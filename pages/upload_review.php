<?php
/**
 * Zurie Upload Foto Pelajar - Admin Review
 * Fasa 5: breadcrumb, tab status, semakan satu-satu, bulk approve dan bulk sync MIS.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/auth_guard.php';
require_once dirname(__DIR__) . '/lib/photo_repair.php';
require_once dirname(__DIR__) . '/lib/mis_sftp.php';
require_once dirname(__DIR__) . '/lib/pg_live_lookup.php';
require_once dirname(__DIR__) . '/lib/matric_card_preview.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

date_default_timezone_set('Asia/Kuala_Lumpur');

$reviewFilesDir = dirname(__DIR__) . '/upload/files';
$reviewPhotoDirs = [
    'original' => $reviewFilesDir . DIRECTORY_SEPARATOR . 'original',
    'repaired' => $reviewFilesDir . DIRECTORY_SEPARATOR . 'repaired',
];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function pdo_zurie_review(): PDO
{
    $configFile = dirname(__DIR__) . '/config/vault_config.php';
    $config = is_file($configFile) ? require $configFile : [];

    $dsn = $config['dsn'] ?? 'mysql:host=localhost;dbname=zurie_noc;charset=utf8mb4';
    $username = $config['username'] ?? 'root';
    $password = $config['password'] ?? '';

    return new PDO((string)$dsn, (string)$username, (string)$password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}


function review_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch();
}

function review_pick_phone_column(PDO $pdo): ?string
{
    foreach (['nohp', 'telefon', 'tel', 'notel', 'no_tel', 'hp', 'phone'] as $column) {
        if (review_column_exists($pdo, 'senarai', $column)) {
            return $column;
        }
    }
    return null;
}

function ensure_review_audit_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_photo_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        matrik VARCHAR(30) NOT NULL UNIQUE,
        nama VARCHAR(255) NULL,
        photo_exists TINYINT(1) NOT NULL DEFAULT 0,
        photo_url VARCHAR(500) NULL,
        http_code INT NULL,
        error_message TEXT NULL,
        checked_at DATETIME NULL,
        quality_status VARCHAR(30) NULL,
        quality_reason VARCHAR(255) NULL,
        quality_checked_at DATETIME NULL,
        quality_checked_by VARCHAR(100) NULL,
        whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0,
        whatsapp_sent_at DATETIME NULL,
        whatsapp_type VARCHAR(30) NULL,
        whatsapp_note VARCHAR(255) NULL,
        whatsapp_source VARCHAR(50) NULL,
        whatsapp_sent_by VARCHAR(100) NULL,
        INDEX idx_quality_status (quality_status),
        INDEX idx_whatsapp_sent (whatsapp_sent)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        "ALTER TABLE student_photo_audit ADD COLUMN nama VARCHAR(255) NULL",
        "ALTER TABLE student_photo_audit ADD COLUMN photo_exists TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE student_photo_audit ADD COLUMN photo_url VARCHAR(500) NULL",
        "ALTER TABLE student_photo_audit ADD COLUMN http_code INT NULL",
        "ALTER TABLE student_photo_audit ADD COLUMN error_message TEXT NULL",
        "ALTER TABLE student_photo_audit ADD COLUMN checked_at DATETIME NULL",
        "ALTER TABLE student_photo_audit ADD COLUMN quality_status VARCHAR(30) NULL",
        "ALTER TABLE student_photo_audit ADD COLUMN quality_reason VARCHAR(255) NULL",
        "ALTER TABLE student_photo_audit ADD COLUMN quality_checked_at DATETIME NULL",
        "ALTER TABLE student_photo_audit ADD COLUMN quality_checked_by VARCHAR(100) NULL",
        "ALTER TABLE student_photo_audit ADD COLUMN whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE student_photo_audit ADD COLUMN whatsapp_sent_at DATETIME NULL",
        "ALTER TABLE student_photo_audit ADD COLUMN whatsapp_type VARCHAR(30) NULL AFTER whatsapp_sent_at",
        "ALTER TABLE student_photo_audit ADD COLUMN whatsapp_note VARCHAR(255) NULL AFTER whatsapp_type",
        "ALTER TABLE student_photo_audit ADD COLUMN whatsapp_source VARCHAR(50) NULL AFTER whatsapp_note",
        "ALTER TABLE student_photo_audit ADD COLUMN whatsapp_sent_by VARCHAR(100) NULL AFTER whatsapp_source",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Abaikan jika column sudah wujud.
        }
    }
}

function review_clean_matrik(string $value): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');
}

function review_wa_phone(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '60')) {
        return $digits;
    }
    if (str_starts_with($digits, '0')) {
        return '6' . $digits;
    }
    if (str_starts_with($digits, '1')) {
        return '60' . $digits;
    }
    return $digits;
}

function review_reject_message(array $row): string
{
    $matrik = (string)($row['matrik'] ?? '');
    $nama = trim((string)($row['nama'] ?? ''));
    $reason = trim((string)($row['reject_reason'] ?? ''));
    $note = trim((string)($row['reject_note'] ?? ''));
    if ($reason === '') {
        $reason = 'Tidak memenuhi spesifikasi gambar yang ditetapkan.';
    }

    $message = "Assalamualaikum dan Salam Sejahtera.\n\n";
    $message .= "MAKLUMAN SEMAKAN GAMBAR PROFIL PENDAFTARAN ({$matrik})\n\n";
    if ($nama !== '') {
        $message .= "Nama: {$nama}\n";
    }
    $message .= "Gambar profil yang dimuat naik telah disemak dan tidak dapat diluluskan atas sebab berikut:\n";
    $message .= $reason . "\n";
    if ($note !== '') {
        $message .= "\nCatatan penyemak:\n{$note}\n";
    }
    $message .= "\nSila muat naik gambar baharu yang jelas, menunjukkan wajah dengan baik dan berlatar belakang putih melalui pautan berikut:\n";
    $message .= "http://www.kmp.matrik.edu.my/zurie/upload/\n\n";
    $message .= "Contoh gambar:\nhttp://mis.kmp.matrik.edu.my/online/contoh_gambar.php\n\n";
    $message .= "Abaikan mesej ini sekiranya gambar baharu telah dimuat naik.\n\n";
    $message .= "Terima kasih.\n\nUnit Teknologi Maklumat\nKolej Matrikulasi Perlis";
    return $message;
}

function review_reject_wa_url(array $row): string
{
    $phone = review_wa_phone((string)($row['nohp'] ?? ''));
    if ($phone === '') {
        return '';
    }
    return 'https://wa.me/' . rawurlencode($phone) . '?text=' . rawurlencode(review_reject_message($row));
}

function review_mark_whatsapp(
    PDO $pdo,
    string $matrik,
    string $nama,
    bool $sent = true,
    string $type = 'tolak',
    string $note = '',
    string $source = 'upload_review',
    string $actor = 'admin'
): void {
    $matrik = review_clean_matrik($matrik);
    if ($matrik === '') {
        throw new RuntimeException('No matrik tidak sah untuk rekod WhatsApp.');
    }

    if ($sent) {
        $stmt = $pdo->prepare("INSERT INTO student_photo_audit
            (matrik, nama, whatsapp_sent, whatsapp_sent_at, whatsapp_type, whatsapp_note, whatsapp_source, whatsapp_sent_by)
            VALUES (?, ?, 1, NOW(), ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE nama=VALUES(nama), whatsapp_sent=1, whatsapp_sent_at=NOW(),
                whatsapp_type=VALUES(whatsapp_type), whatsapp_note=VALUES(whatsapp_note),
                whatsapp_source=VALUES(whatsapp_source), whatsapp_sent_by=VALUES(whatsapp_sent_by)");
        $stmt->execute([
            $matrik,
            $nama,
            $type !== '' ? $type : null,
            $note !== '' ? $note : null,
            $source,
            $actor,
        ]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO student_photo_audit
            (matrik, nama, whatsapp_sent, whatsapp_sent_at, whatsapp_type, whatsapp_note, whatsapp_source, whatsapp_sent_by)
            VALUES (?, ?, 0, NULL, ?, ?, ?, NULL)
            ON DUPLICATE KEY UPDATE nama=VALUES(nama), whatsapp_sent=0, whatsapp_sent_at=NULL,
                whatsapp_type=VALUES(whatsapp_type), whatsapp_note=VALUES(whatsapp_note),
                whatsapp_source=VALUES(whatsapp_source), whatsapp_sent_by=NULL");
        $stmt->execute([
            $matrik,
            $nama,
            $type !== '' ? $type : null,
            $note !== '' ? $note : null,
            $source,
        ]);
    }
}

function review_sync_audit_quality(
    PDO $pdo,
    array $record,
    string $qualityStatus,
    string $qualityReason,
    string $actor,
    bool $resetWhatsapp = false,
    string $whatsappType = '',
    string $whatsappNote = ''
): void {
    $matrik = review_clean_matrik((string)($record['matrik'] ?? ''));
    if ($matrik === '') {
        return;
    }
    $nama = (string)($record['nama'] ?? '');

    if ($resetWhatsapp) {
        $stmt = $pdo->prepare("INSERT INTO student_photo_audit
            (matrik, nama, quality_status, quality_reason, quality_checked_at, quality_checked_by,
             whatsapp_sent, whatsapp_sent_at, whatsapp_type, whatsapp_note, whatsapp_source, whatsapp_sent_by)
            VALUES (?, ?, ?, ?, NOW(), ?, 0, NULL, ?, ?, 'upload_review', NULL)
            ON DUPLICATE KEY UPDATE nama=VALUES(nama), quality_status=VALUES(quality_status),
                quality_reason=VALUES(quality_reason), quality_checked_at=NOW(), quality_checked_by=VALUES(quality_checked_by),
                whatsapp_sent=0, whatsapp_sent_at=NULL, whatsapp_type=VALUES(whatsapp_type),
                whatsapp_note=VALUES(whatsapp_note), whatsapp_source='upload_review', whatsapp_sent_by=NULL");
        $stmt->execute([
            $matrik,
            $nama,
            $qualityStatus,
            $qualityReason,
            $actor,
            $whatsappType !== '' ? $whatsappType : null,
            $whatsappNote !== '' ? $whatsappNote : null,
        ]);
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO student_photo_audit
        (matrik, nama, quality_status, quality_reason, quality_checked_at, quality_checked_by)
        VALUES (?, ?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE nama=VALUES(nama), quality_status=VALUES(quality_status),
            quality_reason=VALUES(quality_reason), quality_checked_at=NOW(), quality_checked_by=VALUES(quality_checked_by)");
    $stmt->execute([$matrik, $nama, $qualityStatus, $qualityReason, $actor]);
}

function review_mark_audit_photo_synced(PDO $pdo, array $record): void
{
    $matrik = review_clean_matrik((string)($record['matrik'] ?? ''));
    if ($matrik === '') {
        return;
    }
    $nama = (string)($record['nama'] ?? '');
    $photoUrl = '/zurie/student_photo.php?nomatrik=' . rawurlencode($matrik);
    $stmt = $pdo->prepare("INSERT INTO student_photo_audit
        (matrik, nama, photo_exists, photo_url, http_code, error_message, checked_at)
        VALUES (?, ?, 1, ?, 200, NULL, NOW())
        ON DUPLICATE KEY UPDATE nama=VALUES(nama), photo_exists=1, photo_url=VALUES(photo_url),
            http_code=200, error_message=NULL, checked_at=NOW()");
    $stmt->execute([$matrik, $nama, $photoUrl]);
}

function review_phone_select(?string $phoneColumn, string $alias = 's'): string
{
    if ($phoneColumn === null) {
        return ", '' AS nohp";
    }
    $safe = str_replace('`', '', $phoneColumn);
    return ", {$alias}.`{$safe}` AS nohp";
}

function review_fetch_record_with_phone(PDO $pdo, int $id, ?string $phoneColumn): ?array
{
    $phoneSelect = review_phone_select($phoneColumn);
    $stmt = $pdo->prepare("SELECT u.* {$phoneSelect}, a.whatsapp_sent, a.whatsapp_sent_at, a.whatsapp_type, a.whatsapp_note, a.whatsapp_source, a.whatsapp_sent_by
        FROM student_photo_uploads u
        LEFT JOIN senarai s ON UPPER(s.matrik)=UPPER(u.matrik)
        LEFT JOIN student_photo_audit a ON UPPER(a.matrik)=UPPER(u.matrik)
        WHERE u.id=? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function review_manual_upload_error(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fail terlalu besar.',
        UPLOAD_ERR_PARTIAL => 'Upload gambar tidak lengkap.',
        UPLOAD_ERR_NO_FILE => 'Sila pilih gambar hasil edit.',
        default => 'Upload gambar gagal. Kod: ' . $code,
    };
}

function review_store_manual_source(array $file, string $manualDir, string $matrikSafe): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(review_manual_upload_error($error));
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Fail upload manual tidak sah.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size < 500 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Saiz gambar hasil edit mesti antara 500 bait hingga 5 MB.');
    }

    $info = @getimagesize($tmp);
    $mime = (string)($info['mime'] ?? '');
    if (!$info || !in_array($mime, ['image/jpeg', 'image/png'], true)) {
        throw new RuntimeException('Hanya fail JPG, JPEG atau PNG dibenarkan untuk repair manual.');
    }

    if (!is_dir($manualDir) && !@mkdir($manualDir, 0755, true) && !is_dir($manualDir)) {
        throw new RuntimeException('Folder upload manual tidak boleh dicipta.');
    }

    $extension = $mime === 'image/png' ? 'png' : 'jpg';
    $filename = $matrikSafe . '_' . date('Ymd_His') . '.' . $extension;
    $path = rtrim($manualDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmp, $path)) {
        throw new RuntimeException('Gagal menyimpan gambar hasil edit. Semak permission folder upload/files/manual.');
    }
    @chmod($path, 0644);

    return [
        'filename' => $filename,
        'relative' => 'manual/' . $filename,
        'path' => $path,
        'original_name' => basename((string)($file['name'] ?? $filename)),
        'mime' => $mime,
        'size' => (int)filesize($path),
    ];
}

function ensure_review_columns(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_photo_uploads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        matrik VARCHAR(30) NOT NULL UNIQUE,
        nokp VARCHAR(30) NOT NULL,
        nama VARCHAR(255) NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NULL,
        file_size INT NULL,
        status VARCHAR(30) DEFAULT 'baru',
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        "ALTER TABLE student_photo_uploads ADD COLUMN reviewed_at DATETIME NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN reviewed_by VARCHAR(100) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN reject_reason TEXT NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN reject_note TEXT NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN original_file VARCHAR(255) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN repaired_file VARCHAR(255) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN repair_status VARCHAR(30) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN repair_message TEXT NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN repaired_at DATETIME NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN sync_status VARCHAR(30) NULL DEFAULT 'belum'",
        "ALTER TABLE student_photo_uploads ADD COLUMN sync_message TEXT NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN synced_at DATETIME NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN synced_by VARCHAR(100) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN sync_remote_file VARCHAR(255) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN sync_attempts INT NOT NULL DEFAULT 0",
        "ALTER TABLE student_photo_uploads ADD COLUMN registration_status VARCHAR(30) NULL DEFAULT 'aktif'",
        "ALTER TABLE student_photo_uploads ADD COLUMN registration_checked_at DATETIME NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN registration_message TEXT NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN identity_source VARCHAR(30) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN admin_source_file VARCHAR(255) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN admin_source_name VARCHAR(255) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN admin_edited_at DATETIME NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN admin_edited_by VARCHAR(100) NULL",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Abaikan jika column sudah wujud.
        }
    }
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['upload_review_csrf'])) {
        $_SESSION['upload_review_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['upload_review_csrf'];
}

function require_csrf(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    $real = (string)($_SESSION['upload_review_csrf'] ?? '');
    if ($sent === '' || $real === '' || !hash_equals($real, $sent)) {
        throw new RuntimeException('Token keselamatan tidak sah. Sila refresh halaman dan cuba semula.');
    }
}

function actor_name(): string
{
    return (string)($_SESSION['portal_username'] ?? $_SESSION['portal_display_name'] ?? 'admin');
}

function mask_nokp_review(string $nokp): string
{
    $digits = preg_replace('/\D+/', '', $nokp) ?? '';
    if (strlen($digits) < 10) {
        return '********';
    }
    return substr($digits, 0, 6) . '-**-' . substr($digits, -4);
}


function review_registration_is_active(array $record): bool
{
    $registration = strtolower(trim((string)($record['registration_status'] ?? '')));
    if ($registration === 'aktif' || $registration === 'active') {
        return true;
    }
    if ($registration === 'pending') {
        return false;
    }
    return strtolower((string)($record['status'] ?? '')) !== 'pending_registration';
}

/**
 * Jika batch extract sudah dijalankan, promosikan rekod pending tanpa perlu query PostgreSQL.
 */
function review_promote_from_mysql(PDO $pdo): int
{
    $sql = "UPDATE student_photo_uploads u
        INNER JOIN senarai s
            ON UPPER(s.matrik)=UPPER(u.matrik)
           AND REPLACE(REPLACE(s.nokp,'-',''),' ','')=REPLACE(REPLACE(u.nokp,'-',''),' ','')
        SET u.registration_status='aktif',
            u.registration_checked_at=NOW(),
            u.registration_message='Aktif disahkan melalui table senarai MySQL.',
            u.status=CASE WHEN u.status='pending_registration' THEN 'baru' ELSE u.status END
        WHERE COALESCE(u.registration_status,'pending')<>'aktif'
          AND UPPER(COALESCE(s.status,'AKTIF'))='AKTIF'";
    return (int)$pdo->exec($sql);
}

function review_recheck_registration(PDO $pdo, array $record): array
{
    $matrik = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($record['matrik'] ?? '')) ?? '');
    $nokp = preg_replace('/\D+/', '', (string)($record['nokp'] ?? '')) ?? '';

    if ($matrik === '' || strlen($nokp) < 10) {
        throw new RuntimeException('Maklumat matrik atau No KP tidak lengkap untuk semakan pendaftaran.');
    }

    $config = zurie_pg_live_config();
    if (!zurie_pg_live_connection_ready($config)) {
        throw new RuntimeException('Konfigurasi sambungan PostgreSQL langsung belum lengkap.');
    }

    $result = zurie_pg_live_check_registration($pdo, $matrik, $nokp, $config);
    $status = (string)($result['status'] ?? 'not_found');

    if ($status === 'active') {
        $stmt = $pdo->prepare("UPDATE student_photo_uploads
            SET registration_status='aktif', registration_checked_at=NOW(),
                registration_message=?, identity_source='postgres_active',
                status=CASE WHEN status='pending_registration' THEN 'baru' ELSE status END
            WHERE id=?");
        $stmt->execute([(string)($result['message'] ?? 'Pendaftaran aktif disahkan.'), (int)$record['id']]);
    } elseif ($status === 'pending_registration') {
        $stmt = $pdo->prepare("UPDATE student_photo_uploads
            SET registration_status='pending', registration_checked_at=NOW(),
                registration_message=?, identity_source='postgres_raw',
                status=CASE WHEN status IN ('lulus','tolak') THEN status ELSE 'pending_registration' END
            WHERE id=?");
        $stmt->execute([(string)($result['message'] ?? 'Pendaftaran fizikal belum aktif.'), (int)$record['id']]);
    } else {
        $stmt = $pdo->prepare("UPDATE student_photo_uploads
            SET registration_checked_at=NOW(), registration_message=?
            WHERE id=?");
        $stmt->execute([(string)($result['message'] ?? 'Rekod tidak ditemui.'), (int)$record['id']]);
    }

    $stmt = $pdo->prepare("SELECT * FROM student_photo_uploads WHERE id=? LIMIT 1");
    $stmt->execute([(int)$record['id']]);
    $updated = $stmt->fetch();
    return is_array($updated) ? $updated : $record;
}

function review_ensure_active_registration(PDO $pdo, array $record): array
{
    if (review_registration_is_active($record)) {
        return $record;
    }

    $updated = review_recheck_registration($pdo, $record);
    if (!review_registration_is_active($updated)) {
        throw new RuntimeException('Pendaftaran fizikal pelajar belum aktif. Gambar boleh disimpan tetapi belum boleh diluluskan atau disync ke MIS.');
    }
    return $updated;
}

function photo_file_url(string $relativePath): string
{
    $parts = array_filter(
        explode('/', str_replace('\\', '/', ltrim($relativePath, '/'))),
        static fn($part) => $part !== ''
    );
    return '/zurie/upload/files/' . implode('/', array_map('rawurlencode', $parts));
}

function photo_file_path(string $filesDir, string $relativePath): string
{
    $clean = str_replace(['../', '..\\'], '', str_replace('\\', '/', ltrim($relativePath, '/')));
    return rtrim($filesDir, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
}

function repaired_path_for_record(array $record, string $filesDir): string
{
    $relative = trim((string)($record['repaired_file'] ?? ''));
    if ($relative === '') {
        $relative = basename((string)($record['filename'] ?? ''));
    }
    if ($relative === '') {
        throw new RuntimeException('Fail repaired belum tersedia. Tekan Repair Semula dahulu.');
    }

    $path = photo_file_path($filesDir, $relative);
    if (!is_file($path)) {
        throw new RuntimeException('Fail repaired tidak ditemui. Tekan Repair Semula dahulu.');
    }
    return $path;
}

function sync_record_to_mis(PDO $pdo, array $record, string $filesDir, string $actor): array
{
    $record = review_ensure_active_registration($pdo, $record);
    $id = (int)($record['id'] ?? 0);
    $matrik = (string)($record['matrik'] ?? '');
    $localFile = repaired_path_for_record($record, $filesDir);
    $result = zurie_mis_sftp_upload_photo($localFile, $matrik);

    $stmt = $pdo->prepare("UPDATE student_photo_uploads
        SET sync_status=?, sync_message=?, synced_at=?, synced_by=?, sync_remote_file=?,
            sync_attempts=COALESCE(sync_attempts,0)+1
        WHERE id=?");
    $stmt->execute([
        $result['ok'] ? 'berjaya' : 'gagal',
        (string)($result['message'] ?? ''),
        $result['ok'] ? date('Y-m-d H:i:s') : null,
        $actor,
        (string)($result['remote_file'] ?? ''),
        $id,
    ]);

    if (!empty($result['ok'])) {
        review_mark_audit_photo_synced($pdo, $record);
    }

    return $result;
}

function review_clean_ids(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function review_fetch_records(PDO $pdo, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM student_photo_uploads WHERE id IN ($placeholders) ORDER BY id");
    $stmt->execute($ids);
    return $stmt->fetchAll();
}


function review_append_pg_intake(array $rows): array
{
    if ($rows === []) {
        return $rows;
    }

    foreach ($rows as &$row) {
        $row['stud_intake'] = '-';
    }
    unset($row);

    try {
        $config = zurie_pg_live_config();
        if (!zurie_pg_live_active_lookup_ready($config)) {
            return $rows;
        }

        $pgsql = zurie_pg_live_connect($config);
        $keys = [];
        $params = [
            ':semester' => (int)($config['semester'] ?? 0),
            ':active_status' => (string)($config['active_status'] ?? '01'),
        ];

        foreach ($rows as $row) {
            $matrik = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($row['matrik'] ?? '')) ?? '');
            $nokp = preg_replace('/\D+/', '', (string)($row['nokp'] ?? '')) ?? '';
            if ($matrik === '' && $nokp === '') {
                continue;
            }
            $key = $matrik . '|' . $nokp;
            $keys[$key] = [$matrik, $nokp];
        }

        if ($keys === []) {
            return $rows;
        }

        $pairs = [];
        $i = 0;
        foreach ($keys as [$matrik, $nokp]) {
            $pairs[] = "(:m{$i}, :k{$i})";
            $params[":m{$i}"] = $matrik;
            $params[":k{$i}"] = $nokp;
            $i++;
        }

        $sql = "SELECT UPPER(COALESCE(personal.nomatrik,'')) AS matrik,
                       REPLACE(REPLACE(COALESCE(personal.nokp,''), '-', ''), ' ', '') AS nokp,
                       pelajar.stud_intake AS stud_intake
                FROM public.personal
                INNER JOIN public.pelajar
                    ON REPLACE(REPLACE(COALESCE(pelajar.stud_kp, ''), '-', ''), ' ', '')
                     = REPLACE(REPLACE(COALESCE(personal.nokp, ''), '-', ''), ' ', '')
                WHERE pelajar.stud_semester = :semester
                  AND pelajar.stud_status = :active_status
                  AND (UPPER(COALESCE(personal.nomatrik,'')), REPLACE(REPLACE(COALESCE(personal.nokp,''), '-', ''), ' ', ''))
                      IN (" . implode(',', $pairs) . ")";

        $stmt = $pgsql->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $intakeByKey = [];
        foreach ($stmt->fetchAll() as $pgRow) {
            $matrik = strtoupper((string)($pgRow['matrik'] ?? ''));
            $nokp = (string)($pgRow['nokp'] ?? '');
            $intakeByKey[$matrik . '|' . $nokp] = trim((string)($pgRow['stud_intake'] ?? '')) ?: '-';
        }

        foreach ($rows as &$row) {
            $matrik = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($row['matrik'] ?? '')) ?? '');
            $nokp = preg_replace('/\D+/', '', (string)($row['nokp'] ?? '')) ?? '';
            $row['stud_intake'] = $intakeByKey[$matrik . '|' . $nokp] ?? '-';
        }
        unset($row);
    } catch (Throwable $e) {
        // Paparan Upload Review tidak diganggu jika PG tidak boleh dicapai.
    }

    return $rows;
}

function review_approve_records(PDO $pdo, array $records, string $filesDir, string $actor): array
{
    $ok = 0;
    $failed = [];
    $stmt = $pdo->prepare("UPDATE student_photo_uploads
        SET status='lulus', reviewed_at=NOW(), reviewed_by=?, reject_reason=NULL, reject_note=NULL,
            sync_status=CASE WHEN sync_status='berjaya' THEN sync_status ELSE 'belum' END
        WHERE id=?");

    foreach ($records as $record) {
        try {
            $record = review_ensure_active_registration($pdo, $record);
            repaired_path_for_record($record, $filesDir);
            $stmt->execute([$actor, (int)$record['id']]);
            review_sync_audit_quality(
                $pdo,
                $record,
                'baik',
                'Gambar upload telah diluluskan melalui Semakan Upload.',
                $actor
            );
            $ok++;
        } catch (Throwable $e) {
            $failed[] = (string)($record['matrik'] ?? ('ID ' . $record['id'])) . ': ' . $e->getMessage();
        }
    }
    return ['ok' => $ok, 'failed' => $failed];
}

function review_sync_records(PDO $pdo, array $records, string $filesDir, string $actor): array
{
    @set_time_limit(0);
    $ok = 0;
    $failed = [];

    foreach ($records as $record) {
        $matrik = (string)($record['matrik'] ?? ('ID ' . $record['id']));
        if (strtolower((string)($record['status'] ?? '')) !== 'lulus') {
            $failed[] = $matrik . ': belum diluluskan';
            continue;
        }
        if (strtolower((string)($record['sync_status'] ?? '')) === 'berjaya') {
            continue;
        }
        try {
            $result = sync_record_to_mis($pdo, $record, $filesDir, $actor);
            if (!empty($result['ok'])) {
                $ok++;
            } else {
                $failed[] = $matrik . ': ' . (string)($result['message'] ?? 'Sync gagal');
            }
        } catch (Throwable $e) {
            $failed[] = $matrik . ': ' . $e->getMessage();
        }
    }

    return ['ok' => $ok, 'failed' => $failed];
}

function review_failure_summary(array $failed, int $max = 5): string
{
    if ($failed === []) {
        return '';
    }
    $shown = array_slice($failed, 0, $max);
    $text = implode(' | ', $shown);
    if (count($failed) > $max) {
        $text .= ' | +' . (count($failed) - $max) . ' lagi';
    }
    return $text;
}

$messages = [];
$errors = [];
$status = strtolower((string)($_GET['status'] ?? 'baru'));
$allowedStatuses = ['semua', 'pending_registration', 'baru', 'lulus', 'belum_sync', 'sync_berjaya', 'sync_gagal', 'tolak'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'baru';
}
$search = trim((string)($_GET['q'] ?? ''));

try {
    $reviewPhotoDirs = zurie_photo_ensure_directories($reviewFilesDir);
    $reviewPhotoDirs['manual'] = $reviewFilesDir . DIRECTORY_SEPARATOR . 'manual';
    if (!is_dir($reviewPhotoDirs['manual']) && !@mkdir($reviewPhotoDirs['manual'], 0755, true) && !is_dir($reviewPhotoDirs['manual'])) {
        throw new RuntimeException('Folder upload/files/manual tidak boleh disediakan.');
    }
    $pdo = pdo_zurie_review();
    ensure_review_columns($pdo);
    ensure_review_audit_table($pdo);
    $phoneColumn = review_pick_phone_column($pdo);
    $promotedFromMysql = review_promote_from_mysql($pdo);
    if ($promotedFromMysql > 0) {
        $messages[] = $promotedFromMysql . ' rekod pendaftaran telah dipromosikan kepada aktif melalui sync MySQL.';
    }
    $token = csrf_token();
    $sftpConfigStatus = zurie_mis_sftp_config_status();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $action = (string)($_POST['action'] ?? '');
        $actor = actor_name();

        if (in_array($action, ['mark_whatsapp', 'unmark_whatsapp'], true)) {
            $matrikWa = review_clean_matrik((string)($_POST['matrik'] ?? ''));
            $namaWa = trim((string)($_POST['nama'] ?? ''));
            if ($matrikWa === '') {
                throw new RuntimeException('No matrik tidak sah untuk kemas kini WhatsApp.');
            }
            $waType = trim(substr((string)($_POST['wa_type'] ?? 'tolak'), 0, 30));
            $waNote = trim(substr((string)($_POST['wa_note'] ?? ''), 0, 255));
            review_mark_whatsapp(
                $pdo,
                $matrikWa,
                $namaWa,
                $action === 'mark_whatsapp',
                $waType,
                $waNote,
                'upload_review',
                $actor
            );
            if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch') {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'ok' => true,
                    'matrik' => $matrikWa,
                    'sent' => $action === 'mark_whatsapp',
                    'type' => $waType,
                    'time' => $action === 'mark_whatsapp' ? date('Y-m-d H:i:s') : null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $messages[] = $action === 'mark_whatsapp' ? 'Status WhatsApp ditanda sebagai telah dihantar.' : 'Tanda WhatsApp telah dibatalkan.';
        } elseif ($action === 'recheck_all_pending') {
            @set_time_limit(0);
            $stmt = $pdo->query("SELECT * FROM student_photo_uploads
                WHERE status='pending_registration'
                   OR COALESCE(registration_status,'pending')='pending'
                ORDER BY id
                LIMIT 50");
            $records = $stmt->fetchAll();
            $activated = 0;
            $stillPending = 0;
            $failed = [];
            foreach ($records as $record) {
                try {
                    $updated = review_recheck_registration($pdo, $record);
                    if (review_registration_is_active($updated)) {
                        $activated++;
                    } else {
                        $stillPending++;
                    }
                } catch (Throwable $e) {
                    $failed[] = (string)($record['matrik'] ?? ('ID ' . $record['id'])) . ': ' . $e->getMessage();
                }
            }
            $messages[] = $activated . ' rekod kini aktif; ' . $stillPending . ' masih menunggu pendaftaran.';
            if ($failed) {
                $errors[] = 'Sebahagian semakan gagal: ' . review_failure_summary($failed);
            }
            $status = 'pending_registration';
        } elseif (in_array($action, ['bulk_approve', 'bulk_sync'], true)) {
            $ids = review_clean_ids($_POST['ids'] ?? []);
            if ($ids === []) {
                throw new RuntimeException('Pilih sekurang-kurangnya satu rekod.');
            }
            if (count($ids) > 100) {
                throw new RuntimeException('Maksimum 100 rekod boleh dipilih pada satu masa.');
            }
            $records = review_fetch_records($pdo, $ids);

            if ($action === 'bulk_approve') {
                $result = review_approve_records($pdo, $records, $reviewFilesDir, $actor);
                $messages[] = $result['ok'] . ' gambar berjaya diluluskan.';
                if ($result['failed']) {
                    $errors[] = 'Sebahagian gagal: ' . review_failure_summary($result['failed']);
                }
                $status = 'baru';
            } else {
                if (!$sftpConfigStatus['ready']) {
                    throw new RuntimeException('Konfigurasi SFTP belum lengkap.');
                }
                $result = review_sync_records($pdo, $records, $reviewFilesDir, $actor);
                $messages[] = $result['ok'] . ' gambar berjaya disync ke MIS.';
                if ($result['failed']) {
                    $errors[] = 'Sebahagian gagal: ' . review_failure_summary($result['failed']);
                }
                $status = 'belum_sync';
            }
        } elseif ($action === 'sync_all_pending') {
            if (!$sftpConfigStatus['ready']) {
                throw new RuntimeException('Konfigurasi SFTP belum lengkap.');
            }
            $stmt = $pdo->query("SELECT * FROM student_photo_uploads
                WHERE status='lulus'
                  AND COALESCE(registration_status,'aktif')='aktif'
                  AND COALESCE(sync_status,'belum') <> 'berjaya'
                  AND COALESCE(repair_status,'')='siap'
                ORDER BY id
                LIMIT 50");
            $records = $stmt->fetchAll();
            if (!$records) {
                $messages[] = 'Tiada rekod diluluskan yang masih belum sync.';
            } else {
                $result = review_sync_records($pdo, $records, $reviewFilesDir, $actor);
                $messages[] = $result['ok'] . ' gambar berjaya disync dalam pusingan ini.';
                if ($result['failed']) {
                    $errors[] = 'Sebahagian gagal: ' . review_failure_summary($result['failed']);
                }
                $remaining = (int)$pdo->query("SELECT COUNT(*) FROM student_photo_uploads
                    WHERE status='lulus'
                      AND COALESCE(registration_status,'aktif')='aktif'
                      AND COALESCE(sync_status,'belum') <> 'berjaya'
                      AND COALESCE(repair_status,'')='siap'")->fetchColumn();
                if ($remaining > 0) {
                    $messages[] = 'Masih berbaki ' . $remaining . ' rekod. Tekan Sync Semua sekali lagi untuk pusingan seterusnya.';
                }
            }
            $status = 'belum_sync';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ID rekod tidak sah.');
            }

            if ($action === 'recheck_registration') {
                $stmt = $pdo->prepare("SELECT * FROM student_photo_uploads WHERE id=? LIMIT 1");
                $stmt->execute([$id]);
                $record = $stmt->fetch();
                if (!$record) {
                    throw new RuntimeException('Rekod gambar tidak ditemui.');
                }
                $updated = review_recheck_registration($pdo, $record);
                if (review_registration_is_active($updated)) {
                    $messages[] = 'Pendaftaran fizikal kini aktif. Gambar sudah boleh disemak dan diluluskan.';
                    $status = 'baru';
                } else {
                    $messages[] = 'Pendaftaran fizikal masih belum aktif.';
                    $status = 'pending_registration';
                }
            } elseif ($action === 'repair') {
                $stmt = $pdo->prepare("SELECT * FROM student_photo_uploads WHERE id=? LIMIT 1");
                $stmt->execute([$id]);
                $record = $stmt->fetch();
                if (!$record) {
                    throw new RuntimeException('Rekod gambar tidak ditemui.');
                }

                $matrikSafe = preg_replace('/[^A-Za-z0-9]/', '', (string)$record['matrik']) ?: ('photo_' . $id);
                $savedName = $matrikSafe . '.jpg';
                $originalRel = trim((string)($record['original_file'] ?? ''));
                $legacyRel = basename((string)($record['filename'] ?? $savedName));
                $sourcePath = $originalRel !== ''
                    ? photo_file_path($reviewFilesDir, $originalRel)
                    : photo_file_path($reviewFilesDir, $legacyRel);

                if (!is_file($sourcePath)) {
                    throw new RuntimeException('Fail asal tidak ditemui untuk proses repair semula.');
                }

                $targetOriginal = $reviewPhotoDirs['original'] . DIRECTORY_SEPARATOR . $savedName;
                if ($originalRel === '' || realpath($sourcePath) !== realpath($targetOriginal)) {
                    if (!@copy($sourcePath, $targetOriginal)) {
                        throw new RuntimeException('Gagal menyediakan salinan gambar asal.');
                    }
                    @chmod($targetOriginal, 0644);
                    $sourcePath = $targetOriginal;
                }

                $repairedPath = $reviewPhotoDirs['repaired'] . DIRECTORY_SEPARATOR . $savedName;
                $legacyPath = $reviewFilesDir . DIRECTORY_SEPARATOR . $savedName;
                $repair = zurie_photo_repair($sourcePath, $repairedPath);
                if (!$repair['ok']) {
                    throw new RuntimeException('Repair gagal: ' . $repair['message']);
                }
                if (!zurie_photo_publish_legacy($repairedPath, $legacyPath)) {
                    throw new RuntimeException('Repair siap tetapi gagal mengemaskini fail utama.');
                }

                $stmt = $pdo->prepare("UPDATE student_photo_uploads
                    SET filename=?, original_file=?, repaired_file=?, file_size=?, repair_status='siap',
                        repair_message=?, repaired_at=NOW(),
                        status=CASE WHEN COALESCE(registration_status,'aktif')='aktif' THEN 'baru' ELSE 'pending_registration' END,
                        reviewed_at=NULL,
                        reviewed_by=NULL, reject_reason=NULL, reject_note=NULL, sync_status='belum', sync_message=NULL,
                        synced_at=NULL, synced_by=NULL, sync_remote_file=NULL, sync_attempts=0
                    WHERE id=?");
                $stmt->execute([
                    $savedName,
                    'original/' . $savedName,
                    'repaired/' . $savedName,
                    (int)($repair['size'] ?? filesize($repairedPath)),
                    (string)$repair['message'],
                    $id,
                ]);
                review_sync_audit_quality(
                    $pdo,
                    $record,
                    'repair',
                    'Auto repair 413x531 dijalankan semula melalui Semakan Upload.',
                    $actor
                );
                $messages[] = 'Gambar berjaya dibaiki semula kepada 413x531.';
                $status = 'baru';
            } elseif ($action === 'manual_upload') {
                $record = review_fetch_record_with_phone($pdo, $id, $phoneColumn);
                if (!$record) {
                    throw new RuntimeException('Rekod gambar tidak ditemui.');
                }

                $matrikSafe = preg_replace('/[^A-Za-z0-9]/', '', (string)$record['matrik']) ?: ('photo_' . $id);
                $manual = review_store_manual_source(
                    is_array($_FILES['manual_photo'] ?? null) ? $_FILES['manual_photo'] : [],
                    $reviewPhotoDirs['manual'],
                    $matrikSafe
                );

                $savedName = $matrikSafe . '.jpg';
                $repairedPath = $reviewPhotoDirs['repaired'] . DIRECTORY_SEPARATOR . $savedName;
                $legacyPath = $reviewFilesDir . DIRECTORY_SEPARATOR . $savedName;
                $repair = zurie_photo_repair((string)$manual['path'], $repairedPath);
                if (!$repair['ok']) {
                    @unlink((string)$manual['path']);
                    throw new RuntimeException('Gambar hasil edit diterima tetapi proses 413x531 gagal: ' . $repair['message']);
                }
                if (!zurie_photo_publish_legacy($repairedPath, $legacyPath)) {
                    @unlink((string)$manual['path']);
                    throw new RuntimeException('Repair manual siap tetapi gagal mengemaskini fail utama.');
                }

                $oldManualRel = trim((string)($record['admin_source_file'] ?? ''));
                $repairMessage = 'Repair manual admin daripada ' . (string)$manual['original_name'] . '. ' . (string)$repair['message'];
                $stmt = $pdo->prepare("UPDATE student_photo_uploads
                    SET filename=?, repaired_file=?, file_size=?, repair_status='siap', repair_message=?, repaired_at=NOW(),
                        admin_source_file=?, admin_source_name=?, admin_edited_at=NOW(), admin_edited_by=?,
                        status=CASE WHEN COALESCE(registration_status,'aktif')='aktif' THEN 'baru' ELSE 'pending_registration' END,
                        reviewed_at=NULL, reviewed_by=NULL, reject_reason=NULL, reject_note=NULL,
                        sync_status='belum', sync_message=NULL, synced_at=NULL, synced_by=NULL, sync_remote_file=NULL, sync_attempts=0
                    WHERE id=?");
                $stmt->execute([
                    $savedName,
                    'repaired/' . $savedName,
                    (int)($repair['size'] ?? filesize($repairedPath)),
                    $repairMessage,
                    (string)$manual['relative'],
                    (string)$manual['original_name'],
                    $actor,
                    $id,
                ]);

                if ($oldManualRel !== '' && $oldManualRel !== (string)$manual['relative'] && str_starts_with(str_replace('\\', '/', $oldManualRel), 'manual/')) {
                    $oldManualPath = photo_file_path($reviewFilesDir, $oldManualRel);
                    if (is_file($oldManualPath)) {
                        @unlink($oldManualPath);
                    }
                }

                $record['admin_source_file'] = (string)$manual['relative'];
                review_sync_audit_quality(
                    $pdo,
                    $record,
                    'repair',
                    'Gambar dibaiki manual oleh admin dan menunggu kelulusan.',
                    $actor
                );
                $messages[] = 'Gambar hasil edit admin berjaya dimuat naik, diputihkan jika PNG lutsinar, dan dijana semula kepada 413x531.';
                $status = 'baru';
            } elseif ($action === 'approve' || $action === 'approve_sync' || $action === 'sync') {
                $stmt = $pdo->prepare("SELECT * FROM student_photo_uploads WHERE id=? LIMIT 1");
                $stmt->execute([$id]);
                $record = $stmt->fetch();
                if (!$record) {
                    throw new RuntimeException('Rekod gambar tidak ditemui.');
                }

                $record = review_ensure_active_registration($pdo, $record);
                repaired_path_for_record($record, $reviewFilesDir);

                if ($action === 'approve' || $action === 'approve_sync') {
                    $stmt = $pdo->prepare("UPDATE student_photo_uploads
                        SET status='lulus', reviewed_at=NOW(), reviewed_by=?, reject_reason=NULL, reject_note=NULL,
                            sync_status=CASE WHEN sync_status='berjaya' THEN sync_status ELSE 'belum' END
                        WHERE id=?");
                    $stmt->execute([$actor, $id]);
                    $record['status'] = 'lulus';
                    review_sync_audit_quality(
                        $pdo,
                        $record,
                        'baik',
                        'Gambar upload telah diluluskan melalui Semakan Upload.',
                        $actor
                    );
                    $messages[] = 'Gambar repaired berjaya diluluskan.';
                }

                if ($action === 'sync' && strtolower((string)($record['status'] ?? '')) !== 'lulus') {
                    throw new RuntimeException('Gambar mesti diluluskan sebelum sync ke MIS.');
                }

                if ($action === 'approve_sync' || $action === 'sync') {
                    if (!$sftpConfigStatus['ready']) {
                        throw new RuntimeException('Konfigurasi SFTP belum lengkap.');
                    }
                    $result = sync_record_to_mis($pdo, $record, $reviewFilesDir, $actor);
                    if ($result['ok']) {
                        $messages[] = (string)$result['message'];
                    } else {
                        $errors[] = 'Gambar diluluskan tetapi sync MIS gagal: ' . (string)$result['message'];
                    }
                }
                $status = $action === 'sync' ? 'belum_sync' : 'baru';
            } elseif ($action === 'reject') {
                $record = review_fetch_record_with_phone($pdo, $id, $phoneColumn);
                if (!$record) {
                    throw new RuntimeException('Rekod gambar tidak ditemui.');
                }

                $reason = trim((string)($_POST['reject_reason'] ?? ''));
                $note = trim((string)($_POST['reject_note'] ?? ''));
                if ($reason === '') {
                    $reason = 'Tidak memenuhi syarat gambar.';
                }
                $stmt = $pdo->prepare("UPDATE student_photo_uploads
                    SET status='tolak', reviewed_at=NOW(), reviewed_by=?, reject_reason=?, reject_note=?
                    WHERE id=?");
                $stmt->execute([$actor, $reason, $note !== '' ? $note : null, $id]);

                $record['reject_reason'] = $reason;
                $record['reject_note'] = $note;
                $waNote = $reason . ($note !== '' ? ' | ' . $note : '');
                review_sync_audit_quality($pdo, $record, 'upload_baru', $reason, $actor, true, 'tolak', $waNote);

                if ((string)($_POST['redirect_wa'] ?? '') === '1') {
                    $waUrl = review_reject_wa_url($record);
                    if ($waUrl === '') {
                        throw new RuntimeException('Gambar telah ditolak tetapi No HP pelajar tiada atau tidak sah.');
                    }
                    review_mark_whatsapp(
                        $pdo,
                        (string)$record['matrik'],
                        (string)$record['nama'],
                        true,
                        'tolak',
                        $waNote,
                        'upload_review',
                        $actor
                    );
                    header('Location: ' . $waUrl, true, 303);
                    exit;
                }

                $messages[] = 'Gambar telah ditolak. Status turut diselaraskan ke Audit Gambar MIS sebagai Upload Baru.';
                $status = 'baru';
            } else {
                throw new RuntimeException('Tindakan tidak dikenali.');
            }
        }
    }

    $stats = [
        'aktif' => 0,
        'sudah_upload' => 0,
        'belum_upload' => 0,
        'pending_registration' => 0,
        'baru' => 0,
        'lulus' => 0,
        'tolak' => 0,
        'sync_belum' => 0,
        'sync_berjaya' => 0,
        'sync_gagal' => 0,
    ];

    try {
        $statsSql = "SELECT
            COUNT(s.id) AS aktif,
            SUM(CASE WHEN u.id IS NOT NULL THEN 1 ELSE 0 END) AS sudah_upload,
            SUM(CASE WHEN u.id IS NULL THEN 1 ELSE 0 END) AS belum_upload,
            SUM(CASE WHEN u.status='pending_registration' OR COALESCE(u.registration_status,'aktif')='pending' THEN 1 ELSE 0 END) AS pending_registration,
            SUM(CASE WHEN u.status='baru' THEN 1 ELSE 0 END) AS baru,
            SUM(CASE WHEN u.status='lulus' THEN 1 ELSE 0 END) AS lulus,
            SUM(CASE WHEN u.status='tolak' THEN 1 ELSE 0 END) AS tolak,
            SUM(CASE WHEN u.status='lulus' AND COALESCE(u.sync_status,'belum')<>'berjaya' THEN 1 ELSE 0 END) AS sync_belum,
            SUM(CASE WHEN u.sync_status='berjaya' THEN 1 ELSE 0 END) AS sync_berjaya,
            SUM(CASE WHEN u.sync_status='gagal' THEN 1 ELSE 0 END) AS sync_gagal
        FROM senarai s
        LEFT JOIN student_photo_uploads u ON UPPER(u.matrik)=UPPER(s.matrik)
        WHERE UPPER(COALESCE(s.status,'AKTIF'))='AKTIF'";
        $stats = array_merge($stats, $pdo->query($statsSql)->fetch() ?: []);
    } catch (Throwable $e) {
        $simple = $pdo->query("SELECT
            COUNT(*) AS sudah_upload,
            SUM(CASE WHEN status='pending_registration' OR COALESCE(registration_status,'aktif')='pending' THEN 1 ELSE 0 END) AS pending_registration,
            SUM(CASE WHEN status='baru' THEN 1 ELSE 0 END) AS baru,
            SUM(CASE WHEN status='lulus' THEN 1 ELSE 0 END) AS lulus,
            SUM(CASE WHEN status='tolak' THEN 1 ELSE 0 END) AS tolak,
            SUM(CASE WHEN status='lulus' AND COALESCE(sync_status,'belum')<>'berjaya' THEN 1 ELSE 0 END) AS sync_belum,
            SUM(CASE WHEN sync_status='berjaya' THEN 1 ELSE 0 END) AS sync_berjaya,
            SUM(CASE WHEN sync_status='gagal' THEN 1 ELSE 0 END) AS sync_gagal
            FROM student_photo_uploads")->fetch() ?: [];
        $stats = array_merge($stats, $simple);
    }

    // Rekod pending mungkin belum berada dalam table senarai, jadi kira terus daripada upload.
    $stats['pending_registration'] = (int)$pdo->query("SELECT COUNT(*) FROM student_photo_uploads WHERE status='pending_registration' OR COALESCE(registration_status,'aktif')='pending'")->fetchColumn();

    $where = [];
    $params = [];
    switch ($status) {
        case 'pending_registration':
            $where[] = "(u.status='pending_registration' OR COALESCE(u.registration_status,'aktif')='pending')";
            break;
        case 'baru':
            $where[] = "u.status='baru'";
            break;
        case 'lulus':
            $where[] = "u.status='lulus'";
            break;
        case 'belum_sync':
            $where[] = "u.status='lulus' AND COALESCE(u.sync_status,'belum')<>'berjaya'";
            break;
        case 'sync_berjaya':
            $where[] = "u.sync_status='berjaya'";
            break;
        case 'sync_gagal':
            $where[] = "u.sync_status='gagal'";
            break;
        case 'tolak':
            $where[] = "u.status='tolak'";
            break;
        case 'semua':
        default:
            break;
    }
    if ($search !== '') {
        $where[] = '(u.matrik LIKE ? OR u.nama LIKE ? OR s.praktikum LIKE ? OR s.jurusan LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $phoneSelect = review_phone_select($phoneColumn);
    $listSql = "SELECT u.*, s.praktikum, s.kuliah, s.jurusan {$phoneSelect},
            a.whatsapp_sent, a.whatsapp_sent_at, a.whatsapp_type, a.whatsapp_note, a.whatsapp_source, a.whatsapp_sent_by
        FROM student_photo_uploads u
        LEFT JOIN senarai s ON UPPER(s.matrik)=UPPER(u.matrik)
        LEFT JOIN student_photo_audit a ON UPPER(a.matrik)=UPPER(u.matrik)
        $whereSql
        ORDER BY u.uploaded_at DESC, u.id DESC
        LIMIT 300";
    $stmt = $pdo->prepare($listSql);
    $stmt->execute($params);
    $rows = review_append_pg_intake($stmt->fetchAll());
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
    $token = csrf_token();
    $rows = [];
    $stats = $stats ?? [];
    $sftpConfigStatus = $sftpConfigStatus ?? zurie_mis_sftp_config_status();
}

function stat_val(array $stats, string $key): int
{
    return (int)($stats[$key] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Semakan Upload Foto | Zurie</title>
<link rel="stylesheet" href="/zurie/assets/css/matric-card-preview.css">
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;margin:0;color:#0f172a}.wrap{max-width:1240px;margin:24px auto;padding:0 16px}.card{background:#fff;border-radius:16px;padding:18px;box-shadow:0 8px 26px rgba(15,23,42,.07);margin-bottom:14px}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.title{font-size:24px;font-weight:800;margin:0 0 5px}.muted{color:#64748b}.breadcrumb{display:flex;gap:7px;align-items:center;flex-wrap:wrap;font-size:13px;margin-bottom:12px}.breadcrumb a{color:#2563eb;text-decoration:none;font-weight:700}.breadcrumb span{color:#64748b}.stats{display:grid;grid-template-columns:repeat(10,1fr);gap:10px}.stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px}.stat b{display:block;font-size:21px;margin-top:3px}.toolbar,.bulkbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.tab,.btn{border:0;border-radius:9px;padding:9px 12px;font-weight:700;text-decoration:none;display:inline-block}.tab{background:#e2e8f0;color:#0f172a;font-size:13px}.tab.active{background:#2563eb;color:#fff}.btn{background:#2563eb;color:#fff;cursor:pointer}.btn:disabled{opacity:.45;cursor:not-allowed}.btn.reject{background:#dc2626}.btn.sync{background:#0f766e}.btn.warn{background:#b45309}.btn.smallbtn{padding:7px 9px;font-size:12px}.btn.ghost{background:#f1f5f9;color:#0f172a}.alert{padding:12px 14px;border-radius:11px;margin-bottom:12px}.err{background:#fee2e2;color:#991b1b}.ok{background:#dcfce7;color:#166534}input[type=text],select{padding:9px;border:1px solid #cbd5e1;border-radius:9px}.table-wrap{overflow:auto;padding:0}table{width:100%;border-collapse:collapse;min-width:1080px}th,td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}th{font-size:12px;text-transform:uppercase;color:#475569;background:#f8fafc}.thumb{width:70px;height:90px;object-fit:cover;border-radius:9px;border:1px solid #cbd5e1;background:#f8fafc}.thumb-pair{display:flex;gap:7px;align-items:flex-start}.thumb-label{display:block;font-size:10px;color:#64748b;text-align:center;margin-bottom:4px}.repair-ok{background:#dbeafe;color:#1d4ed8}.repair-wait{background:#f1f5f9;color:#475569}.badge{padding:4px 8px;border-radius:999px;font-weight:800;font-size:11px;display:inline-block;margin:1px 0}.baru{background:#fef3c7;color:#92400e}.pending-registration{background:#fff7ed;color:#9a3412}.lulus{background:#dcfce7;color:#166534}.tolak{background:#fee2e2;color:#991b1b}.sync-ok{background:#dcfce7;color:#166534}.sync-fail{background:#fee2e2;color:#991b1b}.sync-wait{background:#e2e8f0;color:#475569}.reject-box{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.reject-box input{min-width:190px}.small{font-size:12px;color:#64748b}.modal{position:fixed;inset:0;background:rgba(15,23,42,.72);display:none;align-items:center;justify-content:center;padding:20px;z-index:20}.modal:target{display:flex}.modal-card{background:#fff;border-radius:18px;padding:18px;max-width:1320px;width:100%;max-height:92vh;overflow:auto}.modal-card img{max-width:100%;border-radius:12px}.compare{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}.compare h3{margin:0 0 8px}.compare>div:not(.card-preview-column)>img{width:100%;max-height:560px;object-fit:contain;background:#f8fafc;border:1px solid #e2e8f0}.close{float:right;text-decoration:none;font-weight:900;color:#0f172a}.select-col{width:36px;text-align:center}.bulk-note{margin-left:auto;font-size:12px;color:#64748b}.target{font-family:monospace;background:#f1f5f9;padding:3px 7px;border-radius:6px}.btn.wa{background:#16a34a}.btn.external{background:#7c3aed}.manual-tools{margin-top:8px;border:1px solid #dbeafe;border-radius:10px;background:#f8fafc;padding:7px}.manual-tools summary{cursor:pointer;font-size:12px;font-weight:800;color:#1e40af}.manual-tools form{margin-top:8px}.manual-tools input[type=file]{display:block;width:100%;max-width:330px;font-size:11px;margin:7px 0;padding:6px;border:1px solid #cbd5e1;border-radius:8px;background:#fff}.manual-links{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.wa-state{display:inline-flex;align-items:center;gap:4px;margin-top:5px;font-size:10px;color:#64748b}.wa-dot{width:16px;height:16px;border:1px solid #94a3b8;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;font-weight:900}.wa-dot.sent{background:#dcfce7;border-color:#16a34a;color:#15803d}.manual-badge{background:#ede9fe;color:#6d28d9}.workflow{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.workflow div{border:1px solid #dbeafe;background:#eff6ff;border-radius:10px;padding:10px;font-size:12px}.workflow b{display:block;margin-bottom:4px;color:#1e40af}@media(max-width:850px){.workflow{grid-template-columns:repeat(2,1fr)}}@media(max-width:1000px){.stats{grid-template-columns:repeat(3,1fr)}}@media(max-width:700px){.compare{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr)}.bulk-note{width:100%;margin-left:0}}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="/zurie/">Dashboard</a><span>›</span>
            <a href="/zurie/pages/photo_audit.php">Audit Gambar MIS</a><span>›</span>
            <strong>Semakan Upload</strong><span>›</span>
            <a href="/zurie/pages/pg_live_lookup_setup.php">Semakan PG</a><span>›</span>
            <a href="/zurie/pages/mis_sftp_setup.php">Tetapan SFTP</a>
        </nav>
        <div class="top">
            <div>
                <h1 class="title">Semakan Upload Foto Pelajar</h1>
                <div class="muted">Semak asal/final, WhatsApp pelajar, repair manual jika perlu, kemudian lulus dan sync ke MIS.</div>
            </div>
            <div class="toolbar">
                <a class="btn ghost" href="/zurie/upload/" target="_blank">Borang Upload</a>
                <a class="btn ghost" href="/zurie/pages/pg_live_lookup_setup.php">Uji PostgreSQL</a>
                <a class="btn ghost" href="/zurie/pages/mis_sftp_setup.php">Uji SFTP</a>
            </div>
        </div>
    </div>

    <div class="card workflow">
        <div><b>1. Muat turun asal</b>Buka alat Repair Manual pada rekod dan simpan gambar asal.</div>
        <div><b>2. Buang background</b>Guna remove.bg, Photoshop atau editor lain. Simpan sebagai JPG/PNG.</div>
        <div><b>3. Upload hasil edit</b>Sistem akan letak latar putih untuk PNG lutsinar dan jana final 413x531.</div>
        <div><b>4. Lulus & sync</b>Preview kad, kemudian Lulus + Sync. Status turut dikongsi dengan Audit Gambar MIS.</div>
    </div>

    <?php foreach ($messages as $message): ?><div class="alert ok"><?= h($message) ?></div><?php endforeach; ?>
    <?php foreach ($errors as $error): ?><div class="alert err"><?= h($error) ?></div><?php endforeach; ?>
    <?php if (!$sftpConfigStatus['ready']): ?>
        <div class="alert err">Sync MIS belum aktif: <?= h(implode(', ', $sftpConfigStatus['missing'])) ?>. <a href="/zurie/pages/mis_sftp_setup.php">Semak tetapan SFTP</a>.</div>
    <?php endif; ?>

    <div class="card stats">
        <div class="stat"><span>Pelajar Aktif</span><b><?= stat_val($stats,'aktif') ?></b></div>
        <div class="stat"><span>Sudah Upload</span><b><?= stat_val($stats,'sudah_upload') ?></b></div>
        <div class="stat"><span>Belum Upload</span><b><?= stat_val($stats,'belum_upload') ?></b></div>
        <div class="stat"><span>Menunggu Daftar</span><b><?= stat_val($stats,'pending_registration') ?></b></div>
        <div class="stat"><span>Menunggu Semakan</span><b><?= stat_val($stats,'baru') ?></b></div>
        <div class="stat"><span>Lulus</span><b><?= stat_val($stats,'lulus') ?></b></div>
        <div class="stat"><span>Belum Sync</span><b><?= stat_val($stats,'sync_belum') ?></b></div>
        <div class="stat"><span>Sync Berjaya</span><b><?= stat_val($stats,'sync_berjaya') ?></b></div>
        <div class="stat"><span>Sync Gagal</span><b><?= stat_val($stats,'sync_gagal') ?></b></div>
        <div class="stat"><span>Ditolak</span><b><?= stat_val($stats,'tolak') ?></b></div>
    </div>

    <div class="card">
        <div class="toolbar">
            <?php foreach ([
                'pending_registration'=>'Menunggu Pendaftaran',
                'baru'=>'Menunggu Semakan',
                'belum_sync'=>'Lulus Belum Sync',
                'sync_berjaya'=>'Sync Berjaya',
                'sync_gagal'=>'Sync Gagal',
                'lulus'=>'Semua Lulus',
                'tolak'=>'Ditolak',
                'semua'=>'Semua'
            ] as $key=>$label): ?>
                <a class="tab <?= $status===$key?'active':'' ?>" href="?status=<?= h($key) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
            <form method="get" class="toolbar" style="margin-left:auto">
                <input type="hidden" name="status" value="<?= h($status) ?>">
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Cari matrik/nama/praktikum">
                <button class="btn" type="submit">Cari</button>
            </form>
        </div>
    </div>

    <div class="card">
        <form id="bulkForm" method="post" class="bulkbar">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" id="bulkAction" name="action" value="">
            <button class="btn" type="button" onclick="submitBulk('bulk_approve','Luluskan semua rekod yang dipilih?')">Lulus Terpilih</button>
            <button class="btn sync" type="button" <?= !$sftpConfigStatus['ready'] ? 'disabled' : '' ?> onclick="submitBulk('bulk_sync','Sync rekod terpilih ke MIS? Hanya rekod yang telah lulus akan diproses.')">Sync Terpilih</button>
            <span id="selectedCount" class="small">0 dipilih</span>
        </form>
        <form method="post" class="bulkbar" style="margin-top:9px" onsubmit="return confirm('Semak semula maksimum 50 pelajar yang masih menunggu pendaftaran fizikal?');">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="recheck_all_pending">
            <button class="btn ghost" type="submit">Semak Semua Pendaftaran Menunggu</button>
            <span class="small">Maksimum 50 rekod setiap pusingan.</span>
        </form>
        <form method="post" class="bulkbar" style="margin-top:9px" onsubmit="return confirm('Sync semua gambar yang telah diluluskan tetapi belum berjaya sync? Sistem memproses maksimum 50 rekod setiap pusingan.');">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="sync_all_pending">
            <button class="btn warn" type="submit" <?= !$sftpConfigStatus['ready'] ? 'disabled' : '' ?>>Sync Semua Belum Sync</button>
            <span class="bulk-note">Sasaran: <span class="target"><?= h((string)$sftpConfigStatus['remote_dir']) ?></span> · maksimum 50/pusingan</span>
        </form>
    </div>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th class="select-col"><input type="checkbox" id="selectAll" onclick="toggleAll(this.checked)" title="Pilih semua pada halaman"></th>
                    <th>Asal / Manual / Final</th>
                    <th>Maklumat Pelajar</th>
                    <th>Kelas / Program</th>
                    <th>Upload</th>
                    <th>Status</th>
                    <th>Tindakan</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="muted">Tiada rekod untuk paparan ini.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row):
                $id = (int)$row['id'];
                $file = basename((string)$row['filename']);
                $originalRel = trim((string)($row['original_file'] ?? ''));
                $repairedRel = trim((string)($row['repaired_file'] ?? ''));
                if ($originalRel === '') $originalRel = $file;
                if ($repairedRel === '') $repairedRel = $file;
                $originalUrl = photo_file_url($originalRel);
                $repairedUrl = photo_file_url($repairedRel);
                $adminSourceRel = trim((string)($row['admin_source_file'] ?? ''));
                $adminSourceUrl = $adminSourceRel !== '' ? photo_file_url($adminSourceRel) : '';
                $phoneDisplay = trim((string)($row['nohp'] ?? ''));
                $phoneWa = review_wa_phone($phoneDisplay);
                $rejectWaUrl = strtolower((string)($row['status'] ?? '')) === 'tolak' ? review_reject_wa_url($row) : '';
                $waSent = (int)($row['whatsapp_sent'] ?? 0) === 1;
                $repairStatus = strtolower((string)($row['repair_status'] ?? ''));
                $rowStatus = strtolower((string)($row['status'] ?? 'baru'));
                if (!in_array($rowStatus, ['pending_registration','baru','lulus','tolak'], true)) $rowStatus = 'baru';
                $registrationActive = review_registration_is_active($row);
                $syncStatus = strtolower((string)($row['sync_status'] ?? 'belum'));
                if (!in_array($syncStatus, ['belum','berjaya','gagal'], true)) $syncStatus = 'belum';
                $syncClass = $syncStatus === 'berjaya' ? 'sync-ok' : ($syncStatus === 'gagal' ? 'sync-fail' : 'sync-wait');
            ?>
                <tr>
                    <td class="select-col"><input class="row-check" type="checkbox" name="ids[]" value="<?= $id ?>" form="bulkForm" onchange="updateSelectedCount()" <?= !$registrationActive ? 'disabled title="Pendaftaran belum aktif"' : '' ?>></td>
                    <td>
                        <div class="thumb-pair">
                            <div><span class="thumb-label">Asal</span><a href="#preview<?= $id ?>"><img class="thumb" src="<?= h($originalUrl) ?>" alt="Original <?= h((string)$row['matrik']) ?>"></a></div>
                            <?php if ($adminSourceUrl !== ''): ?>
                                <div><span class="thumb-label">Edit Admin</span><a href="#preview<?= $id ?>"><img class="thumb" src="<?= h($adminSourceUrl) ?>" alt="Manual <?= h((string)$row['matrik']) ?>"></a></div>
                            <?php endif; ?>
                            <div><span class="thumb-label">Final 413x531</span><a href="#preview<?= $id ?>"><img class="thumb" src="<?= h($repairedUrl) ?>" alt="Repaired <?= h((string)$row['matrik']) ?>"></a></div>
                        </div>
                    </td>
                    <td>
                        <b><?= h((string)$row['matrik']) ?></b><br>
                        <?= h((string)$row['nama']) ?><br>
                        <span class="small">No KP: <?= h(mask_nokp_review((string)$row['nokp'])) ?></span><br>
                        <span class="small">No HP: <?= $phoneDisplay !== '' ? h($phoneDisplay) : 'TIADA' ?></span>
                    </td>
                    <td>
                        Praktikum: <b><?= h((string)($row['praktikum'] ?? '-')) ?></b><br>
                        Kuliah: <?= h((string)($row['kuliah'] ?? '-')) ?><br>
                        Jurusan: <?= h((string)($row['jurusan'] ?? '-')) ?><br>
                        Pengambilan: <b><?= h((string)($row['stud_intake'] ?? '-')) ?></b>
                    </td>
                    <td>
                        <?= h((string)$row['uploaded_at']) ?><br>
                        <span class="small"><?= h((string)($row['original_filename'] ?? '')) ?></span><br>
                        <span class="small"><?= number_format(((int)($row['file_size'] ?? 0))/1024, 1) ?> KB</span>
                    </td>
                    <td>
                        <?php
                        $statusLabel = match ($rowStatus) {
                            'pending_registration' => 'MENUNGGU DAFTAR',
                            'baru' => 'MENUNGGU SEMAKAN',
                            default => strtoupper($rowStatus),
                        };
                        $statusClass = $rowStatus === 'pending_registration' ? 'pending-registration' : $rowStatus;
                        ?>
                        <span class="badge <?= h($statusClass) ?>"><?= h($statusLabel) ?></span><br>
                        <span class="badge <?= $registrationActive ? 'lulus' : 'pending-registration' ?>">
                            <?= $registrationActive ? 'PENDAFTARAN AKTIF' : 'PENDAFTARAN BELUM AKTIF' ?>
                        </span><br>
                        <?php if (!empty($row['registration_checked_at'])): ?>
                            <span class="small">Semak daftar: <?= h((string)$row['registration_checked_at']) ?></span><br>
                        <?php endif; ?>
                        <?php if (!$registrationActive && !empty($row['registration_message'])): ?>
                            <span class="small"><?= h((string)$row['registration_message']) ?></span><br>
                        <?php endif; ?>
                        <span class="badge <?= $repairStatus === 'siap' ? 'repair-ok' : 'repair-wait' ?>"><?= $repairStatus === 'siap' ? 'REPAIRED' : 'BELUM REPAIR' ?></span><br>
                        <?php if ($adminSourceRel !== ''): ?>
                            <span class="badge manual-badge">EDIT MANUAL ADMIN</span><br>
                            <span class="small"><?= h((string)($row['admin_edited_at'] ?? '')) ?> · <?= h((string)($row['admin_edited_by'] ?? '-')) ?></span><br>
                        <?php endif; ?>
                        <span class="badge <?= h($syncClass) ?>">MIS: <?= h(strtoupper($syncStatus)) ?></span><br>
                        <span class="wa-state"><span id="waDot_<?= h((string)$row['matrik']) ?>" class="wa-dot <?= $waSent ? 'sent' : '' ?>"><?= $waSent ? '✓' : '' ?></span><span id="waState_<?= h((string)$row['matrik']) ?>"><?= $waSent ? ('WA ' . h((string)($row['whatsapp_type'] ?? '')) . ': ' . h((string)($row['whatsapp_sent_at'] ?? '-'))) : 'Belum WhatsApp' ?></span></span><br>
                        <?php if (!empty($row['whatsapp_source'])): ?><span class="small">Sumber WA: <?= h((string)$row['whatsapp_source']) ?></span><br><?php endif; ?>
                        <?php if (!empty($row['synced_at'])): ?><span class="small">Sync: <?= h((string)$row['synced_at']) ?><br>Oleh: <?= h((string)($row['synced_by'] ?? '-')) ?></span><br><?php endif; ?>
                        <?php if ($syncStatus === 'gagal' && !empty($row['sync_message'])): ?><span class="small">Ralat: <?= h((string)$row['sync_message']) ?></span><br><?php endif; ?>
                        <?php if (!empty($row['reviewed_at'])): ?><span class="small">Semak: <?= h((string)$row['reviewed_at']) ?><br>Oleh: <?= h((string)($row['reviewed_by'] ?? '-')) ?></span><?php endif; ?>
                        <?php if ($rowStatus === 'tolak' && !empty($row['reject_reason'])): ?><br><span class="small">Sebab: <?= h((string)$row['reject_reason']) ?></span><?php endif; ?>
                        <?php if ($rowStatus === 'tolak' && !empty($row['reject_note'])): ?><br><span class="small">Catatan: <?= h((string)$row['reject_note']) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <a class="btn ghost smallbtn" href="#preview<?= $id ?>">Preview</a>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= h($token) ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="action" value="repair">
                            <button class="btn ghost smallbtn" type="submit">Repair Semula</button>
                        </form>
                        <?php if (!$registrationActive): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= h($token) ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="action" value="recheck_registration">
                            <button class="btn warn smallbtn" type="submit">Semak Pendaftaran</button>
                        </form>
                        <?php elseif ($rowStatus !== 'lulus'): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= h($token) ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="action" value="approve_sync">
                            <button class="btn sync smallbtn" type="submit" <?= !$sftpConfigStatus['ready'] ? 'disabled' : '' ?>>Lulus + Sync</button>
                        </form>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= h($token) ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="action" value="approve">
                            <button class="btn smallbtn" type="submit">Lulus</button>
                        </form>
                        <?php elseif ($syncStatus !== 'berjaya'): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= h($token) ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="action" value="sync">
                            <button class="btn sync smallbtn" type="submit" <?= !$sftpConfigStatus['ready'] ? 'disabled' : '' ?>><?= $syncStatus === 'gagal' ? 'Cuba Sync' : 'Sync MIS' ?></button>
                        </form>
                        <?php endif; ?>

                        <?php if ($rowStatus === 'tolak' && $rejectWaUrl !== ''): ?>
                            <a class="btn wa smallbtn" target="_blank" rel="noopener" href="<?= h($rejectWaUrl) ?>" onclick='markReviewWaSent(<?= json_encode((string)$row['matrik'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= json_encode((string)$row['nama'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>, "tolak", <?= json_encode(trim((string)($row['reject_reason'] ?? '')) . (trim((string)($row['reject_note'] ?? '')) !== '' ? ' | ' . trim((string)$row['reject_note']) : ''), JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' >WhatsApp Tolak</a>
                        <?php elseif ($rowStatus === 'tolak'): ?>
                            <span class="badge tolak">TIADA NO HP</span>
                        <?php endif; ?>

                        <details class="manual-tools">
                            <summary>Repair Manual / Remove Background</summary>
                            <div class="manual-links">
                                <a class="btn ghost smallbtn" href="<?= h($originalUrl) ?>" target="_blank" download>Muat Turun Asal</a>
                                <a class="btn external smallbtn" href="https://www.remove.bg/upload" target="_blank" rel="noopener noreferrer">Buka remove.bg</a>
                            </div>
                            <div class="small" style="margin-top:6px">Selepas edit, upload JPG/PNG. PNG lutsinar akan diletakkan atas latar putih dan dijana ke 413x531.</div>
                            <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Gantikan final gambar ini dengan hasil edit admin? Status lulus dan sync akan direset.');">
                                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="manual_upload">
                                <input type="file" name="manual_photo" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required>
                                <button class="btn external smallbtn" type="submit">Upload Hasil Edit</button>
                            </form>
                        </details>

                        <form method="post" class="reject-box">
                            <input type="hidden" name="csrf" value="<?= h($token) ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="text" name="reject_reason" value="<?= h((string)($row['reject_reason'] ?? '')) ?>" placeholder="Sebab tolak" list="rejectReasons">
                            <input type="text" name="reject_note" value="<?= h((string)($row['reject_note'] ?? '')) ?>" placeholder="Catatan admin (pilihan)">
                            <button class="btn reject smallbtn" type="submit">Tolak</button>
                            <?php if ($phoneWa !== ''): ?>
                                <button class="btn wa smallbtn" type="submit" name="redirect_wa" value="1" formtarget="_blank" onclick="setTimeout(function(){location.reload();},1200)">Tolak + WhatsApp</button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php foreach ($rows as $row):
        $id = (int)$row['id'];
        $file = basename((string)$row['filename']);
        $originalRel = trim((string)($row['original_file'] ?? '')) ?: $file;
        $repairedRel = trim((string)($row['repaired_file'] ?? '')) ?: $file;
        $originalUrl = photo_file_url($originalRel);
        $repairedUrl = photo_file_url($repairedRel);
        $adminSourceRel = trim((string)($row['admin_source_file'] ?? ''));
        $adminSourceUrl = $adminSourceRel !== '' ? photo_file_url($adminSourceRel) : '';
    ?>
    <div class="modal" id="preview<?= $id ?>">
        <div class="modal-card">
            <a class="close" href="#">×</a>
            <h2><?= h((string)$row['matrik']) ?> - <?= h((string)$row['nama']) ?></h2>
            <div class="compare">
                <div><h3>Original Pelajar</h3><img src="<?= h($originalUrl) ?>" alt="Original"><p class="small"><a href="<?= h($originalUrl) ?>" target="_blank" download>Muat turun asal</a></p></div>
                <?php if ($adminSourceUrl !== ''): ?>
                    <div><h3>Hasil Edit Admin</h3><img src="<?= h($adminSourceUrl) ?>" alt="Manual"><p class="small"><?= h((string)($row['admin_source_name'] ?? '')) ?> · <?= h((string)($row['admin_edited_by'] ?? '-')) ?></p></div>
                <?php endif; ?>
                <div><h3>Final 413x531</h3><img src="<?= h($repairedUrl) ?>" alt="Repaired"><p class="small"><?= h((string)($row['repair_message'] ?? 'Belum diproses.')) ?></p></div>
                <div class="card-preview-column">
                    <h3>Preview Mock Kad Matrik</h3>
                    <?= zurie_matric_card_preview_html([
                        'nama' => (string)$row['nama'],
                        'nokp' => (string)$row['nokp'],
                        'matrik' => (string)$row['matrik'],
                        'sesi' => '2026/2027',
                    ], $repairedUrl, ['compact' => true]) ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<datalist id="rejectReasons">
    <option value="Background bukan putih">
    <option value="Gambar kabur">
    <option value="Bukan gambar passport">
    <option value="Lebih daripada seorang">
    <option value="Gambar tidak jelas">
    <option value="Saiz wajah terlalu kecil / Ruang kosong terlalu banyak">
</datalist>
<script>
const reviewCsrf = <?= json_encode($token, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
function markReviewWaSent(matrik,nama,waType,waNote){
    const dot=document.getElementById('waDot_'+matrik);
    const state=document.getElementById('waState_'+matrik);
    if(dot){dot.classList.add('sent');dot.textContent='✓';}
    if(state){state.textContent='WA '+(waType||'')+': baru dibuka';}
    const fd=new FormData();
    fd.append('csrf',reviewCsrf);
    fd.append('action','mark_whatsapp');
    fd.append('matrik',matrik);
    fd.append('nama',nama||'');
    fd.append('wa_type',waType||'');
    fd.append('wa_note',waNote||'');
    fetch(location.pathname+location.search,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'},credentials:'same-origin'})
        .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
        .then(function(data){if(state&&data.time)state.textContent='WA '+(data.type||waType||'')+': '+data.time;})
        .catch(function(){if(state)state.textContent='WA: refresh untuk sahkan';});
}
function checkedRows(){return Array.from(document.querySelectorAll('.row-check:checked'));}
function updateSelectedCount(){
    document.getElementById('selectedCount').textContent = checkedRows().length + ' dipilih';
    const all = document.querySelectorAll('.row-check');
    const selectAll = document.getElementById('selectAll');
    if(selectAll){selectAll.checked = all.length > 0 && checkedRows().length === all.length;}
}
function toggleAll(checked){
    document.querySelectorAll('.row-check').forEach(function(box){box.checked=checked;});
    updateSelectedCount();
}
function submitBulk(action,message){
    if(checkedRows().length===0){alert('Pilih sekurang-kurangnya satu rekod.');return;}
    if(!confirm(message)){return;}
    document.getElementById('bulkAction').value=action;
    document.getElementById('bulkForm').submit();
}
</script>
</body>
</html>

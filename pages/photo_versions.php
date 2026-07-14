<?php
/**
 * Semakan Versi Gambar MIS
 * - Scan semua fail gambar dalam folder SFTP MIS.
 * - Kumpulkan gambar mengikut no matrik walaupun extension/nama fail berbeza.
 * - Admin pilih satu gambar terbaik.
 * - Gambar pilihan dibaiki/ditukar kepada JPG 413x531 dan dihantar sebagai NOMATRIK.jpg.
 * - Fail lain cuba diarkib dahulu. Jika arkib gagal, fail tidak dipilih dipadam terus dari SFTP.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/auth_guard.php';
require_once dirname(__DIR__) . '/lib/mis_sftp.php';
require_once dirname(__DIR__) . '/lib/photo_repair.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
date_default_timezone_set('Asia/Kuala_Lumpur');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function pv_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function pv_clean_matrik(string $value): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');
}

function pv_extract_matrik(string $filename): string
{
    /*
     * Fail MIS menggunakan No. Matrik di bahagian hadapan. Perbezaan utama
     * hanyalah format fail, contohnya:
     *   MA2614110409.jpg
     *   MA2614110409.jpeg
     *   MA2614110409.png
     *
     * Ambil No. Matrik daripada awal nama fail tanpa bergantung pada panjang
     * tetap. Suffix seperti " (1)", "_1" atau "-copy" masih dibenarkan.
     */
    $decoded = rawurldecode(basename(str_replace('\\', '/', $filename)));
    $stem = strtoupper(trim((string)pathinfo($decoded, PATHINFO_FILENAME)));

    if (preg_match('/^([A-Z]{1,5}[0-9]{6,20})(?=$|[^A-Z0-9])/', $stem, $match)) {
        return pv_clean_matrik((string)$match[1]);
    }

    // Kes biasa: keseluruhan nama sebelum extension ialah No. Matrik.
    if (preg_match('/^[A-Z0-9]{8,30}$/', $stem)) {
        return pv_clean_matrik($stem);
    }

    return '';
}

function pv_manifest_path(): string
{
    return dirname(__DIR__) . '/data/photo_versions_manifest.json';
}

function pv_work_dir(): string
{
    return dirname(__DIR__) . '/data/photo_versions_work';
}


/**
 * Cari salinan asal yang telah dicache oleh preview thumbnail.
 * Cache hanya diterima apabila nama fail, saiz dan tarikh modified sepadan
 * dengan baris manifest yang dipilih.
 */
function pv_cached_preview_source(string $filename, array $manifestRow): ?string
{
    $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true)) {
        return null;
    }

    $version = (string)($manifestRow['size'] ?? 0) . '-' . (string)($manifestRow['modified'] ?? '');
    $cacheKey = hash('sha256', $filename . '|' . $version);
    $cached = dirname(__DIR__) . '/data/photo_version_cache/original/' . $cacheKey . '.' . $extension;

    clearstatcache(true, $cached);
    if (!is_file($cached) || (int)@filesize($cached) < 1 || @getimagesize($cached) === false) {
        return null;
    }

    return $cached;
}

/**
 * Segarkan manifest daripada listing SFTP sebenar tanpa menggagalkan proses.
 */
function pv_refresh_live_manifest_quiet(array $config): array
{
    $scan = zurie_mis_sftp_list_photo_files($config);
    if (!empty($scan['ok']) && is_array($scan['files'] ?? null)) {
        pv_save_manifest($scan['files']);
    }
    return $scan;
}

/**
 * Tentukan kegagalan arkib yang selamat untuk diteruskan.
 *
 * Kes ini berlaku apabila manifest lama masih melaporkan fail mempunyai saiz,
 * tetapi fail sebenar di SFTP sudah kosong atau sudah tiada. Tiada kandungan
 * berguna boleh diarkib, jadi gambar standard baharu dibenarkan menggantikannya.
 */
function pv_archive_failure_is_nonblocking(string $message): bool
{
    $message = strtolower(trim($message));
    foreach ([
        'fail remote kosong',
        'muat turun tidak menghasilkan fail tempatan',
        'arkib gagal: fail kosong',
        'sudah tiada dalam sftp',
        'no such file',
        'no such file or directory',
    ] as $marker) {
        if ($marker !== '' && str_contains($message, $marker)) {
            return true;
        }
    }
    return false;
}

function pv_archive_root(): string
{
    return dirname(__DIR__) . '/archive/sftp_photos';
}

function pv_archive_display_path(string $absolutePath): string
{
    $root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $path = str_replace('\\', '/', $absolutePath);
    if (str_starts_with($path, $root . '/')) {
        return '/zurie/' . ltrim(substr($path, strlen($root)), '/');
    }
    return $path;
}

function pv_safe_archive_filename(string $filename): string
{
    $filename = basename(str_replace('\\', '/', $filename));
    $filename = preg_replace('/[<>:"\\|?*\x00-\x1F]/u', '_', $filename) ?? '';
    $filename = trim($filename, " .\t\n\r\0\x0B");
    return $filename !== '' ? $filename : ('photo_' . date('His') . '.bin');
}

function pv_append_archive_log(array $record): void
{
    $logDir = pv_archive_root() . '/logs';
    if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        return;
    }
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line !== false) {
        @file_put_contents($logDir . '/' . date('Y-m') . '.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Salin satu fail SFTP ke arkib tempatan tanpa memadam fail remote.
 *
 * @return array{ok:bool,message:string,archive_file?:string,archive_absolute?:string,remote_file?:string,bytes?:int,sha256?:string}
 */
function pv_archive_remote_file(string $filename, string $matrik, array $config): array
{
    try {
        $filename = zurie_mis_sftp_validate_remote_filename($filename);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'remote_file' => $filename];
    }

    $matrik = pv_clean_matrik($matrik);
    if ($matrik === '') {
        return ['ok' => false, 'message' => 'No matrik tidak sah.', 'remote_file' => $filename];
    }

    $archiveDir = pv_archive_root() . '/' . date('Y') . '/' . date('m') . '/' . date('d') . '/' . $matrik;
    if (!is_dir($archiveDir) && !@mkdir($archiveDir, 0755, true) && !is_dir($archiveDir)) {
        return ['ok' => false, 'message' => 'Folder arkib Zurie tidak dapat dicipta.', 'remote_file' => $filename];
    }

    $safeName = pv_safe_archive_filename($filename);
    $archiveFile = $archiveDir . '/' . $safeName;
    if (is_file($archiveFile)) {
        $info = pathinfo($safeName);
        $base = (string)($info['filename'] ?? 'photo');
        $ext = isset($info['extension']) ? ('.' . $info['extension']) : '';
        $archiveFile = $archiveDir . '/' . $base . '_' . date('His') . '_' . substr(hash('sha256', $filename . microtime(true)), 0, 8) . $ext;
    }
    $tempFile = $archiveFile . '.part-' . bin2hex(random_bytes(3));

    $download = zurie_mis_sftp_download_photo_file($filename, $tempFile, $config);
    if (empty($download['ok'])) {
        @unlink($tempFile);
        return ['ok' => false, 'message' => 'Arkib gagal: ' . (string)($download['message'] ?? 'Muat turun gagal.'), 'remote_file' => $filename];
    }

    $bytes = (int)@filesize($tempFile);
    if ($bytes < 1) {
        @unlink($tempFile);
        return ['ok' => false, 'message' => 'Arkib gagal: fail kosong.', 'remote_file' => $filename];
    }

    if (!@rename($tempFile, $archiveFile)) {
        if (!@copy($tempFile, $archiveFile)) {
            @unlink($tempFile);
            return ['ok' => false, 'message' => 'Arkib gagal disimpan dalam folder Zurie.', 'remote_file' => $filename];
        }
        @unlink($tempFile);
    }
    @chmod($archiveFile, 0644);

    return [
        'ok' => true,
        'message' => 'Fail berjaya diarkib.',
        'archive_file' => pv_archive_display_path($archiveFile),
        'archive_absolute' => $archiveFile,
        'remote_file' => $filename,
        'bytes' => (int)@filesize($archiveFile),
        'sha256' => (string)@hash_file('sha256', $archiveFile),
    ];
}

/**
 * Arkib satu fail SFTP secara tempatan dahulu, kemudian padam fail remote.
 * Fail remote tidak akan dipadam jika proses arkib gagal.
 *
 * @return array{ok:bool,message:string,archive_file?:string,remote_file?:string,bytes?:int,sha256?:string}
 */
function pv_archive_then_delete(string $filename, string $matrik, array $config): array
{
    $archive = pv_archive_remote_file($filename, $matrik, $config);
    if (empty($archive['ok'])) {
        return $archive;
    }

    $delete = zurie_mis_sftp_delete_photo_file($filename, $config);
    $record = [
        'timestamp' => date('Y-m-d H:i:s'),
        'operation' => 'archive_then_delete',
        'matrik' => pv_clean_matrik($matrik),
        'remote_file' => $filename,
        'archive_file' => (string)($archive['archive_file'] ?? ''),
        'bytes' => (int)($archive['bytes'] ?? 0),
        'sha256' => (string)($archive['sha256'] ?? ''),
        'deleted' => !empty($delete['ok']),
        'delete_message' => (string)($delete['message'] ?? ''),
        'actor' => pv_actor(),
    ];
    pv_append_archive_log($record);

    if (empty($delete['ok'])) {
        return [
            'ok' => false,
            'message' => 'Fail berjaya diarkib tetapi gagal dipadam dari SFTP: ' . (string)($delete['message'] ?? ''),
            'archive_file' => (string)($archive['archive_file'] ?? ''),
            'remote_file' => $filename,
            'bytes' => (int)($archive['bytes'] ?? 0),
            'sha256' => (string)($archive['sha256'] ?? ''),
        ];
    }

    return [
        'ok' => true,
        'message' => 'Fail diarkib dan dipadam dari SFTP.',
        'archive_file' => (string)($archive['archive_file'] ?? ''),
        'remote_file' => $filename,
        'bytes' => (int)($archive['bytes'] ?? 0),
        'sha256' => (string)($archive['sha256'] ?? ''),
    ];
}

/**
 * Bersihkan fail versi yang tidak dipilih.
 * Sistem cuba arkib dahulu. Jika arkib gagal, fail tetap dipadam terus
 * kerana gambar standard baharu sudah berjaya disimpan dan fail ini bukan
 * fail pilihan. Fail standard NOMATRIK.jpg mesti dikecualikan oleh pemanggil.
 *
 * @return array{ok:bool,message:string,archive_file?:string,remote_file?:string,deleted_without_archive?:bool,archive_error?:string}
 */
function pv_cleanup_unselected_file(string $filename, string $matrik, array $config): array
{
    $archived = pv_archive_then_delete($filename, $matrik, $config);
    if (!empty($archived['ok'])) {
        $archived['deleted_without_archive'] = false;
        return $archived;
    }

    $archiveError = (string)($archived['message'] ?? 'Arkib gagal.');
    $delete = zurie_mis_sftp_delete_photo_file($filename, $config);

    pv_append_archive_log([
        'timestamp' => date('Y-m-d H:i:s'),
        'operation' => 'delete_without_archive',
        'matrik' => pv_clean_matrik($matrik),
        'remote_file' => $filename,
        'archive_file' => '',
        'bytes' => 0,
        'sha256' => '',
        'deleted' => !empty($delete['ok']),
        'delete_message' => 'Arkib gagal: ' . $archiveError . ' | Padam terus: ' . (string)($delete['message'] ?? ''),
        'actor' => pv_actor(),
    ]);

    if (empty($delete['ok'])) {
        return [
            'ok' => false,
            'message' => $archiveError . ' Padam terus juga gagal: ' . (string)($delete['message'] ?? ''),
            'remote_file' => $filename,
            'deleted_without_archive' => false,
            'archive_error' => $archiveError,
        ];
    }

    return [
        'ok' => true,
        'message' => 'Arkib gagal, tetapi fail tidak dipilih berjaya dipadam terus dari SFTP.',
        'remote_file' => $filename,
        'deleted_without_archive' => true,
        'archive_error' => $archiveError,
    ];
}

function pv_load_manifest(): array
{
    $path = pv_manifest_path();
    if (!is_file($path)) {
        return ['scanned_at' => '', 'files' => []];
    }
    $decoded = json_decode((string)@file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['files']) || !is_array($decoded['files'])) {
        return ['scanned_at' => '', 'files' => []];
    }
    return $decoded;
}

function pv_save_manifest(array $files): void
{
    $path = pv_manifest_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Folder data manifest tidak dapat disediakan.');
    }
    $payload = [
        'scanned_at' => date('Y-m-d H:i:s'),
        'files' => array_values($files),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Manifest gambar SFTP tidak dapat disimpan.');
    }
}

/**
 * Segarkan manifest selepas upload/padam.
 *
 * Manifest tempatan dikemas kini dahulu supaya fail yang telah berjaya
 * dipadam tidak terus dipaparkan walaupun scan penuh SFTP mengambil masa
 * atau gagal. Selepas itu, scan penuh dibuat sebagai sumber kebenaran.
 *
 * @param array<int,string> $removeFilenames
 * @param array<string,mixed>|null $ensureFile
 * @return array{ok:bool,message:string,files?:array<int,array<string,mixed>>}
 */
function pv_refresh_manifest(array $config, array $removeFilenames = [], ?array $ensureFile = null): array
{
    $manifest = pv_load_manifest();
    $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];

    $removeMap = [];
    foreach ($removeFilenames as $filename) {
        $filename = trim((string)$filename);
        if ($filename !== '') {
            $removeMap[strtolower($filename)] = true;
        }
    }

    $filtered = [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $filename = trim((string)($file['filename'] ?? ''));
        if ($filename !== '' && isset($removeMap[strtolower($filename)])) {
            continue;
        }
        $filtered[] = $file;
    }

    if (is_array($ensureFile) && trim((string)($ensureFile['filename'] ?? '')) !== '') {
        $ensureName = strtolower(trim((string)$ensureFile['filename']));
        $filtered = array_values(array_filter(
            $filtered,
            static fn($file): bool => !is_array($file)
                || strtolower(trim((string)($file['filename'] ?? ''))) !== $ensureName
        ));
        $filtered[] = $ensureFile;
    }

    pv_save_manifest($filtered);

    $scan = zurie_mis_sftp_list_photo_files($config);
    if (!empty($scan['ok']) && is_array($scan['files'] ?? null)) {
        pv_save_manifest($scan['files']);
    }

    return $scan;
}

function pv_pdo(): PDO
{
    $configFile = dirname(__DIR__) . '/config/vault_config.php';
    $config = is_file($configFile) ? require $configFile : [];
    $dsn = (string)($config['dsn'] ?? 'mysql:host=localhost;dbname=zurie_noc;charset=utf8mb4');
    $username = (string)($config['username'] ?? 'root');
    $password = (string)($config['password'] ?? '');

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8,
    ]);
}

function pv_ensure_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_photo_version_reviews (
        matrik VARCHAR(30) NOT NULL PRIMARY KEY,
        nama VARCHAR(255) NULL,
        selected_source VARCHAR(255) NOT NULL,
        standard_file VARCHAR(255) NOT NULL,
        delete_candidates_json LONGTEXT NULL,
        selection_status VARCHAR(30) NOT NULL DEFAULT 'success',
        selection_message TEXT NULL,
        selected_by VARCHAR(100) NULL,
        selected_at DATETIME NULL,
        cleanup_status VARCHAR(30) NOT NULL DEFAULT 'pending',
        cleanup_done_at DATETIME NULL,
        cleanup_done_by VARCHAR(100) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cleanup_status (cleanup_status),
        INDEX idx_selected_at (selected_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pv_csrf(): string
{
    if (empty($_SESSION['photo_versions_csrf'])) {
        $_SESSION['photo_versions_csrf'] = bin2hex(random_bytes(20));
    }
    return (string)$_SESSION['photo_versions_csrf'];
}

function pv_require_csrf(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    $real = (string)($_SESSION['photo_versions_csrf'] ?? '');
    if ($sent === '' || $real === '' || !hash_equals($real, $sent)) {
        throw new RuntimeException('Token keselamatan tidak sah. Refresh halaman dan cuba semula.');
    }
}

function pv_actor(): string
{
    return (string)($_SESSION['portal_display_name'] ?? $_SESSION['portal_username'] ?? 'admin');
}

/** @return array<string,array<int,array<string,mixed>>> */
function pv_group_files(array $files): array
{
    $groups = [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $filename = (string)($file['filename'] ?? '');
        $matrik = pv_extract_matrik($filename);
        if ($matrik === '') {
            continue;
        }
        $file['matrik'] = $matrik;
        $file['selectable'] = in_array(strtolower((string)($file['extension'] ?? '')), ['jpg', 'jpeg', 'png'], true);
        $groups[$matrik][] = $file;
    }
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
    return $groups;
}

/** @return array<string,string> */
function pv_student_names(PDO $pdo, array $matrics): array
{
    $matrics = array_values(array_unique(array_filter(array_map('pv_clean_matrik', $matrics))));
    if (!$matrics) {
        return [];
    }

    $names = [];
    foreach (array_chunk($matrics, 400) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $stmt = $pdo->prepare("SELECT UPPER(TRIM(matrik)) AS matrik, nama FROM senarai WHERE UPPER(TRIM(matrik)) IN ({$placeholders})");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $matrik = pv_clean_matrik((string)($row['matrik'] ?? ''));
                if ($matrik !== '') {
                    $names[$matrik] = trim((string)($row['nama'] ?? ''));
                }
            }
        } catch (Throwable $e) {
            return $names;
        }
    }
    return $names;
}

/** @return array<string,array<string,mixed>> */
function pv_reviews(PDO $pdo): array
{
    $rows = [];
    foreach ($pdo->query('SELECT * FROM student_photo_version_reviews ORDER BY selected_at DESC')->fetchAll() as $row) {
        $matrik = pv_clean_matrik((string)($row['matrik'] ?? ''));
        if ($matrik !== '') {
            $rows[$matrik] = $row;
        }
    }
    return $rows;
}

function pv_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}

function pv_redirect(array $params = []): never
{
    $query = $params ? ('?' . http_build_query($params)) : '';
    header('Location: /zurie/pages/photo_versions.php' . $query);
    exit;
}

$pdo = pv_pdo();
pv_ensure_table($pdo);
$csrf = pv_csrf();
$config = zurie_mis_sftp_config();
$sftpStatus = zurie_mis_sftp_config_status($config);
$message = (string)($_SESSION['photo_versions_message'] ?? '');
$messageType = (string)($_SESSION['photo_versions_message_type'] ?? 'ok');
unset($_SESSION['photo_versions_message'], $_SESSION['photo_versions_message_type']);

if (isset($_GET['export']) && $_GET['export'] === 'delete_csv') {
    $stmt = $pdo->query("SELECT * FROM student_photo_version_reviews WHERE cleanup_status='pending' ORDER BY selected_at DESC, matrik");
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan_calon_padam_sftp_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['No Matrik', 'Nama', 'Gambar Dipilih', 'Nama Standard', 'Fail Calon Padam', 'Remote Path', 'Dipilih Oleh', 'Tarikh Pilih', 'Status Pembersihan']);
    $remoteDir = (string)($sftpStatus['remote_dir'] ?? '');
    foreach ($stmt->fetchAll() as $row) {
        $candidates = json_decode((string)($row['delete_candidates_json'] ?? '[]'), true);
        if (!is_array($candidates) || !$candidates) {
            continue;
        }
        foreach ($candidates as $candidate) {
            $candidate = (string)$candidate;
            fputcsv($out, [
                (string)$row['matrik'],
                (string)($row['nama'] ?? ''),
                (string)$row['selected_source'],
                (string)$row['standard_file'],
                $candidate,
                rtrim($remoteDir, '/') . '/' . $candidate,
                (string)($row['selected_by'] ?? ''),
                (string)($row['selected_at'] ?? ''),
                (string)($row['cleanup_status'] ?? 'pending'),
            ]);
        }
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        pv_require_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'scan') {
            $result = zurie_mis_sftp_list_photo_files($config);
            if (!$result['ok']) {
                throw new RuntimeException((string)$result['message']);
            }
            $files = is_array($result['files'] ?? null) ? $result['files'] : [];
            pv_save_manifest($files);
            $scanGroups = pv_group_files($files);
            $matchedCount = array_sum(array_map('count', $scanGroups));
            $duplicateGroups = count(array_filter($scanGroups, static fn(array $items): bool => count($items) >= 2));
            $unmatchedCount = max(0, count($files) - $matchedCount);
            $_SESSION['photo_versions_message'] = (string)$result['message']
                . ' Dipadankan: ' . $matchedCount . ' fail, kumpulan 2+ versi: ' . $duplicateGroups
                . ', tidak dapat dipadankan: ' . $unmatchedCount . '.';
            $_SESSION['photo_versions_message_type'] = 'ok';
            pv_redirect(['view' => 'duplicate']);
        }

        if ($action === 'select') {
            $matrik = pv_clean_matrik((string)($_POST['matrik'] ?? ''));
            $selected = trim((string)($_POST['selected_file'] ?? ''));
            if ($matrik === '' || $selected === '') {
                throw new RuntimeException('Pilih satu gambar terlebih dahulu.');
            }

            $manifest = pv_load_manifest();
            $groups = pv_group_files((array)($manifest['files'] ?? []));
            if (!isset($groups[$matrik])) {
                throw new RuntimeException('Kumpulan gambar tidak ditemui. Jalankan Scan SFTP semula.');
            }

            $selectedRow = null;
            foreach ($groups[$matrik] as $file) {
                if (hash_equals((string)$file['filename'], $selected)) {
                    $selectedRow = $file;
                    break;
                }
            }
            if (!$selectedRow) {
                throw new RuntimeException('Fail pilihan tidak sepadan dengan manifest semasa.');
            }
            if (empty($selectedRow['selectable'])) {
                throw new RuntimeException('Format pilihan tidak boleh ditukar. Pilih JPG, JPEG atau PNG.');
            }

            $workDir = pv_work_dir();
            if (!is_dir($workDir) && !@mkdir($workDir, 0755, true) && !is_dir($workDir)) {
                throw new RuntimeException('Folder kerja gambar tidak dapat dicipta.');
            }
            $sourceExt = strtolower((string)pathinfo($selected, PATHINFO_EXTENSION));
            $sourceTemp = $workDir . DIRECTORY_SEPARATOR . $matrik . '_' . bin2hex(random_bytes(4)) . '.' . $sourceExt;
            $jpgTemp = $workDir . DIRECTORY_SEPARATOR . $matrik . '_' . bin2hex(random_bytes(4)) . '.jpg';

            $sourceRecoveredFromCache = false;
            $download = zurie_mis_sftp_download_photo_file($selected, $sourceTemp, $config);
            if (empty($download['ok'])) {
                // Manifest boleh menjadi lapuk apabila fail telah dipadam/ditukar nama
                // selepas halaman dibuka. Gunakan cache preview yang sepadan supaya
                // pilihan pengguna tidak hilang jika thumbnail sudah berjaya dipaparkan.
                $cachedSource = pv_cached_preview_source($selected, $selectedRow);
                if ($cachedSource !== null && @copy($cachedSource, $sourceTemp)) {
                    clearstatcache(true, $sourceTemp);
                    $sourceRecoveredFromCache = is_file($sourceTemp) && (int)@filesize($sourceTemp) > 0;
                }

                if (!$sourceRecoveredFromCache) {
                    pv_refresh_live_manifest_quiet($config);
                    @unlink($sourceTemp);

                    if (!empty($download['missing'])) {
                        throw new RuntimeException(
                            'Fail pilihan ' . $selected . ' sudah tiada dalam SFTP. '
                            . 'Senarai telah disegarkan; pilih semula gambar yang masih dipaparkan.'
                        );
                    }

                    throw new RuntimeException('Gagal muat turun gambar pilihan: ' . (string)($download['message'] ?? 'Muat turun gagal.'));
                }
            }

            $repair = zurie_photo_repair($sourceTemp, $jpgTemp, 413, 531, 90);
            @unlink($sourceTemp);
            if (!$repair['ok']) {
                @unlink($jpgTemp);
                throw new RuntimeException('Gagal tukar gambar pilihan kepada JPG: ' . (string)$repair['message']);
            }

            $standardFile = $matrik . '.jpg';
            $existingStandardRow = null;
            foreach ($groups[$matrik] as $existingFile) {
                // Remote MIS ialah Linux dan nama fail sensitif huruf besar/kecil.
                // Hanya NOMATRIK.jpg yang tepat dianggap fail standard untuk overwrite.
                if ((string)($existingFile['filename'] ?? '') === $standardFile) {
                    $existingStandardRow = $existingFile;
                    break;
                }
            }

            // Arkibkan JPG standard lama sebelum ia diganti/ditulis semula.
            if (is_array($existingStandardRow)) {
                $oldStandardSize = max(0, (int)($existingStandardRow['size'] ?? 0));

                if ($oldStandardSize < 1) {
                    // Fail 0 byte tidak mempunyai kandungan untuk diarkib. Benarkan ia
                    // diganti dengan JPG baharu yang sah dan rekodkan sebabnya dalam log.
                    pv_append_archive_log([
                        'timestamp' => date('Y-m-d H:i:s'),
                        'operation' => 'skip_empty_before_replace',
                        'matrik' => $matrik,
                        'remote_file' => $standardFile,
                        'archive_file' => '',
                        'bytes' => 0,
                        'sha256' => '',
                        'deleted' => false,
                        'delete_message' => 'Fail standard lama bersaiz 0 byte; arkib tidak diperlukan sebelum diganti.',
                        'actor' => pv_actor(),
                    ]);
                } else {
                    $oldStandardArchive = pv_archive_remote_file($standardFile, $matrik, $config);
                    if (empty($oldStandardArchive['ok'])) {
                        $archiveMessage = (string)($oldStandardArchive['message'] ?? 'Arkib gagal.');

                        if (!pv_archive_failure_is_nonblocking($archiveMessage)) {
                            @unlink($jpgTemp);
                            throw new RuntimeException(
                                'Gambar standard lama tidak diganti kerana proses arkib gagal: ' . $archiveMessage
                            );
                        }

                        // Manifest boleh mempunyai saiz lama sedangkan fail remote sebenar
                        // kosong atau telah hilang. Dalam keadaan ini tiada kandungan yang
                        // boleh diselamatkan, jadi teruskan overwrite dengan JPG baharu yang
                        // telah pun disahkan sah pada $jpgTemp.
                        pv_append_archive_log([
                            'timestamp' => date('Y-m-d H:i:s'),
                            'operation' => 'replace_without_archive_empty_or_missing',
                            'matrik' => $matrik,
                            'remote_file' => $standardFile,
                            'archive_file' => '',
                            'bytes' => 0,
                            'sha256' => '',
                            'deleted' => false,
                            'delete_message' => 'Arkib tidak dapat dibuat kerana fail standard lama kosong/tiada. Penggantian diteruskan. ' . $archiveMessage,
                            'actor' => pv_actor(),
                        ]);
                    } else {
                        pv_append_archive_log([
                            'timestamp' => date('Y-m-d H:i:s'),
                            'operation' => 'archive_before_replace',
                            'matrik' => $matrik,
                            'remote_file' => $standardFile,
                            'archive_file' => (string)($oldStandardArchive['archive_file'] ?? ''),
                            'bytes' => (int)($oldStandardArchive['bytes'] ?? 0),
                            'sha256' => (string)($oldStandardArchive['sha256'] ?? ''),
                            'deleted' => false,
                            'delete_message' => 'Fail standard lama diarkib sebelum diganti.',
                            'actor' => pv_actor(),
                        ]);
                    }
                }
            }

            $upload = zurie_mis_sftp_upload_photo($jpgTemp, $matrik, $config);
            @unlink($jpgTemp);
            if (!$upload['ok']) {
                throw new RuntimeException('Gagal simpan gambar standard ke MIS: ' . (string)$upload['message']);
            }

            $candidates = [];
            foreach ($groups[$matrik] as $file) {
                $filename = trim((string)($file['filename'] ?? ''));
                // Kecualikan hanya fail standard tepat. Variasi .JPG/.JPEG/.PNG
                // kekal sebagai calon arkib dan padam untuk elak gambar pendua.
                if ($filename !== '' && $filename !== $standardFile) {
                    $candidates[] = $filename;
                }
            }
            $candidates = array_values(array_unique($candidates));

            /*
             * Selepas gambar pilihan berjaya dijadikan NOMATRIK.jpg, semua
             * versi lain terus diarkib dan dipadam. Ini mengelakkan paparan
             * tiga gambar (JPG baharu + JPEG/PNG lama) yang mengelirukan.
             */
            $success = [];
            $failed = [];
            $archives = [];
            $deletedWithoutArchive = [];
            foreach ($candidates as $candidate) {
                $result = pv_cleanup_unselected_file($candidate, $matrik, $config);
                if (!empty($result['ok'])) {
                    $success[] = $candidate;
                    if (!empty($result['archive_file'])) {
                        $archives[] = (string)$result['archive_file'];
                    }
                    if (!empty($result['deleted_without_archive'])) {
                        $deletedWithoutArchive[] = $candidate;
                    }
                } else {
                    $failed[$candidate] = (string)($result['message'] ?? 'Gagal.');
                }
            }

            $ensureStandard = [
                'filename' => $standardFile,
                'size' => max(0, (int)($upload['bytes'] ?? 0)),
                'modified' => date('Y-m-d H:i:s'),
                'extension' => 'jpg',
                'remote_path' => rtrim((string)($sftpStatus['remote_dir'] ?? ''), '/') . '/' . $standardFile,
            ];
            $scan = pv_refresh_manifest($config, $success, $ensureStandard);

            // Jika scan penuh berjaya, sahkan fail yang dikatakan dipadam benar-benar sudah tiada.
            if (!empty($scan['ok']) && is_array($scan['files'] ?? null)) {
                $liveNames = [];
                foreach ($scan['files'] as $liveFile) {
                    if (is_array($liveFile)) {
                        $liveName = trim((string)($liveFile['filename'] ?? ''));
                        if ($liveName !== '') {
                            $liveNames[$liveName] = true;
                        }
                    }
                }
                foreach ($success as $index => $deletedName) {
                    if (isset($liveNames[$deletedName])) {
                        unset($success[$index]);
                        $failed[$deletedName] = 'Fail masih wujud dalam SFTP selepas arahan padam. Cuba Bersihkan semula.';
                    }
                }
                $success = array_values($success);
            }

            $deletedWithoutArchive = array_values(array_intersect($deletedWithoutArchive, $success));
            $remaining = array_values(array_keys($failed));
            $cleanupStatus = $remaining ? 'partial' : 'done';
            $name = trim((string)($_POST['nama'] ?? ''));
            $archivedDeletedCount = max(0, count($success) - count($deletedWithoutArchive));
            $summary = 'Gambar pilihan dijadikan ' . $standardFile . '. '
                . count($success) . ' fail lama berjaya dibersihkan';
            if ($success) {
                $summary .= ' (' . $archivedDeletedCount . ' diarkib dan dipadam';
                if ($deletedWithoutArchive) {
                    $summary .= ', ' . count($deletedWithoutArchive) . ' dipadam tanpa arkib';
                }
                $summary .= ').';
            } else {
                $summary .= '.';
            }
            if ($remaining) {
                $summary .= ' ' . count($remaining) . ' fail masih belum berjaya: ' . implode('; ', array_map(
                    static fn(string $file, string $reason): string => $file . ' — ' . $reason,
                    array_keys($failed),
                    array_values($failed)
                ));
            } else {
                $summary .= ' Kini hanya ' . $standardFile . ' tinggal dalam SFTP.';
            }
            if ($sourceRecoveredFromCache) {
                $summary .= ' Gambar pilihan dipulihkan daripada cache preview kerana fail asal sudah tiada dalam SFTP.';
            }
            if ($archives) {
                $summary .= ' Arkib: ' . dirname($archives[0]) . '/';
            }
            if (empty($scan['ok'])) {
                $summary .= ' Nota: scan penuh SFTP gagal, tetapi manifest tempatan telah dikemas kini.';
            }

            $doneAt = $remaining ? null : date('Y-m-d H:i:s');
            $doneBy = $remaining ? null : pv_actor();
            $stmt = $pdo->prepare("INSERT INTO student_photo_version_reviews
                (matrik,nama,selected_source,standard_file,delete_candidates_json,selection_status,selection_message,selected_by,selected_at,cleanup_status,cleanup_done_at,cleanup_done_by)
                VALUES (?,?,?,?,?,'success',?,?,NOW(),?,?,?)
                ON DUPLICATE KEY UPDATE
                    nama=VALUES(nama), selected_source=VALUES(selected_source), standard_file=VALUES(standard_file),
                    delete_candidates_json=VALUES(delete_candidates_json), selection_status='success',
                    selection_message=VALUES(selection_message), selected_by=VALUES(selected_by), selected_at=NOW(),
                    cleanup_status=VALUES(cleanup_status), cleanup_done_at=VALUES(cleanup_done_at),
                    cleanup_done_by=VALUES(cleanup_done_by)");
            $stmt->execute([
                $matrik,
                $name,
                $selected,
                $standardFile,
                json_encode($remaining, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $summary,
                pv_actor(),
                $cleanupStatus,
                $doneAt,
                $doneBy,
            ]);

            $_SESSION['photo_versions_message'] = $matrik . ': ' . $summary;
            $_SESSION['photo_versions_message_type'] = $remaining ? 'bad' : 'ok';
            pv_redirect(['q' => $matrik, 'view' => $remaining ? 'pending' : 'selected', 'cleaned' => $remaining ? 0 : 1]);
        }

        if ($action === 'archive_delete') {
            $matrik = pv_clean_matrik((string)($_POST['matrik'] ?? ''));
            if ($matrik === '') {
                throw new RuntimeException('No matrik tidak sah.');
            }

            $stmt = $pdo->prepare('SELECT * FROM student_photo_version_reviews WHERE matrik=? LIMIT 1');
            $stmt->execute([$matrik]);
            $review = $stmt->fetch();
            if (!$review) {
                throw new RuntimeException('Rekod pemilihan gambar tidak ditemui.');
            }

            $candidates = json_decode((string)($review['delete_candidates_json'] ?? '[]'), true);
            if (!is_array($candidates) || !$candidates) {
                throw new RuntimeException('Tiada fail calon padam untuk pelajar ini.');
            }

            $standardFile = $matrik . '.jpg';
            $success = [];
            $failed = [];
            $archives = [];
            $deletedWithoutArchive = [];
            foreach (array_values(array_unique(array_map('strval', $candidates))) as $candidate) {
                if ($candidate === '' || $candidate === $standardFile) {
                    continue;
                }
                $result = pv_cleanup_unselected_file($candidate, $matrik, $config);
                if (!empty($result['ok'])) {
                    $success[] = $candidate;
                    if (!empty($result['archive_file'])) {
                        $archives[] = (string)$result['archive_file'];
                    }
                    if (!empty($result['deleted_without_archive'])) {
                        $deletedWithoutArchive[] = $candidate;
                    }
                } else {
                    $failed[$candidate] = (string)($result['message'] ?? 'Gagal.');
                }
            }

            $ensureStandard = [
                'filename' => $standardFile,
                'size' => 0,
                'modified' => date('Y-m-d H:i:s'),
                'extension' => 'jpg',
                'remote_path' => rtrim((string)($sftpStatus['remote_dir'] ?? ''), '/') . '/' . $standardFile,
            ];
            $scan = pv_refresh_manifest($config, $success, $ensureStandard);

            // Sahkan sekali lagi berdasarkan listing SFTP terkini.
            if (!empty($scan['ok']) && is_array($scan['files'] ?? null)) {
                $liveNames = [];
                foreach ($scan['files'] as $liveFile) {
                    if (is_array($liveFile)) {
                        $liveName = trim((string)($liveFile['filename'] ?? ''));
                        if ($liveName !== '') {
                            $liveNames[$liveName] = true;
                        }
                    }
                }
                foreach ($success as $index => $deletedName) {
                    if (isset($liveNames[$deletedName])) {
                        unset($success[$index]);
                        $failed[$deletedName] = 'Fail masih wujud dalam SFTP selepas arahan padam.';
                    }
                }
                $success = array_values($success);
            }

            $deletedWithoutArchive = array_values(array_intersect($deletedWithoutArchive, $success));
            $remaining = array_values(array_keys($failed));
            $cleanupStatus = $remaining ? 'partial' : 'done';
            $archivedDeletedCount = max(0, count($success) - count($deletedWithoutArchive));
            $summary = count($success) . ' fail lama berjaya dibersihkan';
            if ($success) {
                $summary .= ' (' . $archivedDeletedCount . ' diarkib dan dipadam';
                if ($deletedWithoutArchive) {
                    $summary .= ', ' . count($deletedWithoutArchive) . ' dipadam tanpa arkib';
                }
                $summary .= ').';
            } else {
                $summary .= '.';
            }
            if ($remaining) {
                $summary .= ' ' . count($remaining) . ' fail belum berjaya: ' . implode('; ', array_map(
                    static fn(string $file, string $reason): string => $file . ' — ' . $reason,
                    array_keys($failed),
                    array_values($failed)
                ));
            } else {
                $summary .= ' Kini hanya ' . $standardFile . ' tinggal dalam SFTP.';
            }
            if ($archives) {
                $summary .= ' Lokasi arkib: ' . dirname($archives[0]) . '/';
            }
            if (empty($scan['ok'])) {
                $summary .= ' Nota: scan penuh SFTP gagal, tetapi fail yang berjaya dipadam telah dibuang daripada manifest tempatan.';
            }

            $update = $pdo->prepare('UPDATE student_photo_version_reviews
                SET delete_candidates_json=?, cleanup_status=?, cleanup_done_at=?, cleanup_done_by=?, selection_message=?
                WHERE matrik=?');
            $update->execute([
                json_encode($remaining, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $cleanupStatus,
                $remaining ? null : date('Y-m-d H:i:s'),
                $remaining ? null : pv_actor(),
                $summary,
                $matrik,
            ]);

            $_SESSION['photo_versions_message'] = $matrik . ': ' . $summary;
            $_SESSION['photo_versions_message_type'] = $remaining ? 'bad' : 'ok';
            pv_redirect(['q' => $matrik, 'view' => $remaining ? 'pending' : 'selected', 'cleaned' => $remaining ? 0 : 1]);
        }

        throw new RuntimeException('Tindakan tidak dikenali.');
    } catch (Throwable $e) {
        $_SESSION['photo_versions_message'] = $e->getMessage();
        $_SESSION['photo_versions_message_type'] = 'bad';
        pv_redirect();
    }
}

$manifest = pv_load_manifest();
$manifestFiles = (array)($manifest['files'] ?? []);
$groups = pv_group_files($manifestFiles);
$reviews = pv_reviews($pdo);
$names = pv_student_names($pdo, array_keys($groups));

$matchedFileCount = array_sum(array_map('count', $groups));
$unmatchedFiles = [];
$extensionCounts = [];
foreach ($manifestFiles as $manifestFile) {
    if (!is_array($manifestFile)) continue;
    $filename = (string)($manifestFile['filename'] ?? '');
    $ext = strtolower((string)($manifestFile['extension'] ?? pathinfo($filename, PATHINFO_EXTENSION)));
    if ($ext !== '') $extensionCounts[$ext] = ($extensionCounts[$ext] ?? 0) + 1;
    if ($filename !== '' && pv_extract_matrik($filename) === '') {
        $unmatchedFiles[] = $filename;
    }
}
ksort($extensionCounts);

$q = trim((string)($_GET['q'] ?? ''));
$view = (string)($_GET['view'] ?? 'duplicate');
if (!in_array($view, ['duplicate', 'all', 'pending', 'selected'], true)) {
    $view = 'duplicate';
}

$filtered = [];
foreach ($groups as $matrik => $files) {
    $review = $reviews[$matrik] ?? null;
    $name = (string)($names[$matrik] ?? ($review['nama'] ?? ''));
    if ($q !== '' && stripos($matrik . ' ' . $name, $q) === false) {
        continue;
    }
    if ($view === 'duplicate' && count($files) < 2) continue;
    if ($view === 'pending' && !in_array((string)($review['cleanup_status'] ?? ''), ['pending', 'partial'], true)) continue;
    if ($view === 'selected' && !$review) continue;
    $filtered[$matrik] = $files;
}

$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalGroups = count($filtered);
$totalPages = max(1, (int)ceil($totalGroups / $perPage));
$page = min($page, $totalPages);
$pageGroups = array_slice($filtered, ($page - 1) * $perPage, $perPage, true);

$duplicateCount = count(array_filter($groups, static fn(array $items): bool => count($items) >= 2));
$pendingCount = count(array_filter($reviews, static fn(array $row): bool => in_array((string)($row['cleanup_status'] ?? ''), ['pending', 'partial'], true)));
$selectedCount = count($reviews);
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Semakan Versi Gambar MIS | Zurie</title>
<style>
:root{--blue:#2563eb;--green:#16a34a;--yellow:#d97706;--red:#dc2626;--ink:#0f172a;--muted:#64748b;--line:#dbe3ef;--bg:#f3f7fc}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,sans-serif;color:var(--ink)}.wrap{max-width:1450px;margin:24px auto;padding:0 16px 50px}.card{background:#fff;border:1px solid #e5eaf2;border-radius:18px;padding:18px;box-shadow:0 9px 28px rgba(15,23,42,.06);margin-bottom:16px}.head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}.title{font-size:27px;margin:0 0 7px}.muted{color:var(--muted)}.breadcrumb{font-size:13px;display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px}.breadcrumb a{color:var(--blue);text-decoration:none;font-weight:700}.btn{border:0;border-radius:10px;padding:10px 14px;background:var(--blue);color:#fff;font-weight:800;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px}.btn.secondary{background:#e2e8f0;color:var(--ink)}.btn.green{background:var(--green)}.btn.red{background:var(--red)}.btn:disabled{opacity:.55;cursor:not-allowed}.toolbar{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.toolbar input,.toolbar select{border:1px solid #cbd5e1;border-radius:10px;padding:10px;background:#fff;min-width:190px}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.stat{border-radius:15px;padding:15px;border:1px solid var(--line);text-decoration:none;color:inherit}.stat b{font-size:25px;display:block;margin-bottom:5px}.stat.blue{background:#eff6ff}.stat.yellow{background:#fff7ed}.stat.green{background:#f0fdf4}.stat.red{background:#fef2f2}.notice{padding:13px 15px;border-radius:12px;margin-bottom:15px;font-weight:700}.notice.ok{background:#dcfce7;color:#166534}.notice.bad{background:#fee2e2;color:#991b1b}.group{border:2px solid #e2e8f0;border-radius:18px;background:#fff;margin-bottom:18px;overflow:hidden}.group.pending{border-color:#f59e0b}.group.selected{border-color:#22c55e}.group-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;background:#f8fafc;flex-wrap:wrap}.group-head h2{margin:0;font-size:19px}.badge{display:inline-block;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:900}.badge.yellow{background:#ffedd5;color:#9a3412}.badge.green{background:#dcfce7;color:#166534}.badge.red{background:#fee2e2;color:#991b1b}.photos{display:flex;flex-wrap:wrap;align-items:flex-start;gap:12px;padding:16px}.photo{flex:0 1 122px;max-width:132px;min-width:112px}.photo{border:1px solid var(--line);border-radius:14px;padding:9px;background:#fff;position:relative}.photo.selected-source{outline:3px solid #22c55e}.preview-link{display:block;border-radius:10px;overflow:hidden;background:#f8fafc;text-decoration:none}.preview{display:block;width:100%;height:150px;object-fit:contain;background:linear-gradient(45deg,#eef2f7 25%,transparent 25%),linear-gradient(-45deg,#eef2f7 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#eef2f7 75%),linear-gradient(-45deg,transparent 75%,#eef2f7 75%);background-size:20px 20px;background-position:0 0,0 10px,10px -10px,-10px 0}.preview-link:hover .preview{filter:brightness(.96)}.meta{font-size:12px;line-height:1.55;color:#475569;margin-top:9px;word-break:break-word}.filename{font-weight:900;color:#0f172a}.radio{display:flex;align-items:center;gap:7px;margin-top:9px;font-weight:800}.warning{font-size:12px;color:#b45309;background:#fff7ed;padding:7px;border-radius:8px;margin-top:7px}.group-action{padding:0 16px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}.report-box{background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:11px;font-size:13px;line-height:1.55;flex:1;min-width:260px}.pagination{display:flex;gap:7px;flex-wrap:wrap;justify-content:center;margin:20px 0}.pagination a,.pagination span{padding:8px 11px;border-radius:9px;background:#fff;border:1px solid var(--line);text-decoration:none;color:var(--ink)}.pagination .active{background:var(--blue);color:#fff}.empty{text-align:center;padding:45px 15px;color:var(--muted)}.howto{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.step{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:12px}.exts{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.unmatched{max-height:180px;overflow:auto;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:10px;font-size:12px;line-height:1.6}code{background:#f1f5f9;border-radius:5px;padding:2px 5px}@media(max-width:850px){.howto{grid-template-columns:1fr}}@media(max-width:850px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.preview{height:145px}}@media(max-width:520px){.stats{grid-template-columns:1fr}.toolbar input,.toolbar select{width:100%;min-width:0}.btn{justify-content:center}.photo{flex-basis:100%;max-width:none;min-width:0}}
</style>
</head>
<body><div class="wrap">
<div class="card">
<nav class="breadcrumb"><a href="/zurie/">Dashboard</a><span>›</span><a href="/zurie/pages/photo_audit.php">Audit Gambar MIS</a><span>›</span><strong>Versi Gambar SFTP</strong></nav>
<div class="head">
<div><h1 class="title">Semakan Versi Gambar MIS</h1><div class="muted">Pilih gambar terbaik. Sistem convert kepada JPG 413×531 dan simpan sebagai <b>NOMATRIK.jpg</b>. Fail lain cuba diarkib dahulu; jika arkib gagal, fail tidak dipilih akan dipadam terus dari SFTP.</div></div>
<div class="toolbar">
<form method="post" onsubmit="return confirm('Scan semua fail gambar dalam SFTP MIS sekarang? Proses mungkin mengambil sedikit masa.');">
<input type="hidden" name="csrf" value="<?= pv_h($csrf) ?>"><input type="hidden" name="action" value="scan">
<button class="btn" type="submit" <?= !$sftpStatus['ready'] ? 'disabled' : '' ?>>↻ Scan SFTP</button>
</form>
<a class="btn green" href="?export=delete_csv">⬇ Laporan Calon Padam CSV</a>
<a class="btn secondary" href="/zurie/pages/mis_sftp_setup.php">Tetapan SFTP</a>
</div>
</div>
<p class="muted">Status SFTP: <b><?= $sftpStatus['ready'] ? 'SEDIA' : 'BELUM LENGKAP' ?></b> · Scan terakhir: <b><?= pv_h((string)($manifest['scanned_at'] ?: 'Belum pernah')) ?></b></p>
</div>

<?php if ($message !== ''): ?><div class="notice <?= $messageType === 'bad' ? 'bad' : 'ok' ?>"><?= pv_h($message) ?></div><?php endif; ?>

<div class="card">
<div class="howto">
<div class="step"><b>1. Scan SFTP</b><br><span class="muted">Klik butang Scan SFTP untuk membaca semua fail JPG, JPEG dan PNG. Fail dengan No. Matrik sama akan dikumpulkan walaupun extension berbeza.</span></div>
<div class="step"><b>2. Buka 2+ versi</b><br><span class="muted">Klik kad oren. Semua fail bagi No. Matrik sama akan muncul sebelah-menyebelah.</span></div>
<div class="step"><b>3. Pilih gambar</b><br><span class="muted">Tanda satu gambar dan klik Pilih & Bersihkan. Sistem cipta NOMATRIK.jpg, cuba arkib versi lama, kemudian memadam semua fail yang tidak dipilih.</span></div>
</div>
<div class="exts">
<?php foreach ($extensionCounts as $ext => $count): ?><span class="badge yellow"><?= pv_h(strtoupper($ext)) ?>: <?= (int)$count ?></span><?php endforeach; ?>
<span class="badge green">Dipadankan: <?= (int)$matchedFileCount ?></span>
<?php if ($unmatchedFiles): ?><span class="badge red">Nama tidak dipadankan: <?= count($unmatchedFiles) ?></span><?php endif; ?>
</div>
<?php if ($unmatchedFiles): ?><details style="margin-top:10px"><summary><b>Lihat nama fail yang tidak mempunyai No. Matrik yang dapat dikenal pasti</b></summary><div class="unmatched"><?php foreach (array_slice($unmatchedFiles,0,200) as $badFile): ?><div><?= pv_h($badFile) ?></div><?php endforeach; ?><?php if (count($unmatchedFiles)>200): ?><div>... dan <?= count($unmatchedFiles)-200 ?> fail lagi</div><?php endif; ?></div></details><?php endif; ?>
</div>

<div class="stats">
<a class="stat blue" href="?view=all"><b><?= count($groups) ?></b>Pelajar ada gambar SFTP</a>
<a class="stat yellow" href="?view=duplicate"><b><?= $duplicateCount ?></b>Ada 2 atau lebih versi</a>
<a class="stat green" href="?view=selected"><b><?= $selectedCount ?></b>Gambar utama telah dipilih</a>
<a class="stat red" href="?view=pending"><b><?= $pendingCount ?></b>Menunggu fail lama dipadam</a>
</div>

<div class="card">
<form class="toolbar" method="get">
<input type="text" name="q" value="<?= pv_h($q) ?>" placeholder="Cari No. Matrik / nama">
<select name="view">
<option value="duplicate" <?= $view === 'duplicate' ? 'selected' : '' ?>>Ada 2+ versi</option>
<option value="all" <?= $view === 'all' ? 'selected' : '' ?>>Semua pelajar</option>
<option value="pending" <?= $view === 'pending' ? 'selected' : '' ?>>Menunggu padam</option>
<option value="selected" <?= $view === 'selected' ? 'selected' : '' ?>>Sudah pilih gambar</option>
</select>
<button class="btn" type="submit">Tapis</button>
<a class="btn secondary" href="/zurie/pages/photo_versions.php">Reset</a>
<span class="muted"><?= $totalGroups ?> kumpulan · <?= $perPage ?> pelajar setiap halaman · thumbnail ultra ringan, klik untuk gambar asal</span>
</form>
</div>

<?php if (!$groups): ?>
<div class="card empty"><h2>Belum ada data scan</h2><p>Klik <b>Scan SFTP</b> untuk membaca semua versi gambar pelajar.</p></div>
<?php elseif (!$pageGroups): ?>
<div class="card empty"><h2>Tiada rekod untuk penapis ini</h2></div>
<?php else: ?>
<?php foreach ($pageGroups as $matrik => $files):
    $review = $reviews[$matrik] ?? null;
    $name = (string)($names[$matrik] ?? ($review['nama'] ?? ''));
    $cleanupStatus = (string)($review['cleanup_status'] ?? '');
    $groupClass = in_array($cleanupStatus, ['pending', 'partial'], true) ? 'pending' : ($review ? 'selected' : '');
    $selectedSource = (string)($review['selected_source'] ?? '');
    $candidates = $review ? json_decode((string)($review['delete_candidates_json'] ?? '[]'), true) : [];
    if (!is_array($candidates)) $candidates = [];
    $cleanupComplete = $review
        && in_array($cleanupStatus, ['done', 'none'], true)
        && count($files) === 1
        && (string)($files[0]['filename'] ?? '') === $matrik . '.jpg';
?>
<form class="group <?= pv_h($groupClass) ?>" method="post" onsubmit="return confirm('Gunakan gambar pilihan sebagai <?= pv_h($matrik) ?>.jpg? Semua versi lain akan cuba diarkib. Jika arkib gagal, fail tidak dipilih tetap akan dipadam dari SFTP.');">
<input type="hidden" name="csrf" value="<?= pv_h($csrf) ?>"><input type="hidden" name="action" value="select"><input type="hidden" name="matrik" value="<?= pv_h($matrik) ?>"><input type="hidden" name="nama" value="<?= pv_h($name) ?>">
<div class="group-head">
<div><h2><?= pv_h($matrik) ?><?= $name !== '' ? ' — ' . pv_h($name) : '' ?></h2><span class="muted"><?= count($files) ?> fail gambar ditemui · dipaparkan sebelah-menyebelah</span></div>
<div>
<?php if ($cleanupStatus === 'partial'): ?><span class="badge red">ARKIB/PADAM SEPARA</span>
<?php elseif ($cleanupStatus === 'pending'): ?><span class="badge red">SEDIA ARKIB & PADAM</span>
<?php elseif ($cleanupComplete): ?><span class="badge green">PEMBERSIHAN SELESAI · 1 GAMBAR</span>
<?php elseif ($review): ?><span class="badge green">GAMBAR UTAMA DIPILIH</span>
<?php else: ?><span class="badge yellow">BELUM DISEMAK</span><?php endif; ?>
</div>
</div>
<div class="photos">
<?php foreach ($files as $file):
    $filename = (string)$file['filename'];
    $isStandard = $filename === $matrik . '.jpg';
    $isSelectedSource = ($selectedSource !== '' && hash_equals($selectedSource, $filename))
        || ($cleanupComplete && $isStandard);
    // Kekalkan cache selagi saiz/tarikh fail remote tidak berubah.
    $fileVersion = rawurlencode((string)($file['size'] ?? 0) . '-' . (string)($file['modified'] ?? ''));
    $thumb = '/zurie/api/mis_photo_version.php?mode=thumb&file=' . rawurlencode($filename) . '&v=' . $fileVersion;
    $full = '/zurie/api/mis_photo_version.php?mode=full&file=' . rawurlencode($filename) . '&v=' . $fileVersion;
?>
<label class="photo <?= $isSelectedSource ? 'selected-source' : '' ?>">
<a class="preview-link" href="<?= pv_h($full) ?>" target="_blank" rel="noopener" title="Klik untuk lihat gambar asal">
<img class="preview" loading="lazy" decoding="async" fetchpriority="low" src="<?= pv_h($thumb) ?>" alt="<?= pv_h($filename) ?>" onerror="this.style.opacity='.25';this.alt='Preview gagal';">
</a>
<div class="meta"><div class="filename"><?= pv_h($filename) ?></div><div><?= strtoupper(pv_h((string)$file['extension'])) ?> · <?= pv_h(pv_format_bytes((int)$file['size'])) ?></div><div><?= pv_h((string)($file['modified'] ?: 'Tarikh tidak tersedia')) ?></div><?php if ($isStandard): ?><span class="badge green">NAMA STANDARD MIS</span><?php endif; ?><?php if ($isSelectedSource): ?><span class="badge green">PILIHAN TERAKHIR</span><?php endif; ?></div>
<?php if ($cleanupComplete && $isStandard): ?><div class="radio" style="color:#166534">✓ Gambar akhir dalam SFTP</div>
<?php elseif (!empty($file['selectable'])): ?><div class="radio"><input type="radio" name="selected_file" value="<?= pv_h($filename) ?>" <?= $isSelectedSource ? 'checked' : '' ?>> Pilih gambar ini</div>
<?php else: ?><div class="warning">Format ini hanya untuk preview. Pilih JPG, JPEG atau PNG untuk convert.</div><?php endif; ?>
</label>
<?php endforeach; ?>
</div>
<div class="group-action">
<?php if (!$cleanupComplete): ?><button class="btn green" type="submit">✓ Pilih & Bersihkan</button>
<?php else: ?><div class="notice ok" style="margin:0;flex:1">Selesai. Hanya <b><?= pv_h($matrik) ?>.jpg</b> tinggal dalam SFTP.</div><?php endif; ?>
<?php if ($review): ?>
<div class="report-box"><b>Dipilih:</b> <?= pv_h((string)$review['selected_source']) ?><br><b>Standard MIS:</b> <?= pv_h((string)$review['standard_file']) ?><br><b>Calon padam:</b> <?= $candidates ? pv_h(implode(', ', array_map('strval', $candidates))) : 'Tiada' ?><br><span class="muted"><?= pv_h((string)($review['selected_at'] ?? '')) ?> oleh <?= pv_h((string)($review['selected_by'] ?? '')) ?></span></div>
<?php endif; ?>
</div>
</form>
<?php if ($review && in_array($cleanupStatus, ['pending', 'partial'], true) && $candidates): ?>
<form method="post" style="margin:-10px 0 18px;padding:0 16px;display:flex;justify-content:flex-end;gap:10px;align-items:center;flex-wrap:wrap" onsubmit="return confirm('BERSIHKAN <?= count($candidates) ?> fail lama untuk <?= pv_h($matrik) ?>? Sistem cuba arkib dahulu. Jika arkib gagal, fail tidak dipilih akan dipadam terus. Fail standard <?= pv_h($matrik) ?>.jpg tidak akan dipadam.');">
<input type="hidden" name="csrf" value="<?= pv_h($csrf) ?>"><input type="hidden" name="action" value="archive_delete"><input type="hidden" name="matrik" value="<?= pv_h($matrik) ?>">
<span class="muted">Arkib: <code>/zurie/archive/sftp_photos/YYYY/MM/DD/<?= pv_h($matrik) ?>/</code></span>
<button class="btn red" type="submit">🧹 Bersihkan <?= count($candidates) ?> Fail</button>
</form>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Pagination">
<?php for ($i = 1; $i <= $totalPages; $i++): $params = ['q' => $q, 'view' => $view, 'page' => $i]; ?>
<?php if ($i === $page): ?><span class="active"><?= $i ?></span><?php else: ?><a href="?<?= pv_h(http_build_query($params)) ?>"><?= $i ?></a><?php endif; ?>
<?php endfor; ?></nav><?php endif; ?>

<div class="card"><b>Nota:</b> Sistem mencipta dan mengesahkan fail standard <code>NOMATRIK.jpg</code> terlebih dahulu. Selepas itu, semua versi yang tidak dipilih cuba diarkib. Jika arkib gagal, fail tersebut dipadam terus supaya tiada gambar pendua. Fail standard sentiasa dilindungi.</div>
</div></body></html>

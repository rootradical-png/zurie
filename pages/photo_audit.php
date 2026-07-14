<?php
/**
 * Zurie Semakan Foto Kad Matrik
 * Paparan minimal berasaskan aliran kerja: foto, upload, kad dan RFID.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/auth_guard.php';
require_once dirname(__DIR__) . '/lib/photo_repair.php';
require_once dirname(__DIR__) . '/lib/photo_background_quality.php';
require_once dirname(__DIR__) . '/lib/pg_live_lookup.php';
require_once dirname(__DIR__) . '/lib/photo_missing_report.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

date_default_timezone_set('Asia/Kuala_Lumpur');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function pdo_zurie_audit(): PDO
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

function audit_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch();
}

function ensure_photo_audit_table(PDO $pdo): void
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
        background_status VARCHAR(30) NULL,
        background_score DECIMAL(5,1) NULL,
        background_white_ratio DECIMAL(5,1) NULL,
        background_uniformity DECIMAL(5,1) NULL,
        background_brightness DECIMAL(6,1) NULL,
        background_color_ratio DECIMAL(5,1) NULL,
        background_shadow_ratio DECIMAL(5,1) NULL,
        background_dominant_color VARCHAR(50) NULL,
        background_dominant_hex VARCHAR(10) NULL,
        background_reason VARCHAR(255) NULL,
        background_checked_at DATETIME NULL,
        background_checked_by VARCHAR(100) NULL,
        whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0,
        whatsapp_sent_at DATETIME NULL,
        whatsapp_type VARCHAR(30) NULL,
        whatsapp_note VARCHAR(255) NULL,
        whatsapp_source VARCHAR(50) NULL,
        whatsapp_sent_by VARCHAR(100) NULL,
        INDEX idx_quality_status (quality_status),
        INDEX idx_background_status (background_status),
        INDEX idx_whatsapp_sent (whatsapp_sent),
        INDEX idx_photo_exists (photo_exists),
        INDEX idx_checked_at (checked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $changes = [
        "ALTER TABLE student_photo_audit ADD COLUMN quality_status VARCHAR(30) NULL AFTER checked_at",
        "ALTER TABLE student_photo_audit ADD COLUMN quality_reason VARCHAR(255) NULL AFTER quality_status",
        "ALTER TABLE student_photo_audit ADD COLUMN quality_checked_at DATETIME NULL AFTER quality_reason",
        "ALTER TABLE student_photo_audit ADD COLUMN quality_checked_by VARCHAR(100) NULL AFTER quality_checked_at",
        "ALTER TABLE student_photo_audit ADD COLUMN background_status VARCHAR(30) NULL AFTER quality_checked_by",
        "ALTER TABLE student_photo_audit ADD COLUMN background_score DECIMAL(5,1) NULL AFTER background_status",
        "ALTER TABLE student_photo_audit ADD COLUMN background_white_ratio DECIMAL(5,1) NULL AFTER background_score",
        "ALTER TABLE student_photo_audit ADD COLUMN background_uniformity DECIMAL(5,1) NULL AFTER background_white_ratio",
        "ALTER TABLE student_photo_audit ADD COLUMN background_brightness DECIMAL(6,1) NULL AFTER background_uniformity",
        "ALTER TABLE student_photo_audit ADD COLUMN background_color_ratio DECIMAL(5,1) NULL AFTER background_brightness",
        "ALTER TABLE student_photo_audit ADD COLUMN background_shadow_ratio DECIMAL(5,1) NULL AFTER background_color_ratio",
        "ALTER TABLE student_photo_audit ADD COLUMN background_dominant_color VARCHAR(50) NULL AFTER background_shadow_ratio",
        "ALTER TABLE student_photo_audit ADD COLUMN background_dominant_hex VARCHAR(10) NULL AFTER background_dominant_color",
        "ALTER TABLE student_photo_audit ADD COLUMN background_reason VARCHAR(255) NULL AFTER background_dominant_hex",
        "ALTER TABLE student_photo_audit ADD COLUMN background_checked_at DATETIME NULL AFTER background_reason",
        "ALTER TABLE student_photo_audit ADD COLUMN background_checked_by VARCHAR(100) NULL AFTER background_checked_at",
        "ALTER TABLE student_photo_audit ADD COLUMN whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER background_checked_by",
        "ALTER TABLE student_photo_audit ADD COLUMN whatsapp_sent_at DATETIME NULL AFTER whatsapp_sent",
        "ALTER TABLE student_photo_audit ADD COLUMN whatsapp_type VARCHAR(30) NULL AFTER whatsapp_sent_at",
        "ALTER TABLE student_photo_audit ADD COLUMN whatsapp_note VARCHAR(255) NULL AFTER whatsapp_type",
        "ALTER TABLE student_photo_audit ADD COLUMN whatsapp_source VARCHAR(50) NULL AFTER whatsapp_note",
        "ALTER TABLE student_photo_audit ADD COLUMN whatsapp_sent_by VARCHAR(100) NULL AFTER whatsapp_source",
        "ALTER TABLE student_photo_audit ADD INDEX idx_quality_status (quality_status)",
        "ALTER TABLE student_photo_audit ADD INDEX idx_background_status (background_status)",
        "ALTER TABLE student_photo_audit ADD INDEX idx_whatsapp_sent (whatsapp_sent)",
    ];
    foreach ($changes as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Abaikan jika column/index sudah wujud.
        }
    }
}

function ensure_upload_table_audit(PDO $pdo): void
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
        "ALTER TABLE student_photo_uploads ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
        "ALTER TABLE student_photo_uploads ADD COLUMN original_file VARCHAR(255) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN repaired_file VARCHAR(255) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN repair_status VARCHAR(30) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN repair_message TEXT NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN repaired_at DATETIME NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN reviewed_at DATETIME NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN reviewed_by VARCHAR(100) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN reject_reason TEXT NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN reject_note TEXT NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN sync_status VARCHAR(30) NULL DEFAULT 'belum'",
        "ALTER TABLE student_photo_uploads ADD COLUMN sync_message TEXT NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN synced_at DATETIME NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN synced_by VARCHAR(100) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN sync_remote_file VARCHAR(255) NULL",
        "ALTER TABLE student_photo_uploads ADD COLUMN sync_attempts INT NOT NULL DEFAULT 0",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Abaikan jika column sudah wujud.
        }
    }
}

function csrf_token_audit(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['photo_audit_csrf'])) {
        $_SESSION['photo_audit_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['photo_audit_csrf'];
}

function require_csrf_audit(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    $real = (string)($_SESSION['photo_audit_csrf'] ?? '');
    if ($sent === '' || $real === '' || !hash_equals($real, $sent)) {
        throw new RuntimeException('Token keselamatan tidak sah. Sila refresh halaman dan cuba semula.');
    }
}

function actor_name_audit(): string
{
    return (string)($_SESSION['portal_username'] ?? $_SESSION['portal_display_name'] ?? 'admin');
}

function clean_matrik_audit(string $value): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');
}

/**
 * Ambil No. Matrik daripada nama fail gambar SFTP.
 * Logik diselaraskan dengan halaman photo_versions.php.
 */
function audit_photo_version_matrik(string $filename): string
{
    $decoded = rawurldecode(basename(str_replace('\\', '/', $filename)));
    $stem = strtoupper(trim((string)pathinfo($decoded, PATHINFO_FILENAME)));

    if (preg_match('/^([A-Z]{1,5}[0-9]{6,20})(?=$|[^A-Z0-9])/', $stem, $match)) {
        return clean_matrik_audit((string)$match[1]);
    }

    if (preg_match('/^[A-Z0-9]{8,30}$/', $stem)) {
        return clean_matrik_audit($stem);
    }

    return '';
}

/**
 * Baca snapshot manifest Versi Gambar tanpa membuat sambungan SFTP baharu.
 * Kiraan akan berubah selepas butang Scan SFTP dijalankan pada halaman Versi Gambar.
 *
 * @return array{counts:array<string,int>,total:int,scanned_at:string}
 */
function audit_photo_version_snapshot(): array
{
    $snapshot = ['counts' => [], 'total' => 0, 'scanned_at' => ''];
    $path = dirname(__DIR__) . '/data/photo_versions_manifest.json';
    if (!is_file($path)) {
        return $snapshot;
    }

    $raw = @file_get_contents($path);
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($manifest)) {
        return $snapshot;
    }

    $snapshot['scanned_at'] = trim((string)($manifest['scanned_at'] ?? ''));
    foreach ((array)($manifest['files'] ?? []) as $file) {
        if (!is_array($file)) {
            continue;
        }
        $filename = trim((string)($file['filename'] ?? ''));
        $matrik = $filename !== '' ? audit_photo_version_matrik($filename) : '';
        if ($matrik === '') {
            continue;
        }
        $snapshot['counts'][$matrik] = (int)($snapshot['counts'][$matrik] ?? 0) + 1;
        $snapshot['total']++;
    }

    return $snapshot;
}

/**
 * Seragamkan nilai Pengambilan daripada PostgreSQL/MIS.
 * Contoh yang diterima: 4, 04, Intake 4, Pengambilan 4.
 */
function audit_normalize_intake(mixed $value): string
{
    $text = trim((string)$value);
    if ($text === '' || $text === '-') {
        return '';
    }

    if (preg_match('/(?:pengambilan|intake)\s*[:\-]?\s*0*(\d+)/i', $text, $m)) {
        $number = ltrim((string)$m[1], '0');
        return $number === '' ? '0' : $number;
    }

    if (preg_match('/^0*(\d+)$/', $text, $m)) {
        $number = ltrim((string)$m[1], '0');
        return $number === '' ? '0' : $number;
    }

    return $text;
}

function audit_pick_phone_column(PDO $pdo): ?string
{
    foreach (['nohp', 'telefon', 'tel', 'notel', 'no_tel', 'hp', 'phone'] as $col) {
        if (audit_column_exists($pdo, 'senarai', $col)) {
            return $col;
        }
    }
    return null;
}


/**
 * Dapatkan pilihan unik daripada jadual senarai.
 *
 * @return array<int,string>
 */
function audit_distinct_student_options(PDO $pdo, string $column): array
{
    $allowed = ['jantina', 'praktikum', 'kuliah', 'jurusan', 'asrama'];
    if (!in_array($column, $allowed, true) || !audit_column_exists($pdo, 'senarai', $column)) {
        return [];
    }

    $safeColumn = str_replace('`', '', $column);
    $sql = "SELECT DISTINCT TRIM(COALESCE(`{$safeColumn}`,'')) AS option_value
            FROM senarai
            WHERE UPPER(TRIM(COALESCE(status,'')))='AKTIF'
              AND TRIM(COALESCE(`{$safeColumn}`,''))<>''
            ORDER BY option_value";
    $values = [];
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        $value = trim((string)($row['option_value'] ?? ''));
        if ($value !== '') {
            $values[] = $value;
        }
    }
    return array_values(array_unique($values));
}

/**
 * Dapatkan senarai intake sebenar daripada PostgreSQL i-SIMS.
 *
 * @return array<int,string>
 */
function audit_pg_intake_options(): array
{
    try {
        $config = zurie_pg_live_config();
        if (!zurie_pg_live_active_lookup_ready($config)) {
            return [];
        }

        $pgsql = zurie_pg_live_connect($config);
        $stmt = $pgsql->prepare(
            "SELECT DISTINCT TRIM(CAST(stud_intake AS TEXT)) AS intake
             FROM public.pelajar
             WHERE stud_semester = :semester
               AND stud_status = :active_status
               AND stud_intake IS NOT NULL
               AND TRIM(CAST(stud_intake AS TEXT)) <> ''"
        );
        $stmt->bindValue(':semester', (int)($config['semester'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':active_status', (string)($config['active_status'] ?? '01'), PDO::PARAM_STR);
        $stmt->execute();

        $values = [];
        foreach ($stmt->fetchAll() as $row) {
            $value = audit_normalize_intake($row['intake'] ?? '');
            if ($value !== '') {
                $values[] = $value;
            }
        }
        $values = array_values(array_unique($values));
        natsort($values);
        return array_values($values);
    } catch (Throwable $e) {
        return [];
    }
}


/**
 * Snapshot pelajar aktif sebenar daripada PostgreSQL berdasarkan semester dan kod status semasa.
 * PostgreSQL ialah sumber rujukan utama; table MySQL `senarai` hanya salinan operasi Zurie.
 *
 * @return array<string,mixed>
 */
function audit_pg_active_snapshot(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $cached = [
        'ready' => false,
        'semester' => 0,
        'active_status' => '',
        'academic_session' => '',
        'active_records' => 0,
        'unique_kp' => 0,
        'blank_kp_records' => 0,
        'roster_count' => 0,
        'duplicate_records' => 0,
        'unmapped_students' => 0,
        'rows' => [],
        'by_matrik' => [],
        'error' => '',
    ];

    try {
        $config = zurie_pg_live_config();
        $cached['semester'] = (int)($config['semester'] ?? 0);
        $cached['active_status'] = trim((string)($config['active_status'] ?? ''));
        $cached['academic_session'] = trim((string)($config['academic_session'] ?? ''));

        if (!zurie_pg_live_active_lookup_ready($config)) {
            $cached['error'] = 'Tetapan PostgreSQL semester/kod aktif belum lengkap.';
            return $cached;
        }

        $pgsql = zurie_pg_live_connect($config);
        $countStmt = $pgsql->prepare(
            "SELECT
                COUNT(*) AS active_records,
                COUNT(DISTINCT NULLIF(regexp_replace(COALESCE(stud_kp, ''), '[^0-9]', '', 'g'), '')) AS unique_kp,
                SUM(
                    CASE
                        WHEN NULLIF(regexp_replace(COALESCE(stud_kp, ''), '[^0-9]', '', 'g'), '') IS NULL
                        THEN 1 ELSE 0
                    END
                ) AS blank_kp_records
             FROM public.pelajar
             WHERE stud_semester = :semester
               AND stud_status = :active_status"
        );
        $countStmt->bindValue(':semester', (int)$config['semester'], PDO::PARAM_INT);
        $countStmt->bindValue(':active_status', (string)$config['active_status'], PDO::PARAM_STR);
        $countStmt->execute();
        $countRow = $countStmt->fetch() ?: [];
        $cached['active_records'] = (int)($countRow['active_records'] ?? 0);
        $cached['unique_kp'] = (int)($countRow['unique_kp'] ?? 0);
        $cached['blank_kp_records'] = (int)($countRow['blank_kp_records'] ?? 0);

        $rosterSql = <<<'SQL'
SELECT DISTINCT ON (UPPER(TRIM(COALESCE(personal.nomatrik, ''))))
    UPPER(TRIM(COALESCE(personal.nomatrik, ''))) AS matrik,
    TRIM(COALESCE(personal.nama, '')) AS nama,
    regexp_replace(COALESCE(personal.nokp, ''), '[^0-9]', '', 'g') AS nokp,
    regexp_replace(
        COALESCE(NULLIF(TRIM(personal.nohp), ''), NULLIF(TRIM(personal.notel), ''), ''),
        '[^0-9]', '', 'g'
    ) AS nohp,
    TRIM(COALESCE(personal.jantina, '')) AS jantina,
    CASE
        WHEN b.blok_nama IS NOT NULL THEN
            CONCAT(
                b.blok_nama,
                'T',
                CASE WHEN k.ktl_aras = '0' THEN 'G' ELSE k.ktl_aras END,
                '.',
                k.ktl_bilik
            )
        ELSE ''
    END AS asrama,
    TRIM(COALESCE(kl.kuliah_nama, '')) AS kuliah,
    TRIM(COALESCE(pr.praktikum_nama, '')) AS praktikum,
    TRIM(COALESCE(t.tutoran_nama, '')) AS tutoran,
    TRIM(COALESCE(e.english_nama, '')) AS english,
    TRIM(COALESCE(kk.koko_nama, '')) AS kokurikulum,
    TRIM(COALESCE(jp.jp_jurusan, '')) AS jurusan,
    TRIM(COALESCE(personal.cardno, '')) AS cardno,
    TRIM(COALESCE(personal.em_cardno, '')) AS em_cardno,
    'AKTIF'::text AS status,
    TRIM(CAST(pelajar.stud_intake AS TEXT)) AS stud_intake,
    pelajar.stud_semester AS stud_semester,
    pelajar.stud_status AS stud_status
FROM public.personal
INNER JOIN public.pelajar
    ON regexp_replace(COALESCE(pelajar.stud_kp, ''), '[^0-9]', '', 'g')
     = regexp_replace(COALESCE(personal.nokp, ''), '[^0-9]', '', 'g')
LEFT JOIN public.jurusan_pelajar jp
    ON regexp_replace(COALESCE(jp.jp_nokp, ''), '[^0-9]', '', 'g')
     = regexp_replace(COALESCE(personal.nokp, ''), '[^0-9]', '', 'g')
LEFT JOIN public.asrama a
    ON a.asr_profileid = personal.profileid
LEFT JOIN public.katil k
    ON k.ktl_id = a.asr_katil
LEFT JOIN public.blok b
    ON b.blok_id = k.ktl_blok
LEFT JOIN public.tutoran t
    ON t.tutoran_id = jp.jp_tutoran
LEFT JOIN public.kuliah kl
    ON kl.kuliah_id = t.tutoran_kuliah
LEFT JOIN public.praktikum pr
    ON pr.praktikum_id = t.tutoran_praktikum
LEFT JOIN public.english e
    ON e.english_id = jp.jp_english
LEFT JOIN public.koko kk
    ON kk.koko_id = jp.jp_koko
WHERE pelajar.stud_semester = :semester
  AND pelajar.stud_status = :active_status
  AND TRIM(COALESCE(personal.nomatrik, '')) <> ''
ORDER BY
    UPPER(TRIM(COALESCE(personal.nomatrik, ''))),
    pelajar.stud_intake DESC NULLS LAST
SQL;
        $stmt = $pgsql->prepare($rosterSql);
        $stmt->bindValue(':semester', (int)$config['semester'], PDO::PARAM_INT);
        $stmt->bindValue(':active_status', (string)$config['active_status'], PDO::PARAM_STR);
        $stmt->execute();

        $rows = [];
        $byMatrik = [];
        foreach ($stmt->fetchAll() as $row) {
            $matrik = clean_matrik_audit((string)($row['matrik'] ?? ''));
            if ($matrik === '') {
                continue;
            }
            $row['matrik'] = $matrik;
            $row['nokp'] = preg_replace('/\D+/', '', (string)($row['nokp'] ?? '')) ?? '';
            $row['nohp'] = preg_replace('/\D+/', '', (string)($row['nohp'] ?? '')) ?? '';
            $normalizedIntake = audit_normalize_intake($row['stud_intake'] ?? '');
            $row['stud_intake'] = $normalizedIntake !== '' ? $normalizedIntake : '-';
            $rows[] = $row;
            $byMatrik[$matrik] = $row;
        }

        $cached['rows'] = $rows;
        $cached['by_matrik'] = $byMatrik;
        $cached['roster_count'] = count($rows);
        $cached['duplicate_records'] = max(
            0,
            $cached['active_records'] - $cached['blank_kp_records'] - $cached['unique_kp']
        );
        $cached['unmapped_students'] = max(0, $cached['unique_kp'] - $cached['roster_count']);
        $cached['ready'] = true;
        return $cached;
    } catch (Throwable $e) {
        $cached['error'] = $e->getMessage();
        return $cached;
    }
}

/**
 * Gabungkan data kelas/intake semasa daripada PostgreSQL dan tandakan sama ada rekod masih aktif.
 * Jika PostgreSQL tersedia, tiada fallback intake bagi rekod yang tidak lagi aktif.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function audit_attach_pg_active(array $rows): array
{
    if ($rows === []) {
        return [];
    }

    $snapshot = audit_pg_active_snapshot();
    if (empty($snapshot['ready'])) {
        foreach ($rows as &$row) {
            $row['pg_active'] = null;
            $savedIntake = audit_normalize_intake($row['stud_intake'] ?? '');
            $row['stud_intake'] = $savedIntake !== '' ? $savedIntake : '-';

            if ($savedIntake === '') {
                foreach ([(string)($row['kuliah'] ?? ''), (string)($row['praktikum'] ?? '')] as $groupCode) {
                    if (preg_match('/^A\s*(\d+)/i', trim($groupCode), $m)) {
                        $row['stud_intake'] = audit_normalize_intake($m[1]) ?: '-';
                        break;
                    }
                }
            }
        }
        unset($row);
        return $rows;
    }

    $byMatrik = is_array($snapshot['by_matrik'] ?? null) ? $snapshot['by_matrik'] : [];
    $currentFields = [
        'nama', 'nokp', 'nohp', 'jantina', 'asrama', 'kuliah', 'praktikum',
        'tutoran', 'english', 'kokurikulum', 'jurusan', 'cardno', 'em_cardno',
        'stud_intake', 'stud_semester', 'stud_status',
    ];

    foreach ($rows as &$row) {
        $matrik = clean_matrik_audit((string)($row['matrik'] ?? ''));
        $active = $byMatrik[$matrik] ?? null;
        if (!is_array($active)) {
            $row['pg_active'] = 0;
            $row['stud_intake'] = '-';
            $row['stud_semester'] = null;
            $row['stud_status'] = null;
            continue;
        }

        $row['pg_active'] = 1;
        foreach ($currentFields as $field) {
            $value = $active[$field] ?? null;
            if ($value !== null && trim((string)$value) !== '') {
                $row[$field] = $value;
            } elseif (!array_key_exists($field, $row)) {
                $row[$field] = $value;
            }
        }
        $normalizedIntake = audit_normalize_intake($row['stud_intake'] ?? '');
        $row['stud_intake'] = $normalizedIntake !== '' ? $normalizedIntake : '-';
    }
    unset($row);

    return $rows;
}

/** @param array<int,array<string,mixed>> $rows */
function audit_keep_current_active_rows(array $rows): array
{
    $snapshot = audit_pg_active_snapshot();
    if (empty($snapshot['ready'])) {
        return $rows;
    }
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => (int)($row['pg_active'] ?? 0) === 1
    ));
}

/** @param array<string,string> $filters */
function audit_row_matches_filters(array $row, array $filters, string $search = ''): bool
{
    $map = [
        'jantina' => 'jantina',
        'intake' => 'stud_intake',
        'praktikum' => 'praktikum',
        'kuliah' => 'kuliah',
        'jurusan' => 'jurusan',
        'asrama' => 'asrama',
    ];
    foreach ($map as $key => $field) {
        $wanted = trim((string)($filters[$key] ?? ''));
        if ($wanted === '') {
            continue;
        }
        $actual = trim((string)($row[$field] ?? ''));
        if ($key === 'intake') {
            $wanted = audit_normalize_intake($wanted);
            $actual = audit_normalize_intake($actual);
        }
        if ($actual !== $wanted) {
            return false;
        }
    }

    $search = trim($search);
    if ($search === '') {
        return true;
    }
    $haystack = implode(' ', [
        (string)($row['matrik'] ?? ''),
        (string)($row['nama'] ?? ''),
        (string)($row['nokp'] ?? ''),
        (string)($row['praktikum'] ?? ''),
        (string)($row['kuliah'] ?? ''),
        (string)($row['jurusan'] ?? ''),
        (string)($row['asrama'] ?? ''),
        (string)($row['stud_intake'] ?? ''),
    ]);
    return stripos($haystack, $search) !== false;
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @param array<string,string> $filters
 * @return array<int,array<string,mixed>>
 */
function audit_filter_rows(array $rows, array $filters, string $search = ''): array
{
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => audit_row_matches_filters($row, $filters, $search)
    ));
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,array<int,string>>
 */
function audit_filter_options_from_rows(array $rows): array
{
    $map = [
        'jantina' => 'jantina',
        'intake' => 'stud_intake',
        'praktikum' => 'praktikum',
        'kuliah' => 'kuliah',
        'jurusan' => 'jurusan',
        'asrama' => 'asrama',
    ];
    $options = array_fill_keys(array_keys($map), []);
    foreach ($rows as $row) {
        foreach ($map as $key => $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($key === 'intake') {
                $value = audit_normalize_intake($value);
            }
            if ($value !== '' && $value !== '-') {
                $options[$key][$value] = $value;
            }
        }
    }
    foreach ($options as $key => $values) {
        $values = array_values($values);
        natsort($values);
        $options[$key] = array_values($values);
    }
    return $options;
}

/**
 * Ringkaskan semua status teknikal kepada satu status kerja yang mudah difahami.
 *
 * @return array{key:string,label:string,class:string,reason:string}
 */
function audit_workflow_state(array $row): array
{
    $checked = trim((string)($row['checked_at'] ?? '')) !== '';
    $exists = (int)($row['photo_exists'] ?? 0) === 1;
    $quality = strtolower(trim((string)($row['quality_status'] ?? '')));
    $background = strtolower(trim((string)($row['background_status'] ?? '')));
    $uploadStatus = strtolower(trim((string)($row['upload_status'] ?? '')));
    $syncStatus = strtolower(trim((string)($row['sync_status'] ?? '')));

    if (!$checked) {
        return [
            'key' => 'review',
            'label' => 'Perlu Semakan',
            'class' => 'state-review',
            'reason' => 'Audit gambar belum dijalankan.',
        ];
    }

    if (!$exists) {
        return [
            'key' => 'missing',
            'label' => 'Tiada Gambar',
            'class' => 'state-missing',
            'reason' => 'Gambar tidak ditemui dalam MIS.',
        ];
    }

    if (in_array($uploadStatus, ['baru', 'pending_registration'], true)) {
        return [
            'key' => 'upload',
            'label' => 'Upload Baharu',
            'class' => 'state-upload',
            'reason' => $uploadStatus === 'pending_registration'
                ? 'Gambar diterima dan menunggu pendaftaran fizikal pelajar.'
                : 'Gambar baharu menunggu semakan admin.',
        ];
    }

    if ($uploadStatus === 'tolak') {
        $reason = trim((string)($row['reject_reason'] ?? ''));
        return [
            'key' => 'review',
            'label' => 'Perlu Semakan',
            'class' => 'state-review',
            'reason' => $reason !== '' ? 'Gambar upload ditolak: ' . $reason : 'Gambar upload telah ditolak.',
        ];
    }

    if ($syncStatus === 'gagal') {
        return [
            'key' => 'review',
            'label' => 'Perlu Semakan',
            'class' => 'state-review',
            'reason' => 'Gambar gagal dihantar ke MIS.',
        ];
    }

    if ($uploadStatus === 'lulus' && $syncStatus !== 'berjaya') {
        return [
            'key' => 'review',
            'label' => 'Perlu Semakan',
            'class' => 'state-review',
            'reason' => 'Gambar telah lulus tetapi belum dihantar ke MIS.',
        ];
    }

    if ($quality === 'repair') {
        return [
            'key' => 'review',
            'label' => 'Perlu Semakan',
            'class' => 'state-review',
            'reason' => trim((string)($row['quality_reason'] ?? '')) ?: 'Gambar memerlukan pembaikan.',
        ];
    }

    if ($quality === 'upload_baru') {
        return [
            'key' => 'review',
            'label' => 'Perlu Semakan',
            'class' => 'state-review',
            'reason' => trim((string)($row['quality_reason'] ?? '')) ?: 'Pelajar perlu memuat naik gambar baharu.',
        ];
    }

    if (in_array($background, ['semak', 'tolak', 'gagal'], true)) {
        $reason = trim((string)($row['background_reason'] ?? ''));
        $fallback = match ($background) {
            'tolak' => 'Latar belakang gambar tidak sesuai.',
            'gagal' => 'Analisis gambar gagal dan perlu disemak.',
            default => 'Gambar memerlukan semakan manual.',
        };
        return [
            'key' => 'review',
            'label' => 'Perlu Semakan',
            'class' => 'state-review',
            'reason' => $reason !== '' ? $reason : $fallback,
        ];
    }

    if ($quality === '' || $background === '') {
        return [
            'key' => 'review',
            'label' => 'Perlu Semakan',
            'class' => 'state-review',
            'reason' => 'Gambar ada tetapi penilaian belum lengkap.',
        ];
    }

    // Maklumat kad hanya digunakan apabila rekod datang daripada roster PostgreSQL aktif.
    if ((int)($row['pg_active'] ?? 0) === 1) {
        $cardNo = trim((string)($row['cardno'] ?? ''));
        $rfidUid = trim((string)($row['em_cardno'] ?? ''));
        if ($cardNo === '' || $rfidUid === '') {
            if ($cardNo === '' && $rfidUid === '') {
                $reason = 'No. cetakan kad dan UID RFID belum direkod.';
            } elseif ($cardNo === '') {
                $reason = 'No. cetakan kad belum direkod.';
            } else {
                $reason = 'UID RFID belum didaftarkan.';
            }
            return [
                'key' => 'card',
                'label' => 'Kad Belum Lengkap',
                'class' => 'state-card',
                'reason' => $reason,
            ];
        }
    }

    return [
        'key' => 'completed',
        'label' => 'Selesai',
        'class' => 'state-completed',
        'reason' => 'Gambar dan maklumat kad telah lengkap.',
    ];
}

/**
 * Tentukan tiga isu utama yang perlu diberi perhatian.
 * Setiap isu dikira secara bebas supaya seorang pelajar boleh berada
 * dalam lebih daripada satu kategori.
 *
 * @return array{missing:bool,repair:bool,rfid:bool,reasons:array<int,string>}
 */
function audit_issue_flags(array $row): array
{
    $checked = trim((string)($row['checked_at'] ?? '')) !== '';
    $exists = (int)($row['photo_exists'] ?? 0) === 1;
    $quality = strtolower(trim((string)($row['quality_status'] ?? '')));
    $background = strtolower(trim((string)($row['background_status'] ?? '')));

    // Hanya sah dianggap tiada gambar selepas audit MIS telah dijalankan.
    $missing = $checked && !$exists;

    // Selaraskan dengan kiraan halaman REGISTER RFID MIS: cardno kosong.
    $rfid = (int)($row['pg_active'] ?? 0) === 1
        && trim((string)($row['cardno'] ?? '')) === '';

    // Fokus kepada gambar yang memang perlu pembaikan/semakan visual.
    $repair = $exists && (
        $quality === 'repair'
        || in_array($background, ['semak', 'tolak', 'gagal'], true)
    );

    $reasons = [];
    if ($missing) {
        $reasons[] = 'Gambar tidak ditemui dalam MIS.';
    }
    if ($repair) {
        $reason = trim((string)($row['quality_reason'] ?? ''));
        if ($reason === '') {
            $reason = trim((string)($row['background_reason'] ?? ''));
        }
        $reasons[] = $reason !== '' ? $reason : 'Gambar memerlukan pembaikan.';
    }
    if ($rfid) {
        $reasons[] = 'Kad RFID belum didaftarkan dalam MIS.';
    }

    return [
        'missing' => $missing,
        'repair' => $repair,
        'rfid' => $rfid,
        'reasons' => $reasons,
    ];
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,int>
 */
function audit_compute_stats(array $rows): array
{
    $stats = [
        'aktif' => count($rows),
        'sudah_semak' => 0,
        'belum_semak' => 0,
        'ada_mis' => 0,
        'tiada_mis' => 0,
        'belum_nilai' => 0,
        'baik' => 0,
        'repair' => 0,
        'upload_baru' => 0,
        'bg_checked' => 0,
        'bg_pending' => 0,
        'bg_ok' => 0,
        'bg_review' => 0,
        'bg_reject' => 0,
        'bg_failed' => 0,
        'perlu_whatsapp' => 0,
        'sudah_whatsapp' => 0,
        'simple_missing' => 0,
        'simple_repair' => 0,
        'simple_rfid' => 0,
        // Kekalkan key lama untuk keserasian dalaman.
        'simple_review' => 0,
        'simple_upload' => 0,
        'simple_review_total' => 0,
        'simple_card' => 0,
        'simple_completed' => 0,
        'simple_attention' => 0,
    ];
    foreach ($rows as $row) {
        $checked = trim((string)($row['checked_at'] ?? '')) !== '';
        $exists = (int)($row['photo_exists'] ?? 0) === 1;
        $quality = trim((string)($row['quality_status'] ?? ''));
        $stats[$checked ? 'sudah_semak' : 'belum_semak']++;
        if ($exists) {
            $stats['ada_mis']++;
        } elseif ($checked) {
            $stats['tiada_mis']++;
        }
        if ($exists && $quality === '') {
            $stats['belum_nilai']++;
        }
        if ($quality === 'baik') {
            $stats['baik']++;
        } elseif ($quality === 'repair') {
            $stats['repair']++;
        } elseif ($quality === 'upload_baru') {
            $stats['upload_baru']++;
        }
        $backgroundStatus = trim((string)($row['background_status'] ?? ''));
        if ($exists && $backgroundStatus === '') {
            $stats['bg_pending']++;
        }
        if ($backgroundStatus !== '') {
            $stats['bg_checked']++;
        }
        if (in_array($backgroundStatus, ['putih', 'hampir_putih'], true)) {
            $stats['bg_ok']++;
        } elseif ($backgroundStatus === 'semak') {
            $stats['bg_review']++;
        } elseif ($backgroundStatus === 'tolak') {
            $stats['bg_reject']++;
        } elseif ($backgroundStatus === 'gagal') {
            $stats['bg_failed']++;
        }
        $waContext = audit_whatsapp_context($row);
        if ($waContext['needed']) {
            $stats['perlu_whatsapp']++;
            if ((int)($row['whatsapp_sent'] ?? 0) === 1) {
                $stats['sudah_whatsapp']++;
            }
        }

        $issues = audit_issue_flags($row);
        if ($issues['missing']) {
            $stats['simple_missing']++;
        }
        if ($issues['repair']) {
            $stats['simple_repair']++;
            $stats['simple_review']++;
            $stats['simple_review_total']++;
        }
        if ($issues['rfid']) {
            $stats['simple_rfid']++;
            $stats['simple_card']++;
        }

        if ($issues['missing'] || $issues['repair'] || $issues['rfid']) {
            $stats['simple_attention']++;
        } else {
            $stats['simple_completed']++;
        }
    }
    return $stats;
}

/**
 * @return array<string,mixed>
 */
function audit_pg_reconciliation(PDO $pdo): array
{
    $snapshot = audit_pg_active_snapshot();
    $result = [
        'ready' => (bool)($snapshot['ready'] ?? false),
        'semester' => (int)($snapshot['semester'] ?? 0),
        'active_status' => (string)($snapshot['active_status'] ?? ''),
        'academic_session' => (string)($snapshot['academic_session'] ?? ''),
        'active_records' => (int)($snapshot['active_records'] ?? 0),
        'unique_kp' => (int)($snapshot['unique_kp'] ?? 0),
        'blank_kp_records' => (int)($snapshot['blank_kp_records'] ?? 0),
        'roster_count' => (int)($snapshot['roster_count'] ?? 0),
        'duplicate_records' => (int)($snapshot['duplicate_records'] ?? 0),
        'unmapped_students' => (int)($snapshot['unmapped_students'] ?? 0),
        'local_active' => 0,
        'matched' => 0,
        'missing_local' => 0,
        'stale_local' => 0,
        'error' => (string)($snapshot['error'] ?? ''),
    ];
    if (empty($snapshot['ready'])) {
        return $result;
    }

    $localRows = $pdo->query(
        "SELECT matrik FROM senarai
         WHERE UPPER(TRIM(COALESCE(status,'')))='AKTIF'"
    )->fetchAll();
    $localMap = [];
    foreach ($localRows as $row) {
        $matrik = clean_matrik_audit((string)($row['matrik'] ?? ''));
        if ($matrik !== '') {
            $localMap[$matrik] = true;
        }
    }
    $pgMap = is_array($snapshot['by_matrik'] ?? null) ? $snapshot['by_matrik'] : [];
    $result['local_active'] = count($localMap);
    $result['matched'] = count(array_intersect_key($localMap, $pgMap));
    $result['missing_local'] = count(array_diff_key($pgMap, $localMap));
    $result['stale_local'] = count(array_diff_key($localMap, $pgMap));
    return $result;
}

/**
 * Selaras table MySQL `senarai` dengan roster aktif PostgreSQL semasa.
 * Rekod aktif PG di-upsert; rekod MySQL yang masih AKTIF tetapi tiada dalam roster semasa
 * ditandakan TIDAK_AKTIF. Rekod audit dan upload tidak dipadam.
 *
 * @return array<string,int>
 */
function audit_reconcile_active_students(PDO $pdo): array
{
    $snapshot = audit_pg_active_snapshot();
    if (empty($snapshot['ready'])) {
        throw new RuntimeException((string)($snapshot['error'] ?? 'PostgreSQL aktif tidak tersedia.'));
    }
    $roster = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];
    if ($roster === []) {
        throw new RuntimeException('Roster aktif PostgreSQL kosong. Proses selaras dibatalkan untuk keselamatan.');
    }

    zurie_pg_live_ensure_senarai_table($pdo);
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_zurie_photo_active');
    $pdo->exec('CREATE TEMPORARY TABLE tmp_zurie_photo_active (matrik VARCHAR(30) NOT NULL PRIMARY KEY) ENGINE=MEMORY');
    $insertTemp = $pdo->prepare('INSERT IGNORE INTO tmp_zurie_photo_active (matrik) VALUES (?)');
    foreach ($roster as $student) {
        $matrik = clean_matrik_audit((string)($student['matrik'] ?? ''));
        if ($matrik !== '') {
            $insertTemp->execute([$matrik]);
        }
    }

    $synced = 0;
    $pdo->beginTransaction();
    try {
        foreach ($roster as $student) {
            zurie_pg_live_sync_student_to_mysql($pdo, $student);
            $synced++;
        }
        $stale = $pdo->exec(
            "UPDATE senarai s
             LEFT JOIN tmp_zurie_photo_active t ON UPPER(TRIM(s.matrik))=t.matrik
             SET s.status='TIDAK_AKTIF', s.synced_at=NOW()
             WHERE UPPER(TRIM(COALESCE(s.status,'')))='AKTIF'
               AND t.matrik IS NULL"
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        try {
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_zurie_photo_active');
        } catch (Throwable $e) {
            // Abaikan kegagalan buang table sementara.
        }
    }

    return [
        'synced' => $synced,
        'stale' => (int)$stale,
        'active_records' => (int)($snapshot['active_records'] ?? 0),
        'unique_kp' => (int)($snapshot['unique_kp'] ?? 0),
        'roster_count' => (int)($snapshot['roster_count'] ?? 0),
    ];
}

/**
 * Tambahkan penapis medan pelajar kepada klausa SQL.
 *
 * @param array<int,string> $where
 * @param array<int,mixed> $params
 * @param array<string,string> $filters
 */
function audit_apply_student_filters(array &$where, array &$params, array $filters): void
{
    $map = [
        'jantina' => 'jantina',
        'praktikum' => 'praktikum',
        'kuliah' => 'kuliah',
        'jurusan' => 'jurusan',
        'asrama' => 'asrama',
    ];

    foreach ($map as $key => $column) {
        $value = trim((string)($filters[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        $safeColumn = str_replace('`', '', $column);
        $where[] = "TRIM(COALESCE(s.`{$safeColumn}`,'')) = ?";
        $params[] = $value;
    }
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function audit_filter_rows_by_intake(array $rows, string $intake): array
{
    $intake = audit_normalize_intake($intake);
    if ($intake === '') {
        return $rows;
    }

    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => audit_normalize_intake($row['stud_intake'] ?? '') === $intake
    ));
}

/**
 * @param array<string,string> $filters
 */
function audit_filter_summary(array $filters, string $search = ''): string
{
    $labels = [
        'jantina' => 'Jantina',
        'intake' => 'Intake',
        'praktikum' => 'Praktikum',
        'kuliah' => 'Kuliah',
        'jurusan' => 'Jurusan',
        'asrama' => 'Asrama',
    ];
    $parts = [];
    if (trim($search) !== '') {
        $parts[] = 'Carian: ' . trim($search);
    }
    foreach ($labels as $key => $label) {
        $value = trim((string)($filters[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $label . ': ' . $value;
        }
    }
    return $parts === [] ? 'Semua rekod' : implode(' | ', $parts);
}

function audit_gender_label(string $value): string
{
    $normalized = strtoupper(trim($value));
    return match ($normalized) {
        'L', 'LELAKI', 'MALE', 'M' => 'Lelaki',
        'P', 'PEREMPUAN', 'FEMALE', 'F' => 'Perempuan',
        default => $value,
    };
}

/**
 * Ambil semua pelajar aktif yang telah diaudit tetapi masih tiada gambar MIS.
 * Laporan PDF tidak menggunakan LIMIT 500 seperti paparan skrin.
 *
 * @return array<int,array<string,mixed>>
 */
function audit_missing_report_rows(
    PDO $pdo,
    ?string $phoneColumn,
    string $search = '',
    array $studentFilters = []
): array {
    $phoneSelect = $phoneColumn
        ? ', s.`' . str_replace('`', '', $phoneColumn) . '` AS nohp'
        : ", '' AS nohp";

    $sql = "SELECT
                s.matrik, s.nama, s.nokp, s.jantina, s.asrama,
                s.praktikum, s.kuliah, s.jurusan,
                s.stud_intake, s.stud_semester, s.stud_status" . $phoneSelect . ",
                a.checked_at, a.photo_exists, a.quality_status,
                a.whatsapp_sent, a.whatsapp_sent_at,
                u.status AS upload_status, u.sync_status, u.uploaded_at
            FROM senarai s
            INNER JOIN student_photo_audit a ON UPPER(a.matrik)=UPPER(s.matrik)
            LEFT JOIN student_photo_uploads u ON UPPER(u.matrik)=UPPER(s.matrik)
            WHERE UPPER(TRIM(COALESCE(s.status,'')))='AKTIF'
              AND a.checked_at IS NOT NULL
              AND COALESCE(a.photo_exists,0)=0
            ORDER BY COALESCE(s.praktikum,''), s.matrik";
    $rows = audit_keep_current_active_rows(audit_attach_pg_active($pdo->query($sql)->fetchAll()));
    return audit_filter_rows($rows, $studentFilters, $search);
}

function audit_current_upload_url(): string
{
    $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '';
    if ($host === '') {
        return '';
    }
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    return ($https ? 'https' : 'http') . '://' . $host . '/zurie/upload/';
}

function audit_download_missing_pdf(
    PDO $pdo,
    ?string $phoneColumn,
    string $search,
    string $actor,
    array $studentFilters = []
): void {
    $reportRows = audit_missing_report_rows($pdo, $phoneColumn, $search, $studentFilters);
    $lastAudit = '-';
    foreach ($reportRows as $row) {
        $checkedAt = trim((string)($row['checked_at'] ?? ''));
        if ($checkedAt !== '' && ($lastAudit === '-' || strcmp($checkedAt, $lastAudit) > 0)) {
            $lastAudit = $checkedAt;
        }
    }
    if ($lastAudit !== '-') {
        try {
            $lastAudit = (new DateTimeImmutable($lastAudit))->format('d/m/Y H:i');
        } catch (Throwable $e) {
            // Kekalkan nilai asal jika format tarikh tidak standard.
        }
    }

    $stamp = date('Ymd-His');
    $reportNo = 'ZURIE/HEP/FOTO/' . $stamp;
    $pdf = zurie_build_missing_photo_report($reportRows, [
        'report_no' => $reportNo,
        'report_date' => date('d/m/Y H:i'),
        'actor' => $actor,
        'search' => $search,
        'filter_summary' => audit_filter_summary($studentFilters, $search),
        'upload_link' => audit_current_upload_url(),
        'last_audit' => $lastAudit,
    ]);

    $filename = 'laporan_hep_tiada_gambar_' . date('Ymd_His') . '.pdf';
    if (ob_get_length() !== false && ob_get_length() > 0) {
        @ob_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store, max-age=0');
    echo $pdf;
    exit;
}

/**
 * Tambah maklumat intake daripada PostgreSQL i-SIMS.
 * Jika sambungan PG tidak tersedia, cuba baca kod A1/A2 daripada Kuliah atau Praktikum.
 */
function audit_append_pg_intake(array $rows): array
{
    return audit_attach_pg_active($rows);
}

function mis_photo_check(string $matrik): array
{
    $matrik = clean_matrik_audit($matrik);
    $base = 'http://mis.kmp.matrik.edu.my/misv3/pictures/student/';
    $exts = ['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'];
    $lastCode = 0;
    $lastError = '';

    foreach ($exts as $ext) {
        $url = $base . rawurlencode($matrik) . '.' . $ext;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'ZuriePhotoAudit/6.0',
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $err = (string)curl_error($ch);
            curl_close($ch);

            $lastCode = $code;
            $lastError = $err;
            if ($code === 200 && ($ctype === '' || stripos($ctype, 'image') !== false)) {
                return ['exists' => 1, 'url' => $url, 'code' => $code, 'error' => null];
            }
            continue;
        }

        $headers = @get_headers($url, 1);
        if (is_array($headers)) {
            $statusLine = (string)($headers[0] ?? '');
            if (preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
                $lastCode = (int)$m[1];
            }
            if (strpos($statusLine, '200') !== false) {
                return ['exists' => 1, 'url' => $url, 'code' => 200, 'error' => null];
            }
        }
    }

    return ['exists' => 0, 'url' => null, 'code' => $lastCode, 'error' => $lastError ?: null];
}

/**
 * Ambil gambar MIS dan pulangkan bytes JPEG standard.
 * @return array{bytes:string,url:string}|null
 */
function mis_photo_fetch_jpeg(string $matrik): ?array
{
    $matrik = clean_matrik_audit($matrik);
    $base = 'http://mis.kmp.matrik.edu.my/misv3/pictures/student/';

    foreach (['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'] as $ext) {
        $url = $base . rawurlencode($matrik) . '.' . $ext;
        $data = false;
        $code = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_USERAGENT => 'ZuriePhotoRepair/6.0',
            ]);
            $data = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'timeout' => 20,
                'header' => "User-Agent: ZuriePhotoRepair/6.0\r\n",
            ]]);
            $data = @file_get_contents($url, false, $ctx);
            $code = $data === false ? 0 : 200;
        }

        if ($code !== 200 || $data === false || strlen((string)$data) < 500) {
            continue;
        }

        $info = @getimagesizefromstring((string)$data);
        $mime = (string)($info['mime'] ?? '');
        if ($mime === 'image/jpeg') {
            return ['bytes' => (string)$data, 'url' => $url];
        }

        if ($mime === 'image/png' && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $src = @imagecreatefromstring((string)$data);
            if (!$src) {
                continue;
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $dst = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $w, $h, $white);
            imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
            ob_start();
            imagejpeg($dst, null, 92);
            $jpg = ob_get_clean();
            imagedestroy($src);
            imagedestroy($dst);
            if ($jpg !== false && strlen((string)$jpg) > 500) {
                return ['bytes' => (string)$jpg, 'url' => $url];
            }
        }
    }

    return null;
}


/**
 * Analisis background gambar MIS dan simpan keputusan.
 * Cadangan automatik hanya digunakan jika rekod belum dinilai secara manual.
 *
 * @return array<string,mixed>
 */
function audit_background_check(PDO $pdo, string $matrik, string $actor): array
{
    @set_time_limit(90);
    $matrik = clean_matrik_audit($matrik);
    if ($matrik === '') {
        throw new RuntimeException('No matrik tidak sah.');
    }

    $auditStmt = $pdo->prepare("SELECT photo_exists, quality_status, quality_checked_by
        FROM student_photo_audit WHERE UPPER(matrik)=UPPER(?) LIMIT 1");
    $auditStmt->execute([$matrik]);
    $current = $auditStmt->fetch();
    if (!$current || (int)($current['photo_exists'] ?? 0) !== 1) {
        throw new RuntimeException('Gambar MIS belum tersedia untuk analisis background.');
    }

    $mis = mis_photo_fetch_jpeg($matrik);
    if (!$mis) {
        throw new RuntimeException('Gambar MIS tidak dapat dimuat turun untuk analisis background.');
    }

    $analysis = zurie_photo_background_analyse((string)$mis['bytes']);
    $save = $pdo->prepare("UPDATE student_photo_audit SET
        background_status=?, background_score=?, background_white_ratio=?,
        background_uniformity=?, background_brightness=?, background_color_ratio=?,
        background_shadow_ratio=?, background_dominant_color=?, background_dominant_hex=?,
        background_reason=?, background_checked_at=NOW(), background_checked_by=?
        WHERE UPPER(matrik)=UPPER(?)");
    $save->execute([
        (string)($analysis['status'] ?? 'gagal'),
        (float)($analysis['score'] ?? 0),
        (float)($analysis['white_ratio'] ?? 0),
        (float)($analysis['uniformity'] ?? 0),
        (float)($analysis['brightness'] ?? 0),
        (float)($analysis['color_ratio'] ?? 0),
        (float)($analysis['shadow_ratio'] ?? 0),
        (string)($analysis['dominant_color'] ?? '-'),
        (string)($analysis['dominant_hex'] ?? '#000000'),
        (string)($analysis['reason'] ?? 'Analisis background gagal.'),
        $actor,
        $matrik,
    ]);

    $currentQuality = trim((string)($current['quality_status'] ?? ''));
    if ($currentQuality === '') {
        $bgStatus = (string)($analysis['status'] ?? 'gagal');
        $autoReason = 'Auto BG: ' . (string)($analysis['reason'] ?? '');
        if (in_array($bgStatus, ['putih', 'hampir_putih'], true)) {
            audit_set_quality($pdo, $matrik, 'baik', $actor, $autoReason);
            $analysis['auto_action'] = 'baik';
        } elseif ($bgStatus === 'tolak') {
            audit_set_quality($pdo, $matrik, 'upload_baru', $actor, $autoReason);
            $analysis['auto_action'] = 'upload_baru';
        } else {
            $analysis['auto_action'] = 'semak_manual';
        }
    } else {
        $analysis['auto_action'] = 'kekal_manual';
    }

    return $analysis;
}

/**
 * Ringkasan kategori auto background untuk pelajar aktif.
 *
 * @return array<string,int>
 */
function audit_background_category_counts(PDO $pdo): array
{
    $sql = "SELECT
        SUM(CASE WHEN a.photo_exists=1 THEN 1 ELSE 0 END) AS total_images,
        SUM(CASE WHEN a.photo_exists=1 AND TRIM(COALESCE(a.background_status,''))='' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN a.background_status IN ('putih','hampir_putih') THEN 1 ELSE 0 END) AS accepted,
        SUM(CASE WHEN a.background_status='semak' THEN 1 ELSE 0 END) AS review,
        SUM(CASE WHEN a.background_status='tolak' THEN 1 ELSE 0 END) AS rejected,
        SUM(CASE WHEN a.background_status='gagal' THEN 1 ELSE 0 END) AS failed
      FROM student_photo_audit a
      INNER JOIN senarai s ON UPPER(s.matrik)=UPPER(a.matrik)
      WHERE UPPER(TRIM(COALESCE(s.status,'')))='AKTIF'";
    $row = $pdo->query($sql)->fetch() ?: [];
    return [
        'total_images' => (int)($row['total_images'] ?? 0),
        'pending' => (int)($row['pending'] ?? 0),
        'accepted' => (int)($row['accepted'] ?? 0),
        'review' => (int)($row['review'] ?? 0),
        'rejected' => (int)($row['rejected'] ?? 0),
        'failed' => (int)($row['failed'] ?? 0),
    ];
}

/**
 * Reset keputusan auto background supaya semua gambar boleh dinilai semula
 * menggunakan ambang semasa. Keputusan manual admin tidak disentuh.
 *
 * @return array<string,mixed>
 */
function audit_background_reset_for_recheck(PDO $pdo): array
{
    $pdo->beginTransaction();
    try {
        // Buang queue WhatsApp auto yang belum dihantar sahaja.
        $clearWa = $pdo->exec("UPDATE student_photo_audit SET
            whatsapp_type=NULL, whatsapp_note=NULL, whatsapp_source=NULL, whatsapp_sent_by=NULL,
            whatsapp_sent=0, whatsapp_sent_at=NULL
            WHERE photo_exists=1
              AND COALESCE(whatsapp_sent,0)=0
              AND whatsapp_source='photo_audit'
              AND whatsapp_type='upload_baru'
              AND quality_reason LIKE 'Auto BG:%'");

        // Reset hanya keputusan kualiti yang dijana oleh Auto BG.
        $clearQuality = $pdo->exec("UPDATE student_photo_audit SET
            quality_status=NULL, quality_reason=NULL, quality_checked_at=NULL, quality_checked_by=NULL
            WHERE photo_exists=1 AND quality_reason LIKE 'Auto BG:%'");

        // Reset semua keputusan analisis background; keputusan manual lain kekal.
        $resetBackground = $pdo->exec("UPDATE student_photo_audit SET
            background_status=NULL, background_score=NULL, background_white_ratio=NULL,
            background_uniformity=NULL, background_brightness=NULL, background_color_ratio=NULL,
            background_shadow_ratio=NULL, background_dominant_color=NULL, background_dominant_hex=NULL,
            background_reason=NULL, background_checked_at=NULL, background_checked_by=NULL
            WHERE photo_exists=1");

        $pdo->commit();
        return [
            'reset' => (int)$resetBackground,
            'quality_reset' => (int)$clearQuality,
            'wa_reset' => (int)$clearWa,
            'counts' => audit_background_category_counts($pdo),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function audit_background_mark_failed(PDO $pdo, string $matrik, string $actor, string $reason): void
{
    $stmt = $pdo->prepare("UPDATE student_photo_audit SET
        background_status='gagal', background_score=0, background_white_ratio=0,
        background_uniformity=0, background_brightness=0, background_color_ratio=0,
        background_shadow_ratio=0, background_dominant_color='-', background_dominant_hex='#64748b',
        background_reason=?, background_checked_at=NOW(), background_checked_by=?
        WHERE UPPER(matrik)=UPPER(?)");
    $stmt->execute([substr($reason, 0, 250), $actor, $matrik]);
}

/**
 * Proses gambar belum dinilai secara batch kecil supaya satu klik boleh berjalan
 * berterusan tanpa satu request yang terlalu panjang.
 *
 * @return array<string,mixed>
 */
function audit_background_auto_batch(PDO $pdo, int $limit, string $actor): array
{
    @set_time_limit(300);
    @ignore_user_abort(true);
    $limit = max(1, min(10, $limit));

    $stmt = $pdo->prepare("SELECT a.matrik
        FROM student_photo_audit a
        INNER JOIN senarai s ON UPPER(s.matrik)=UPPER(a.matrik)
        WHERE UPPER(TRIM(COALESCE(s.status,'')))='AKTIF'
          AND a.photo_exists=1
          AND TRIM(COALESCE(a.background_status,''))=''
        ORDER BY a.matrik
        LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $matriks = array_values(array_filter(array_map(
        static fn(array $row): string => clean_matrik_audit((string)($row['matrik'] ?? '')),
        $stmt->fetchAll()
    )));

    $processed = 0;
    $batchCounts = ['accepted' => 0, 'review' => 0, 'rejected' => 0, 'failed' => 0];
    $failures = [];

    foreach ($matriks as $matrik) {
        try {
            $result = audit_background_check($pdo, $matrik, $actor);
            $status = (string)($result['status'] ?? 'gagal');
            if (in_array($status, ['putih', 'hampir_putih'], true)) {
                $batchCounts['accepted']++;
            } elseif ($status === 'semak') {
                $batchCounts['review']++;
            } elseif ($status === 'tolak') {
                $batchCounts['rejected']++;
            } else {
                $batchCounts['failed']++;
            }
        } catch (Throwable $e) {
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'Analisis gagal.';
            audit_background_mark_failed($pdo, $matrik, $actor, $message);
            $batchCounts['failed']++;
            $failures[] = $matrik . ': ' . $message;
        }
        $processed++;
    }

    $counts = audit_background_category_counts($pdo);
    return [
        'processed' => $processed,
        'batch' => $batchCounts,
        'counts' => $counts,
        'remaining' => (int)$counts['pending'],
        'completed' => (int)$counts['pending'] === 0,
        'failures' => array_slice($failures, 0, 5),
    ];
}


function run_photo_audit(PDO $pdo, int $limit = 0): array
{
    @set_time_limit(600);
    @ignore_user_abort(true);

    $sql = "SELECT matrik, nama, nokp, kuliah, praktikum
            FROM senarai
            WHERE UPPER(TRIM(COALESCE(status,''))) = 'AKTIF'
            ORDER BY matrik";

    $students = audit_keep_current_active_rows(audit_attach_pg_active($pdo->query($sql)->fetchAll()));
    if ($limit > 0) {
        $students = array_slice($students, 0, (int)$limit);
    }
    $save = $pdo->prepare("INSERT INTO student_photo_audit
        (matrik, nama, photo_exists, photo_url, http_code, error_message, checked_at, quality_status)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE
            nama = VALUES(nama),
            photo_exists = VALUES(photo_exists),
            photo_url = VALUES(photo_url),
            http_code = VALUES(http_code),
            error_message = VALUES(error_message),
            checked_at = NOW(),
            quality_status = CASE
                WHEN VALUES(photo_exists)=0 THEN 'tiada'
                WHEN student_photo_audit.quality_status='tiada' THEN NULL
                ELSE student_photo_audit.quality_status
            END");

    $total = 0;
    $exists = 0;
    $missing = 0;

    foreach ($students as $student) {
        $matrik = clean_matrik_audit((string)($student['matrik'] ?? ''));
        if ($matrik === '') {
            continue;
        }
        $result = mis_photo_check($matrik);
        $save->execute([
            $matrik,
            (string)($student['nama'] ?? ''),
            (int)$result['exists'],
            $result['url'],
            $result['code'],
            $result['error'],
            (int)$result['exists'] === 1 ? null : 'tiada',
        ]);
        $total++;
        if ((int)$result['exists'] === 1) {
            $exists++;
        } else {
            $missing++;
        }
    }

    return ['total' => $total, 'exists' => $exists, 'missing' => $missing];
}

function audit_set_quality(PDO $pdo, string $matrik, ?string $status, string $actor, ?string $reason = null): void
{
    $allowed = [null, 'baik', 'repair', 'upload_baru', 'tiada'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Status kualiti tidak sah.');
    }

    $stmt = $pdo->prepare("UPDATE student_photo_audit
        SET quality_status=?, quality_reason=?, quality_checked_at=?, quality_checked_by=?
        WHERE matrik=?");
    $stmt->execute([
        $status,
        $reason,
        $status === null ? null : date('Y-m-d H:i:s'),
        $status === null ? null : $actor,
        $matrik,
    ]);

    if ($status === 'upload_baru') {
        audit_reset_whatsapp(
            $pdo,
            $matrik,
            'upload_baru',
            trim((string)($reason ?? 'Pelajar perlu memuat naik gambar baharu.')),
            'photo_audit'
        );
    }
}

function audit_queue_repair_from_mis(PDO $pdo, string $matrik, string $actor): array
{
    @set_time_limit(120);
    $matrik = clean_matrik_audit($matrik);
    if ($matrik === '') {
        throw new RuntimeException('No matrik tidak sah.');
    }

    $studentStmt = $pdo->prepare("SELECT matrik, nama, nokp FROM senarai
        WHERE UPPER(matrik)=UPPER(?) AND UPPER(TRIM(COALESCE(status,'')))='AKTIF' LIMIT 1");
    $studentStmt->execute([$matrik]);
    $student = $studentStmt->fetch();
    if (!$student) {
        throw new RuntimeException('Pelajar aktif tidak ditemui dalam table senarai.');
    }

    $existingStmt = $pdo->prepare("SELECT * FROM student_photo_uploads WHERE UPPER(matrik)=UPPER(?) LIMIT 1");
    $existingStmt->execute([$matrik]);
    $existing = $existingStmt->fetch();
    if ($existing) {
        $sourceLabel = (string)($existing['original_filename'] ?? '');
        if ($sourceLabel !== '' && stripos($sourceLabel, 'MIS:') !== 0) {
            throw new RuntimeException('Pelajar sudah upload gambar baru. Buka Semakan Upload supaya gambar pelajar tidak ditimpa.');
        }
    }

    $mis = mis_photo_fetch_jpeg($matrik);
    if (!$mis) {
        throw new RuntimeException('Gambar MIS tidak dapat dimuat turun untuk repair.');
    }

    $filesDir = dirname(__DIR__) . '/upload/files';
    $dirs = zurie_photo_ensure_directories($filesDir);
    $savedName = $matrik . '.jpg';
    $originalRel = 'original/' . $savedName;
    $repairedRel = 'repaired/' . $savedName;
    $originalPath = $dirs['original'] . DIRECTORY_SEPARATOR . $savedName;
    $repairedPath = $dirs['repaired'] . DIRECTORY_SEPARATOR . $savedName;
    $legacyPath = $filesDir . DIRECTORY_SEPARATOR . $savedName;

    if (@file_put_contents($originalPath, $mis['bytes']) === false) {
        throw new RuntimeException('Gagal menyimpan gambar asal MIS. Semak permission upload/files/original.');
    }
    @chmod($originalPath, 0644);
    @unlink($repairedPath);
    @unlink($legacyPath);

    $repair = zurie_photo_repair($originalPath, $repairedPath);
    if (!$repair['ok']) {
        throw new RuntimeException('Auto repair gagal: ' . $repair['message']);
    }
    if (!zurie_photo_publish_legacy($repairedPath, $legacyPath)) {
        throw new RuntimeException('Repair siap tetapi gagal menyediakan fail utama.');
    }

    $upsert = $pdo->prepare("INSERT INTO student_photo_uploads
        (matrik, nokp, nama, filename, original_filename, original_file, repaired_file,
         file_size, repair_status, repair_message, repaired_at, status, uploaded_at,
         reviewed_at, reviewed_by, reject_reason, sync_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'siap', ?, NOW(), 'baru', NOW(), NULL, NULL, NULL, 'belum')
        ON DUPLICATE KEY UPDATE
            nokp=VALUES(nokp), nama=VALUES(nama), filename=VALUES(filename),
            original_filename=VALUES(original_filename), original_file=VALUES(original_file),
            repaired_file=VALUES(repaired_file), file_size=VALUES(file_size),
            repair_status='siap', repair_message=VALUES(repair_message), repaired_at=NOW(),
            status='baru', uploaded_at=NOW(), reviewed_at=NULL, reviewed_by=NULL,
            reject_reason=NULL, sync_status='belum', sync_message=NULL, synced_at=NULL,
            synced_by=NULL, sync_remote_file=NULL");
    $upsert->execute([
        $matrik,
        (string)($student['nokp'] ?? ''),
        (string)($student['nama'] ?? ''),
        $savedName,
        'MIS:' . $mis['url'],
        $originalRel,
        $repairedRel,
        (int)($repair['size'] ?? filesize($repairedPath)),
        (string)$repair['message'],
    ]);

    audit_set_quality($pdo, $matrik, 'repair', $actor, 'Gambar MIS dihantar ke auto repair dan Semakan Upload.');

    return [
        'matrik' => $matrik,
        'message' => 'Auto repair siap dan dihantar ke Semakan Upload.',
    ];
}

function audit_clean_matrik_list(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $result = [];
    foreach ($raw as $value) {
        $matrik = clean_matrik_audit((string)$value);
        if ($matrik !== '') {
            $result[$matrik] = $matrik;
        }
    }
    return array_values($result);
}

function stat_int(array $stats, string $key): int
{
    return (int)($stats[$key] ?? 0);
}

function audit_wa_phone(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '60') === 0) {
        return $digits;
    }
    if (strpos($digits, '0') === 0) {
        return '6' . $digits;
    }
    if (strpos($digits, '1') === 0) {
        return '60' . $digits;
    }
    return $digits;
}

/**
 * Tentukan sama ada pelajar masih perlu dihubungi melalui WhatsApp.
 * Rekod upload baharu/lulus tidak lagi dianggap perlu WA. Rekod ditolak sentiasa perlu WA.
 *
 * @return array{needed:bool,type:string,label:string,note:string}
 */
function audit_whatsapp_context(array $row): array
{
    $checked = trim((string)($row['checked_at'] ?? '')) !== '';
    $exists = (int)($row['photo_exists'] ?? 0) === 1;
    $quality = strtolower(trim((string)($row['quality_status'] ?? '')));
    $uploadStatus = strtolower(trim((string)($row['upload_status'] ?? '')));
    $reason = trim((string)($row['reject_reason'] ?? ''));
    $note = trim((string)($row['reject_note'] ?? ''));

    if ($uploadStatus === 'tolak') {
        $detail = $reason !== '' ? $reason : 'Gambar yang dimuat naik tidak memenuhi spesifikasi.';
        if ($note !== '') {
            $detail .= ' | ' . $note;
        }
        return ['needed' => true, 'type' => 'tolak', 'label' => 'WhatsApp Tolak', 'note' => $detail];
    }

    if (in_array($uploadStatus, ['baru', 'lulus', 'pending_registration'], true)) {
        return ['needed' => false, 'type' => '', 'label' => '', 'note' => ''];
    }

    if ($quality === 'upload_baru') {
        return [
            'needed' => true,
            'type' => 'upload_baru',
            'label' => 'WA Upload Baru',
            'note' => trim((string)($row['quality_reason'] ?? 'Gambar MIS perlu diganti.')),
        ];
    }

    if ($checked && !$exists) {
        return [
            'needed' => true,
            'type' => 'tiada_gambar',
            'label' => 'WA Tiada Gambar',
            'note' => 'Tiada gambar pelajar dalam MIS.',
        ];
    }

    return ['needed' => false, 'type' => '', 'label' => '', 'note' => ''];
}

function audit_reset_whatsapp(PDO $pdo, string $matrik, string $type, string $note, string $source = 'photo_audit'): void
{
    $matrik = clean_matrik_audit($matrik);
    if ($matrik === '') {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO student_photo_audit
        (matrik, whatsapp_sent, whatsapp_sent_at, whatsapp_type, whatsapp_note, whatsapp_source, whatsapp_sent_by)
        VALUES (?, 0, NULL, ?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE whatsapp_sent=0, whatsapp_sent_at=NULL,
            whatsapp_type=VALUES(whatsapp_type), whatsapp_note=VALUES(whatsapp_note),
            whatsapp_source=VALUES(whatsapp_source), whatsapp_sent_by=NULL");
    $stmt->execute([$matrik, $type, $note !== '' ? $note : null, $source]);
}

function audit_record_whatsapp(PDO $pdo, string $matrik, bool $sent, string $type, string $note, string $source, string $actor): void
{
    $matrik = clean_matrik_audit($matrik);
    if ($matrik === '') {
        throw new RuntimeException('No matrik tidak sah untuk rekod WhatsApp.');
    }

    if ($sent) {
        $stmt = $pdo->prepare("INSERT INTO student_photo_audit
            (matrik, whatsapp_sent, whatsapp_sent_at, whatsapp_type, whatsapp_note, whatsapp_source, whatsapp_sent_by)
            VALUES (?, 1, NOW(), ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE whatsapp_sent=1, whatsapp_sent_at=NOW(),
                whatsapp_type=VALUES(whatsapp_type), whatsapp_note=VALUES(whatsapp_note),
                whatsapp_source=VALUES(whatsapp_source), whatsapp_sent_by=VALUES(whatsapp_sent_by)");
        $stmt->execute([$matrik, $type !== '' ? $type : null, $note !== '' ? $note : null, $source, $actor]);
        return;
    }

    $stmt = $pdo->prepare("UPDATE student_photo_audit
        SET whatsapp_sent=0, whatsapp_sent_at=NULL, whatsapp_sent_by=NULL
        WHERE matrik=?");
    $stmt->execute([$matrik]);
}

function audit_upload_message(array $row): string
{
    $matrik = (string)($row['matrik'] ?? '');
    $nama = (string)($row['nama'] ?? '');
    $quality = (string)($row['quality_status'] ?? '');
    $nameLine = $nama !== '' ? "Nama: {$nama}\n" : '';

    if ($quality === 'upload_baru') {
        $intro = "Tuan/Puan dimohon untuk MEMUAT NAIK SEMULA gambar bagi tujuan pendaftaran online kerana gambar sedia ada tidak menepati spesifikasi yang ditetapkan.";
    } else {
        $intro = "Tuan/Puan didapati masih belum mempunyai gambar profil yang lengkap bagi tujuan pendaftaran online dan cetakan Kad Matrik Kolej Matrikulasi Perlis.";
    }

    return "Assalamualaikum dan Salam Sejahtera.\n\n" .
        "MAKLUMAN KEMASKINI GAMBAR PROFIL PENDAFTARAN ({$matrik})\n\n" .
        $nameLine .
        $intro . "\n\n" .
        "Untuk makluman, gambar ini akan digunakan untuk CETAKAN KAD MATRIK. Sila pastikan gambar yang dimuat naik:\n" .
        "• jelas dan tidak pecah\n" .
        "• berkualiti tinggi\n" .
        "• menunjukkan wajah dengan jelas\n" .
        "• berlatar belakang yang sesuai\n" .
        "• mengikut format yang ditetapkan\n\n" .
        "Contoh gambar boleh dirujuk di:\n" .
        "http://mis.kmp.matrik.edu.my/online/contoh_gambar.php\n\n" .
        "Sila kemaskini segera melalui pautan berikut:\n" .
        "http://www.kmp.matrik.edu.my/zurie/upload/\n\n" .
        "Kegagalan berbuat demikian boleh menyebabkan Kad Matrik tidak dapat diproses atau dikeluarkan.\n\n" .
        "Abaikan mesej ini sekiranya tindakan kemaskini telah dibuat.\n\n" .
        "Terima kasih.\n\n" .
        "Unit Teknologi Maklumat\n" .
        "Kolej Matrikulasi Perlis";
}

function audit_wa_url(array $row): string
{
    $phone = audit_wa_phone((string)($row['nohp'] ?? ''));
    if ($phone === '') {
        return '';
    }
    return 'https://wa.me/' . rawurlencode($phone) . '?text=' . rawurlencode(audit_upload_message($row));
}

function audit_reject_message(array $row): string
{
    $matrik = (string)($row['matrik'] ?? '');
    $nama = (string)($row['nama'] ?? '');
    $reason = trim((string)($row['reject_reason'] ?? ''));
    $note = trim((string)($row['reject_note'] ?? ''));
    if ($reason === '') {
        $reason = 'Tidak memenuhi spesifikasi gambar yang ditetapkan.';
    }
    $nameLine = $nama !== '' ? "Nama: {$nama}
" : '';
    $noteBlock = $note !== '' ? "
Catatan penyemak:
{$note}
" : '';

    return "Assalamualaikum dan Salam Sejahtera.

" .
        "MAKLUMAN SEMAKAN GAMBAR PROFIL PENDAFTARAN ({$matrik})

" .
        $nameLine .
        "Gambar profil yang telah dimuat naik bagi tujuan pendaftaran online dan cetakan Kad Matrik Kolej Matrikulasi Perlis telah disemak.

" .
        "Dukacita dimaklumkan bahawa gambar tersebut tidak dapat diluluskan atas sebab berikut:
" .
        $reason . "
" .
        $noteBlock . "
" .
        "Sehubungan itu, tuan/puan dimohon memuat naik gambar baharu yang memenuhi syarat berikut:
" .
        "• jelas dan tidak pecah
" .
        "• berkualiti tinggi
" .
        "• menunjukkan wajah dengan jelas
" .
        "• berlatar belakang yang sesuai
" .
        "• mengikut format yang ditetapkan

" .
        "Contoh gambar boleh dirujuk di:
" .
        "http://mis.kmp.matrik.edu.my/online/contoh_gambar.php

" .
        "Sila kemaskini melalui pautan berikut:
" .
        "http://www.kmp.matrik.edu.my/zurie/upload/

" .
        "Kegagalan mengemas kini gambar yang memenuhi spesifikasi boleh menyebabkan proses cetakan Kad Matrik tertangguh atau tidak dapat diproses.

" .
        "Abaikan mesej ini sekiranya gambar baharu telah dimuat naik selepas menerima makluman ini.

" .
        "Terima kasih.

" .
        "Unit Teknologi Maklumat
" .
        "Kolej Matrikulasi Perlis";
}

function audit_reject_wa_url(array $row): string
{
    $phone = audit_wa_phone((string)($row['nohp'] ?? ''));
    if ($phone === '') {
        return '';
    }
    return 'https://wa.me/' . rawurlencode($phone) . '?text=' . rawurlencode(audit_reject_message($row));
}

function quality_badge(?string $status): array
{
    return match ($status) {
        'baik' => ['BAIK', 'quality-good'],
        'repair' => ['PERLU REPAIR', 'quality-repair'],
        'upload_baru' => ['UPLOAD BARU', 'quality-upload'],
        'tiada' => ['TIADA GAMBAR', 'quality-missing'],
        default => ['BELUM NILAI', 'quality-pending'],
    };
}

$messages = [];
$errors = [];
$filter = (string)($_GET['filter'] ?? 'attention');
$filterAliases = [
    'card' => 'rfid',
    'review_all' => 'repair',
    'review' => 'repair',
    'completed' => 'all',
];
$filter = $filterAliases[$filter] ?? $filter;
$allowedFilters = [
    'attention', 'missing', 'repair', 'rfid', 'all',
    // Kekalkan penapis teknikal lama untuk pautan/bookmark sedia ada.
    'quality_pending', 'good', 'upload_new',
    'bg_pending', 'bg_ok', 'bg_review', 'bg_reject', 'bg_failed',
    'wa_pending', 'wa_sent', 'exists', 'unchecked',
];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'attention';
}
$search = trim(substr((string)($_GET['q'] ?? ''), 0, 120));
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = 1;
$offset = 0;
$studentFilters = [
    'jantina' => trim(substr((string)($_GET['jantina'] ?? ''), 0, 100)),
    'intake' => trim(substr((string)($_GET['intake'] ?? ''), 0, 30)),
    'praktikum' => trim(substr((string)($_GET['praktikum'] ?? ''), 0, 100)),
    'kuliah' => trim(substr((string)($_GET['kuliah'] ?? ''), 0, 100)),
    'jurusan' => trim(substr((string)($_GET['jurusan'] ?? ''), 0, 100)),
    'asrama' => trim(substr((string)($_GET['asrama'] ?? ''), 0, 100)),
];
$token = csrf_token_audit();
$rows = [];
$stats = [];
$filteredCount = 0;
$phoneColumn = null;
$activeSnapshot = [];
$activeReconciliation = [];
$photoVersionSnapshot = ['counts' => [], 'total' => 0, 'scanned_at' => ''];
$filterOptions = [
    'jantina' => [],
    'intake' => [],
    'praktikum' => [],
    'kuliah' => [],
    'jurusan' => [],
    'asrama' => [],
];
$isAjaxAudit = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';

try {
    $pdo = pdo_zurie_audit();
    ensure_photo_audit_table($pdo);
    ensure_upload_table_audit($pdo);
    zurie_pg_live_ensure_senarai_table($pdo);
    $phoneColumn = audit_pick_phone_column($pdo);
    $actor = actor_name_audit();
    $activeSnapshot = audit_pg_active_snapshot();
    $photoVersionSnapshot = audit_photo_version_snapshot();

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['download'] ?? '') === 'hep_missing_pdf') {
        audit_download_missing_pdf($pdo, $phoneColumn, $search, $actor, $studentFilters);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_audit();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'auto_background_batch') {
            header('Content-Type: application/json; charset=utf-8');
            try {
                $batchSize = max(1, min(10, (int)($_POST['batch_size'] ?? 10)));
                $result = audit_background_auto_batch($pdo, $batchSize, $actor);
                echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (Throwable $batchError) {
                http_response_code(422);
                echo json_encode([
                    'ok' => false,
                    'error' => $batchError->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            exit;
        } elseif ($action === 'reset_background_auto') {
            header('Content-Type: application/json; charset=utf-8');
            try {
                $result = audit_background_reset_for_recheck($pdo);
                echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (Throwable $resetError) {
                http_response_code(422);
                echo json_encode([
                    'ok' => false,
                    'error' => $resetError->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            exit;
        } elseif ($action === 'reconcile_active') {
            $result = audit_reconcile_active_students($pdo);
            $messages[] = 'Selaras pelajar aktif selesai. Disync/kemas kini: ' . $result['synced'] .
                ', rekod lama ditanda tidak aktif: ' . $result['stale'] .
                ', roster aktif unik: ' . $result['roster_count'] . '.';
        } elseif ($action === 'audit_all' || $action === 'audit_sample') {
            $result = run_photo_audit($pdo, $action === 'audit_sample' ? 50 : 0);
            $messages[] = 'Audit selesai. Disemak: ' . $result['total'] .
                ', Ada gambar: ' . $result['exists'] . ', Tiada gambar: ' . $result['missing'] . '.';
            $filter = 'attention';
        } elseif ($action === 'mark_whatsapp' || $action === 'unmark_whatsapp') {
            $matrik = clean_matrik_audit((string)($_POST['matrik'] ?? ''));
            if ($matrik === '') {
                throw new RuntimeException('No matrik tidak sah.');
            }
            $waType = trim(substr((string)($_POST['wa_type'] ?? ''), 0, 30));
            $waNote = trim(substr((string)($_POST['wa_note'] ?? ''), 0, 255));
            audit_record_whatsapp(
                $pdo,
                $matrik,
                $action === 'mark_whatsapp',
                $waType,
                $waNote,
                'photo_audit',
                $actor
            );
            if ($isAjaxAudit) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => true,
                    'matrik' => $matrik,
                    'status' => $action === 'mark_whatsapp' ? 'sent' : 'pending',
                    'type' => $waType,
                    'time' => $action === 'mark_whatsapp' ? date('Y-m-d H:i:s') : null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        } elseif (in_array($action, ['quality_good', 'quality_upload', 'quality_reset', 'quality_repair', 'background_check'], true)) {
            $matrik = clean_matrik_audit((string)($_POST['matrik'] ?? ''));
            if ($matrik === '') {
                throw new RuntimeException('No matrik tidak sah.');
            }
            if ($action === 'background_check') {
                $result = audit_background_check($pdo, $matrik, $actor);
                $messages[] = $matrik . ': ' . (string)($result['label'] ?? 'Analisis siap') .
                    ' (skor ' . number_format((float)($result['score'] ?? 0), 1) . '%). ' .
                    (string)($result['reason'] ?? '');
            } elseif ($action === 'quality_good') {
                audit_set_quality($pdo, $matrik, 'baik', $actor, 'Gambar MIS diterima tanpa perubahan.');
                $messages[] = $matrik . ' ditanda Gambar Baik.';
            } elseif ($action === 'quality_upload') {
                audit_set_quality($pdo, $matrik, 'upload_baru', $actor, 'Pelajar perlu memuat naik gambar baharu.');
                $messages[] = $matrik . ' ditanda Perlu Upload Baru.';
            } elseif ($action === 'quality_reset') {
                audit_set_quality($pdo, $matrik, null, $actor);
                $messages[] = 'Penilaian kualiti ' . $matrik . ' dibatalkan.';
            } else {
                $result = audit_queue_repair_from_mis($pdo, $matrik, $actor);
                $messages[] = $matrik . ': ' . $result['message'];
            }
        } elseif (in_array($action, ['bulk_good', 'bulk_upload', 'bulk_repair', 'bulk_background_check'], true)) {
            $matriks = audit_clean_matrik_list($_POST['matriks'] ?? []);
            if ($matriks === []) {
                throw new RuntimeException('Pilih sekurang-kurangnya satu pelajar.');
            }
            if (count($matriks) > 50) {
                throw new RuntimeException('Maksimum 50 rekod bagi satu tindakan pukal.');
            }
            if (in_array($action, ['bulk_repair', 'bulk_background_check'], true) && count($matriks) > 20) {
                throw new RuntimeException('Repair/analisis background pukal maksimum 20 gambar bagi satu pusingan.');
            }

            $success = 0;
            $failed = [];
            foreach ($matriks as $matrik) {
                try {
                    if ($action === 'bulk_good') {
                        audit_set_quality($pdo, $matrik, 'baik', $actor, 'Gambar MIS diterima tanpa perubahan.');
                    } elseif ($action === 'bulk_upload') {
                        audit_set_quality($pdo, $matrik, 'upload_baru', $actor, 'Pelajar perlu memuat naik gambar baharu.');
                    } elseif ($action === 'bulk_background_check') {
                        audit_background_check($pdo, $matrik, $actor);
                    } else {
                        audit_queue_repair_from_mis($pdo, $matrik, $actor);
                    }
                    $success++;
                } catch (Throwable $e) {
                    $failed[] = $matrik . ': ' . $e->getMessage();
                }
            }
            $messages[] = 'Tindakan pukal selesai. Berjaya: ' . $success . ', Gagal: ' . count($failed) . '.';
            if ($failed !== []) {
                $errors[] = implode(' | ', array_slice($failed, 0, 10));
            }
        } elseif ($action === 'clear_audit') {
            $pdo->exec("TRUNCATE TABLE student_photo_audit");
            $messages[] = 'Data audit gambar telah dikosongkan untuk batch baru.';
            $filter = 'attention';
        } elseif ($action === 'clear_audit_uploads') {
            $pdo->exec("TRUNCATE TABLE student_photo_audit");
            $pdo->exec("TRUNCATE TABLE student_photo_uploads");
            $messages[] = 'Data audit dan rekod upload telah dikosongkan. Fail gambar fizikal tidak dipadam.';
            $filter = 'attention';
        } else {
            throw new RuntimeException('Tindakan tidak dikenali.');
        }
    }

    $phoneSelect = $phoneColumn
        ? ', s.`' . str_replace('`', '', $phoneColumn) . '` AS nohp'
        : ", '' AS nohp";
    $baseSql = "SELECT
            s.matrik, s.nama, s.nokp, s.jantina, s.asrama,
            s.praktikum, s.kuliah, s.jurusan,
            s.stud_intake, s.stud_semester, s.stud_status" . $phoneSelect . ",
            a.photo_exists, a.photo_url, a.http_code, a.error_message, a.checked_at,
            a.quality_status, a.quality_reason, a.quality_checked_at, a.quality_checked_by,
            a.background_status, a.background_score, a.background_white_ratio,
            a.background_uniformity, a.background_brightness, a.background_color_ratio,
            a.background_shadow_ratio, a.background_dominant_color, a.background_dominant_hex,
            a.background_reason, a.background_checked_at, a.background_checked_by,
            a.whatsapp_sent, a.whatsapp_sent_at, a.whatsapp_type, a.whatsapp_note,
            a.whatsapp_source, a.whatsapp_sent_by,
            u.id AS upload_id, u.status AS upload_status, u.filename, u.original_filename,
            u.repair_status, u.sync_status, u.uploaded_at, u.reject_reason, u.reject_note
        FROM senarai s
        LEFT JOIN student_photo_audit a ON UPPER(a.matrik)=UPPER(s.matrik)
        LEFT JOIN student_photo_uploads u ON UPPER(u.matrik)=UPPER(s.matrik)
        WHERE UPPER(TRIM(COALESCE(s.status,'')))='AKTIF'
        ORDER BY s.matrik";

    $allActiveRows = audit_keep_current_active_rows(
        audit_attach_pg_active($pdo->query($baseSql)->fetchAll())
    );
    $activeReconciliation = audit_pg_reconciliation($pdo);
    $filterOptions = audit_filter_options_from_rows($allActiveRows);
    foreach (audit_pg_intake_options() as $pgIntake) {
        $normalized = audit_normalize_intake($pgIntake);
        if ($normalized !== '' && !in_array($normalized, $filterOptions['intake'], true)) {
            $filterOptions['intake'][] = $normalized;
        }
    }
    natsort($filterOptions['intake']);
    $filterOptions['intake'] = array_values(array_unique($filterOptions['intake']));
    $stats = audit_compute_stats($allActiveRows);

    $tabRows = array_values(array_filter($allActiveRows, static function (array $row) use ($filter): bool {
        $checked = trim((string)($row['checked_at'] ?? '')) !== '';
        $exists = (int)($row['photo_exists'] ?? 0) === 1;
        $quality = trim((string)($row['quality_status'] ?? ''));
        $waSent = (int)($row['whatsapp_sent'] ?? 0) === 1;
        $backgroundStatus = trim((string)($row['background_status'] ?? ''));
        $waContext = audit_whatsapp_context($row);

        $issues = audit_issue_flags($row);

        return match ($filter) {
            'attention' => $issues['missing'] || $issues['repair'] || $issues['rfid'],
            'missing' => $issues['missing'],
            'repair' => $issues['repair'],
            'rfid' => $issues['rfid'],
            'quality_pending' => $exists && $quality === '',
            'good' => $quality === 'baik',
            'upload_new' => $quality === 'upload_baru',
            'bg_pending' => $exists && $backgroundStatus === '',
            'bg_ok' => in_array($backgroundStatus, ['putih', 'hampir_putih'], true),
            'bg_review' => $backgroundStatus === 'semak',
            'bg_reject' => $backgroundStatus === 'tolak',
            'bg_failed' => $backgroundStatus === 'gagal',
            'wa_pending' => $waContext['needed'] && !$waSent,
            'wa_sent' => $waContext['needed'] && $waSent,
            'exists' => $exists,
            'unchecked' => !$checked,
            default => true,
        };
    }));

    $candidateRows = audit_filter_rows($tabRows, $studentFilters, $search);

    // Susunan lalai: skor background paling rendah dahulu.
    // Rekod yang belum mempunyai skor diletakkan selepas rekod yang telah dinilai.
    usort($candidateRows, static function (array $left, array $right): int {
        $leftRaw = $left['background_score'] ?? null;
        $rightRaw = $right['background_score'] ?? null;
        $leftHasScore = $leftRaw !== null && $leftRaw !== '' && is_numeric($leftRaw);
        $rightHasScore = $rightRaw !== null && $rightRaw !== '' && is_numeric($rightRaw);

        if ($leftHasScore && $rightHasScore) {
            $scoreCompare = (float)$leftRaw <=> (float)$rightRaw;
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }
        } elseif ($leftHasScore !== $rightHasScore) {
            return $leftHasScore ? -1 : 1;
        }

        $nameCompare = strnatcasecmp(
            trim((string)($left['nama'] ?? '')),
            trim((string)($right['nama'] ?? ''))
        );
        if ($nameCompare !== 0) {
            return $nameCompare;
        }

        return strnatcasecmp(
            trim((string)($left['matrik'] ?? '')),
            trim((string)($right['matrik'] ?? ''))
        );
    });

    $filteredCount = count($candidateRows);
    $totalPages = max(1, (int)ceil($filteredCount / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $rows = array_slice($candidateRows, $offset, $perPage);

} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

foreach ($rows as $row) {
    $rowIntake = audit_normalize_intake($row['stud_intake'] ?? '');
    if ($rowIntake !== '' && $rowIntake !== '-' && !in_array($rowIntake, $filterOptions['intake'], true)) {
        $filterOptions['intake'][] = $rowIntake;
    }
}
natsort($filterOptions['intake']);
$filterOptions['intake'] = array_values($filterOptions['intake']);

foreach ($studentFilters as $key => $selectedValue) {
    if ($selectedValue !== '' && isset($filterOptions[$key]) && !in_array($selectedValue, $filterOptions[$key], true)) {
        $filterOptions[$key][] = $selectedValue;
    }
}

$currentQueryParams = array_merge(['filter' => $filter, 'q' => $search, 'page' => $page], $studentFilters);
$currentQueryParams = array_filter(
    $currentQueryParams,
    static fn($value): bool => $value !== '' && $value !== null
);
$currentQueryString = http_build_query($currentQueryParams);
$filterSummary = audit_filter_summary($studentFilters, $search);
$hasAdvancedFilters = $filterSummary !== 'Semua rekod';
$pageStart = $filteredCount > 0 ? $offset + 1 : 0;
$pageEnd = min($offset + $perPage, $filteredCount);
$paginationBaseParams = array_merge(['filter' => $filter, 'q' => $search], $studentFilters);
$paginationBaseParams = array_filter($paginationBaseParams, static fn($value): bool => $value !== '');
$paginationUrl = static function (int $targetPage) use ($paginationBaseParams): string {
    $params = $paginationBaseParams;
    if ($targetPage > 1) {
        $params['page'] = $targetPage;
    }
    return '?' . http_build_query($params);
};
$paginationStart = max(1, $page - 2);
$paginationEnd = min($totalPages, $page + 2);

$tabUrls = [];
foreach ($allowedFilters as $tabKey) {
    $tabParams = array_merge(['filter' => $tabKey, 'q' => $search], $studentFilters);
    $tabParams = array_filter($tabParams, static fn($value): bool => $value !== '');
    $tabUrls[$tabKey] = '?' . http_build_query($tabParams);
}
$pdfParams = array_merge(
    ['filter' => 'missing', 'download' => 'hep_missing_pdf', 'q' => $search],
    $studentFilters
);
$pdfParams = array_filter($pdfParams, static fn($value): bool => $value !== '');
$pdfDownloadUrl = '?' . http_build_query($pdfParams);
$resetAdvancedUrl = '?filter=attention';
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Semakan Foto Kad Matrik | Zurie</title>
<style>
:root{--bg:#f5f7fb;--surface:#fff;--border:#e2e8f0;--text:#0f172a;--muted:#64748b;--navy:#173b67;--blue:#2563eb;--blue-soft:#eff6ff;--red:#b42318;--red-soft:#fff1f0;--amber:#9a6700;--amber-soft:#fff8e6;--green:#18794e;--green-soft:#ecfdf3;--purple:#6941c6;--purple-soft:#f4f3ff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",Arial,sans-serif}.wrap{max-width:1240px;margin:28px auto;padding:0 18px}.panel{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:16px;box-shadow:0 5px 18px rgba(15,23,42,.04)}.breadcrumb{display:flex;gap:7px;align-items:center;font-size:13px;margin-bottom:14px;color:var(--muted)}.breadcrumb a{color:var(--blue);font-weight:700;text-decoration:none}.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap}.page-title{margin:0 0 6px;font-size:27px;line-height:1.15;letter-spacing:-.02em}.subtitle{margin:0;color:var(--muted);font-size:14px}.head-actions,.inline-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.btn{appearance:none;border:0;border-radius:9px;padding:10px 13px;background:var(--blue);color:#fff;font-weight:750;font-size:13px;line-height:1;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px}.btn:hover{filter:brightness(.97)}.btn.secondary{background:#eef2f7;color:#1e293b}.btn.subtle{background:#fff;color:#334155;border:1px solid var(--border)}.btn.danger{background:#b42318}.btn.warn{background:#b45309}.btn.good{background:#15803d}.btn.repair{background:#6d28d9}.btn.upload{background:#c2410c}.btn.wa{background:#15803d}.btn.small{padding:7px 9px;font-size:11px}.alert{border-radius:11px;padding:11px 13px;margin-bottom:12px;font-size:13px}.alert.ok{background:var(--green-soft);color:var(--green);border:1px solid #bbf7d0}.alert.err{background:var(--red-soft);color:var(--red);border:1px solid #fecaca}.summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.summary-card{display:block;text-decoration:none;color:inherit;border:1px solid var(--border);border-radius:14px;padding:16px;background:#fff;transition:.15s}.summary-card:hover{transform:translateY(-1px);border-color:#a8b7ca}.summary-card.active{box-shadow:0 0 0 2px rgba(37,99,235,.12)}.summary-card span{display:block;color:var(--muted);font-size:12px;font-weight:700}.summary-card b{display:block;font-size:27px;margin:7px 0 3px}.summary-card small{color:var(--muted)}.summary-card.missing{border-top:3px solid #d92d20}.summary-card.review{border-top:3px solid #f59e0b}.summary-card.rfid{border-top:3px solid #2563eb}.progress-note{margin-top:13px;color:var(--muted);font-size:12px}.filters{display:grid;grid-template-columns:minmax(190px,.8fr) minmax(280px,1.5fr) minmax(170px,.7fr) auto auto;gap:10px;align-items:end}.field{display:flex;flex-direction:column;gap:6px}.field label{font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:800;color:#475569}.field input,.field select{width:100%;height:40px;border:1px solid #cbd5e1;border-radius:9px;padding:0 11px;background:#fff;color:var(--text)}.result-line{margin-top:12px;color:var(--muted);font-size:12px}.table-panel{padding:0;overflow:hidden}.table-scroll{overflow:auto}table{width:100%;border-collapse:collapse;min-width:860px}th{padding:12px 14px;background:#f8fafc;color:#475569;text-transform:uppercase;letter-spacing:.035em;font-size:11px;text-align:left;border-bottom:1px solid var(--border)}td{padding:14px;border-bottom:1px solid var(--border);vertical-align:top;font-size:13px}tbody tr:hover{background:#fbfdff}.photo{width:68px;height:86px;border-radius:10px;object-fit:cover;border:1px solid #cbd5e1;background:#f8fafc}.photo-empty{width:68px;height:86px;border-radius:10px;border:1px dashed #cbd5e1;background:#f8fafc;display:grid;place-items:center;color:#94a3b8;font-size:11px;text-align:center;padding:6px}.student-name{display:block;font-weight:800;margin-bottom:4px}.student-meta{display:block;color:var(--muted);font-size:11px;line-height:1.55}.student-version-link{display:inline-flex;align-items:center;gap:5px;margin-top:5px;padding:4px 7px;border-radius:7px;background:#eff6ff;color:#1d4ed8;text-decoration:none;font-size:10px;font-weight:800;border:1px solid #dbeafe}.student-version-link:hover{background:#dbeafe}.student-version-link.empty{background:#f8fafc;color:#64748b;border-color:#e2e8f0}.state{display:inline-flex;align-items:center;border-radius:999px;padding:6px 9px;font-size:11px;font-weight:800;white-space:nowrap}.state-missing{background:var(--red-soft);color:var(--red)}.state-review{background:var(--amber-soft);color:var(--amber)}.state-upload{background:var(--blue-soft);color:#1d4ed8}.state-rfid{background:var(--blue-soft);color:#1d4ed8}.state-completed{background:var(--green-soft);color:var(--green)}.issue-list{display:flex;gap:6px;flex-wrap:wrap}.reason-list{display:grid;gap:5px;max-width:360px;line-height:1.45}.reason-item{display:flex;gap:7px;align-items:flex-start}.reason-item:before{content:"•";color:#94a3b8;font-weight:900}.card-lines{display:grid;gap:5px}.card-line{display:flex;align-items:center;gap:6px}.card-line strong{font-size:11px;min-width:54px;color:#475569}.value-ok{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:11px;color:#166534}.value-missing{font-size:11px;color:var(--red);font-weight:750}.row-actions{display:flex;gap:7px;flex-wrap:wrap;align-items:flex-start}.row-details,.row-more{position:relative}.row-details>summary,.row-more>summary{cursor:pointer;list-style:none;border-radius:8px;padding:7px 9px;background:#f1f5f9;color:#334155;font-size:11px;font-weight:800}.row-details>summary::-webkit-details-marker,.row-more>summary::-webkit-details-marker{display:none}.details-box,.more-box{margin-top:7px;padding:10px;border:1px solid var(--border);border-radius:10px;background:#f8fafc;min-width:230px;color:#475569;font-size:11px;line-height:1.6}.more-box{display:flex;gap:6px;flex-wrap:wrap}.more-box button{margin:0}.muted{color:var(--muted)}.settings{padding:0;overflow:hidden}.settings>summary{cursor:pointer;list-style:none;padding:16px 20px;font-weight:800;color:var(--navy);display:flex;justify-content:space-between;align-items:center}.settings>summary::-webkit-details-marker{display:none}.settings>summary:after{content:'Buka';font-size:11px;color:var(--blue);background:var(--blue-soft);padding:5px 8px;border-radius:999px}.settings[open]>summary:after{content:'Tutup'}.settings-body{padding:0 20px 20px;border-top:1px solid var(--border)}.settings-section{padding-top:17px}.settings-section+.settings-section{margin-top:17px;border-top:1px solid var(--border)}.settings-section h3{margin:0 0 5px;font-size:15px}.settings-section p{margin:0 0 12px;color:var(--muted);font-size:12px}.source-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:12px}.source-item{border:1px solid var(--border);border-radius:10px;padding:10px;background:#f8fafc}.source-item span{display:block;color:var(--muted);font-size:10px}.source-item b{font-size:19px}.guide-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.guide-item{border:1px solid var(--border);border-radius:10px;padding:10px;font-size:12px;color:#475569}.auto-progress{margin-top:12px;padding:12px;border:1px solid #bfdbfe;background:#fff;border-radius:11px}.auto-progress[hidden]{display:none}.progress-head{display:flex;justify-content:space-between;gap:8px;margin-bottom:8px;font-size:12px}.progress-track{height:9px;border-radius:999px;background:#e2e8f0;overflow:hidden}.progress-fill{height:100%;width:0;background:#2563eb;transition:width .2s}.auto-counts{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-top:9px}.auto-count{padding:7px;border-radius:8px;text-align:center;background:#f8fafc;font-size:10px}.auto-count b{display:block;font-size:15px;margin-top:2px}.pagination-wrap{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;padding:15px 18px}.pagination{display:flex;gap:5px;align-items:center;flex-wrap:wrap}.page-link{display:inline-flex;min-width:32px;height:32px;align-items:center;justify-content:center;padding:0 8px;border-radius:8px;background:#e2e8f0;color:#0f172a;text-decoration:none;font-weight:800;font-size:12px}.page-link.active{background:var(--blue);color:#fff}.page-link.disabled{opacity:.4;pointer-events:none}.small{font-size:11px;color:var(--muted)}.btn.small{min-height:32px;padding:8px 10px;font-family:Inter,"Segoe UI",Arial,sans-serif;font-size:12px;font-weight:800;line-height:1.15;letter-spacing:0;color:#fff;opacity:1;text-shadow:none;white-space:nowrap}.btn.secondary.small,.btn.subtle.small{color:#0f172a}.more-box .btn{font-family:Inter,"Segoe UI",Arial,sans-serif}.wa-line{display:flex;gap:5px;align-items:center;margin-top:6px}.wa-box{width:17px;height:17px;border:1px solid #94a3b8;border-radius:4px;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;color:#16a34a}.wa-box.sent{border-color:#16a34a;background:#dcfce7}.wa-cancel{border:0;background:#fee2e2;color:#991b1b;border-radius:999px;width:17px;height:17px;cursor:pointer;font-weight:900;font-size:11px;padding:0}.wa-time{font-size:10px;color:#64748b}
@media(max-width:900px){.summary-grid{grid-template-columns:1fr}.filters{grid-template-columns:1fr 1fr}.filters .field:nth-child(2){grid-column:span 2}.source-row,.guide-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:620px){.wrap{padding:0 10px;margin:14px auto}.panel{padding:15px}.page-title{font-size:23px}.filters{grid-template-columns:1fr}.filters .field:nth-child(2){grid-column:span 1}.filters .btn{width:100%}.source-row,.guide-row,.auto-counts{grid-template-columns:1fr 1fr}.head-actions{width:100%}.head-actions .btn{flex:1}}
</style>
</head>
<body>
<div class="wrap">
    <section class="panel">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="/zurie/">Dashboard</a><span>›</span><strong>Foto Kad Matrik</strong></nav>
        <div class="page-head">
            <div>
                <h1 class="page-title">Semakan Foto Kad Matrik</h1>
                <p class="subtitle">Fokus kepada gambar tiada dalam MIS, RFID belum didaftarkan dan gambar yang perlu repair.</p>
            </div>
            <div class="head-actions">
                <form method="post" onsubmit="return confirm('Jalankan audit semua gambar pelajar aktif sekarang? Proses ini mungkin mengambil sedikit masa.');">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <button class="btn" type="submit" name="action" value="audit_all">Jalankan Audit Foto</button>
                </form>
                <a class="btn secondary" href="/zurie/upload/" target="_blank" rel="noopener">Borang Upload</a>
                <a class="btn subtle" href="#adminSettings" onclick="document.getElementById('adminSettings').open=true">Tetapan</a>
            </div>
        </div>
    </section>

    <?php foreach ($messages as $message): ?><div class="alert ok"><?= h($message) ?></div><?php endforeach; ?>
    <?php foreach ($errors as $error): ?><div class="alert err"><?= h($error) ?></div><?php endforeach; ?>

    <section class="panel">
        <div class="summary-grid">
            <a class="summary-card missing <?= $filter === 'missing' ? 'active' : '' ?>" href="<?= h($tabUrls['missing']) ?>">
                <span>Tiada Gambar dalam MIS</span><b><?= number_format(stat_int($stats, 'simple_missing')) ?></b><small>Gambar pelajar tidak ditemui</small>
            </a>
            <a class="summary-card rfid <?= $filter === 'rfid' ? 'active' : '' ?>" href="<?= h($tabUrls['rfid']) ?>">
                <span>Belum Daftar RFID</span><b><?= number_format(stat_int($stats, 'simple_rfid')) ?></b><small>Kiraan ikut rekod RFID MIS</small>
            </a>
            <a class="summary-card review <?= $filter === 'repair' ? 'active' : '' ?>" href="<?= h($tabUrls['repair']) ?>">
                <span>Gambar Perlu Repair</span><b><?= number_format(stat_int($stats, 'simple_repair')) ?></b><small>Gambar memerlukan pembaikan</small>
            </a>
        </div>
        <div class="progress-note">Pelajar aktif: <b><?= number_format(stat_int($stats, 'aktif')) ?></b> &nbsp;•&nbsp; Tanpa isu utama: <b><?= number_format(stat_int($stats, 'simple_completed')) ?></b> &nbsp;•&nbsp; Ada isu: <b><?= number_format(stat_int($stats, 'simple_attention')) ?></b></div>
    </section>

    <section class="panel">
        <form method="get" class="filters">
            <div class="field">
                <label for="statusFilter">Status</label>
                <select id="statusFilter" name="filter">
                    <option value="attention" <?= $filter === 'attention' ? 'selected' : '' ?>>Semua Isu</option>
                    <option value="missing" <?= $filter === 'missing' ? 'selected' : '' ?>>Tiada Gambar dalam MIS</option>
                    <option value="rfid" <?= $filter === 'rfid' ? 'selected' : '' ?>>Belum Daftar RFID</option>
                    <option value="repair" <?= $filter === 'repair' ? 'selected' : '' ?>>Gambar Perlu Repair</option>
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>Semua Pelajar</option>
                </select>
            </div>
            <div class="field">
                <label for="searchStudent">Carian</label>
                <input id="searchStudent" type="text" name="q" value="<?= h($search) ?>" placeholder="Nama atau nombor matrik">
            </div>
            <div class="field">
                <label for="praktikumFilter">Praktikum</label>
                <select id="praktikumFilter" name="praktikum">
                    <option value="">Semua</option>
                    <?php foreach ($filterOptions['praktikum'] as $option): ?>
                        <option value="<?= h($option) ?>" <?= $studentFilters['praktikum'] === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn" type="submit">Cari</button>
            <a class="btn secondary" href="?filter=attention">Reset</a>
        </form>
        <div class="result-line">Memaparkan <?= number_format($pageStart) ?>–<?= number_format($pageEnd) ?> daripada <?= number_format($filteredCount) ?> rekod. Susunan lalai: skor background terendah dahulu.</div>
    </section>

    <details class="panel settings" id="adminSettings">
        <summary>Tetapan dan alat pentadbir</summary>
        <div class="settings-body">
            <div class="settings-section">
                <h3>Sumber pelajar aktif</h3>
                <p>Roster PostgreSQL sesi semasa menjadi rujukan utama. Tetapan teknikal disimpan di sini supaya tidak mengganggu kerja harian.</p>
                <div class="inline-actions">
                    <a class="btn subtle" href="/zurie/pages/pg_live_lookup_setup.php">PostgreSQL</a>
                    <a class="btn subtle" href="/zurie/pages/mis_sftp_setup.php">SFTP MIS</a>
                    <a class="btn subtle" href="/zurie/pages/upload_review.php">Semakan Upload</a>
                    <a class="btn subtle" href="/zurie/pages/photo_versions.php">Versi Gambar (<?= number_format((int)($photoVersionSnapshot['total'] ?? 0)) ?>)</a>
                </div>
                <?php if (!empty($activeReconciliation['ready'])): ?>
                    <div class="source-row">
                        <div class="source-item"><span>Roster aktif</span><b><?= number_format((int)($activeReconciliation['roster_count'] ?? 0)) ?></b></div>
                        <div class="source-item"><span>Aktif dalam Zurie</span><b><?= number_format((int)($activeReconciliation['matched'] ?? 0)) ?></b></div>
                        <div class="source-item"><span>Belum diselaraskan</span><b><?= number_format((int)($activeReconciliation['missing_local'] ?? 0)) ?></b></div>
                        <div class="source-item"><span>Rekod lama aktif</span><b><?= number_format((int)($activeReconciliation['stale_local'] ?? 0)) ?></b></div>
                    </div>
                    <form method="post" class="inline-actions" style="margin-top:10px" onsubmit="return confirm('Selaras roster aktif PostgreSQL dengan senarai Zurie? Rekod audit dan upload tidak dipadam.');">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <button class="btn secondary" type="submit" name="action" value="reconcile_active">Selaras Pelajar Aktif</button>
                    </form>
                <?php else: ?>
                    <div class="alert err" style="margin-top:12px">PostgreSQL tidak tersedia. <?= h((string)($activeReconciliation['error'] ?? $activeSnapshot['error'] ?? '')) ?></div>
                <?php endif; ?>
            </div>

            <div class="settings-section">
                <h3>Analisis gambar automatik</h3>
                <p>Skor 50% dan ke atas diterima. Keputusan yang meragukan kekal dalam senarai Perlu Semakan.</p>
                <div class="inline-actions">
                    <button type="button" class="btn" id="autoBgStart" data-pending="<?= stat_int($stats, 'bg_pending') ?>">Nilai Gambar Belum Dinilai</button>
                    <button type="button" class="btn secondary" id="autoBgRecheck">Nilai Semula Semua</button>
                    <button type="button" class="btn secondary" id="autoBgStop" hidden>Henti selepas batch</button>
                </div>
                <div class="auto-progress" id="autoBgProgress" hidden>
                    <div class="progress-head"><strong id="autoBgStatus">Menyediakan batch…</strong><span id="autoBgPercent">0%</span></div>
                    <div class="progress-track"><div class="progress-fill" id="autoBgFill"></div></div>
                    <div class="auto-counts">
                        <div class="auto-count"><span>Belum</span><b id="autoCountPending"><?= stat_int($stats,'bg_pending') ?></b></div>
                        <div class="auto-count"><span>Diterima</span><b id="autoCountAccepted"><?= stat_int($stats,'bg_ok') ?></b></div>
                        <div class="auto-count"><span>Semak</span><b id="autoCountReview"><?= stat_int($stats,'bg_review') ?></b></div>
                        <div class="auto-count"><span>Ditolak</span><b id="autoCountRejected"><?= stat_int($stats,'bg_reject') ?></b></div>
                        <div class="auto-count"><span>Gagal</span><b id="autoCountFailed"><?= stat_int($stats,'bg_failed') ?></b></div>
                    </div>
                    <div class="small" id="autoBgLog" style="margin-top:8px">Jangan tutup tab sehingga proses selesai.</div>
                </div>
            </div>

            <div class="settings-section">
                <h3>Alat pentadbir</h3>
                <p>Gunakan fungsi ini hanya apabila perlu membuat audit semula, ujian atau reset batch.</p>
                <div class="inline-actions">
                    <form method="post" onsubmit="return confirm('Uji audit untuk 50 pelajar aktif?');">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <button class="btn secondary" type="submit" name="action" value="audit_sample">Uji 50 Pelajar</button>
                    </form>
                    <a class="btn secondary" href="<?= h($pdfDownloadUrl) ?>">Laporan Tiada Gambar</a>
                    <form method="post" onsubmit="return confirm('Kosongkan keputusan audit untuk batch baharu?');">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <button class="btn danger" type="submit" name="action" value="clear_audit">Reset Audit</button>
                    </form>
                </div>
            </div>

            <div class="settings-section">
                <h3>Panduan ringkas</h3>
                <div class="guide-row">
                    <div class="guide-item"><b>Tiada Gambar dalam MIS</b><br>Hubungi pelajar atau buka borang upload.</div>
                    <div class="guide-item"><b>Belum Daftar RFID</b><br>Semak pendaftaran kad RFID dalam MIS.</div>
                    <div class="guide-item"><b>Gambar Perlu Repair</b><br>Repair gambar atau minta pelajar upload semula.</div>
                </div>
            </div>
        </div>
    </details>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="matrik" id="singleMatrik" value="">
        <section class="panel table-panel">
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Foto</th><th>Pelajar</th><th>Isu Dikesan</th><th>Sebab</th><th>Tindakan</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Tiada rekod untuk paparan ini.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $row):
                        $matrik = (string)($row['matrik'] ?? '');
                        $exists = (int)($row['photo_exists'] ?? 0) === 1;
                        $proxyUrl = '/zurie/student_photo.php?nomatrik=' . rawurlencode($matrik);
                        $issues = audit_issue_flags($row);
                        $uploadStatus = strtolower(trim((string)($row['upload_status'] ?? '')));
                        $syncStatus = strtolower(trim((string)($row['sync_status'] ?? '')));
                        $backgroundStatus = strtolower(trim((string)($row['background_status'] ?? '')));
                        $waContext = audit_whatsapp_context($row);
                        $waUrl = $waContext['type'] === 'tolak' ? audit_reject_wa_url($row) : ($waContext['needed'] ? audit_wa_url($row) : '');
                        $waSent = (int)($row['whatsapp_sent'] ?? 0) === 1;
                        $cardNo = trim((string)($row['cardno'] ?? ''));
                        $rfidUid = trim((string)($row['em_cardno'] ?? ''));
                        $photoVersionCount = (int)($photoVersionSnapshot['counts'][$matrik] ?? 0);
                        $photoVersionUrl = '/zurie/pages/photo_versions.php?view=all&q=' . rawurlencode($matrik)
                            . '#pelajar-' . rawurlencode($matrik);
                        $photoVersionTitle = 'Buka versi gambar pelajar ini';
                        if (!empty($photoVersionSnapshot['scanned_at'])) {
                            $photoVersionTitle .= ' · Scan SFTP terakhir: ' . (string)$photoVersionSnapshot['scanned_at'];
                        }
                    ?>
                    <tr>
                        <td>
                            <?php if ($exists): ?><a href="<?= h($proxyUrl) ?>" target="_blank" rel="noopener"><img class="photo" src="<?= h($proxyUrl) ?>" alt="Foto <?= h($matrik) ?>" onerror="this.style.display='none'"></a>
                            <?php else: ?><div class="photo-empty">Tiada foto</div><?php endif; ?>
                        </td>
                        <td>
                            <span class="student-name"><?= h((string)($row['nama'] ?? '-')) ?></span>
                            <span class="student-meta"><?= h($matrik) ?></span>
                            <span class="student-meta"><?= h((string)($row['praktikum'] ?? '-')) ?> · <?= h((string)($row['jurusan'] ?? '-')) ?></span>
                            <a class="student-version-link <?= $photoVersionCount < 1 ? 'empty' : '' ?>" href="<?= h($photoVersionUrl) ?>" title="<?= h($photoVersionTitle) ?>">
                                <?= number_format($photoVersionCount) ?> gambar di SFTP ↗
                            </a>
                        </td>
                        <td>
                            <div class="issue-list">
                                <?php if ($issues['missing']): ?><span class="state state-missing">Tiada Gambar</span><?php endif; ?>
                                <?php if ($issues['rfid']): ?><span class="state state-rfid">Belum Daftar RFID</span><?php endif; ?>
                                <?php if ($issues['repair']): ?><span class="state state-review">Perlu Repair</span><?php endif; ?>
                                <?php if (!$issues['missing'] && !$issues['rfid'] && !$issues['repair']): ?><span class="state state-completed">Tiada Isu</span><?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="reason-list">
                                <?php foreach ($issues['reasons'] as $issueReason): ?><div class="reason-item"><?= h((string)$issueReason) ?></div><?php endforeach; ?>
                                <?php if ($issues['reasons'] === []): ?><span class="muted">Tiada tindakan diperlukan.</span><?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="row-actions">
                                <?php if ($issues['missing']): ?>
                                    <?php if ($waUrl !== ''): ?><a class="btn wa small" target="_blank" rel="noopener" href="<?= h($waUrl) ?>" onclick='markWaSent(<?= json_encode($matrik, JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= json_encode((string)$waContext['type'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= json_encode((string)$waContext['note'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Hubungi Pelajar</a>
                                    <?php else: ?><a class="btn secondary small" href="/zurie/upload/" target="_blank" rel="noopener">Borang Upload</a><?php endif; ?>
                                <?php elseif ($issues['repair']): ?>
                                    <button class="btn repair small" type="submit" name="action" value="quality_repair" formaction="?<?= h($currentQueryString) ?>" onclick="if(!confirm('Auto repair gambar <?= h($matrik) ?>?'))return false;this.form.querySelector('#singleMatrik').value='<?= h($matrik) ?>'">Repair</button>
                                <?php elseif ($issues['rfid']): ?>
                                    <details class="row-details"><summary>Semak RFID</summary><div class="details-box">Kad RFID belum didaftarkan dalam MIS. Buka modul Register RFID Kad Matrik untuk tindakan lanjut.</div></details>
                                <?php elseif ($exists): ?>
                                    <a class="btn secondary small" href="<?= h($proxyUrl) ?>" target="_blank" rel="noopener">Lihat Foto</a>
                                <?php endif; ?>

                                <details class="row-details">
                                    <summary>Butiran</summary>
                                    <div class="details-box">
                                        Audit: <?= h((string)($row['checked_at'] ?? 'Belum')) ?><br>
                                        Kualiti: <?= h((string)($row['quality_status'] ?? 'Belum dinilai')) ?><br>
                                        Latar: <?= h((string)($row['background_status'] ?? 'Belum dinilai')) ?>
                                        <?php if ($row['background_score'] !== null): ?><br>Skor: <?= number_format((float)$row['background_score'], 1) ?>%<?php endif; ?>
                                        <?php if ($uploadStatus !== ''): ?><br>Upload: <?= h(strtoupper($uploadStatus)) ?> · Sync: <?= h(strtoupper($syncStatus !== '' ? $syncStatus : 'BELUM')) ?><?php endif; ?>
                                        <br>No. Kad: <?= $cardNo !== '' ? h($cardNo) : 'Belum direkod' ?>
                                        <br>UID RFID: <?= $rfidUid !== '' ? h($rfidUid) : 'Belum direkod' ?>
                                    </div>
                                </details>

                                <?php if ($exists): ?>
                                    <details class="row-more">
                                        <summary>Tindakan</summary>
                                        <div class="more-box">
                                            <button class="btn secondary small" type="submit" name="action" value="background_check" formaction="?<?= h($currentQueryString) ?>" onclick="this.form.querySelector('#singleMatrik').value='<?= h($matrik) ?>'">Semak BG</button>
                                            <button class="btn good small" type="submit" name="action" value="quality_good" formaction="?<?= h($currentQueryString) ?>" onclick="this.form.querySelector('#singleMatrik').value='<?= h($matrik) ?>'">Baik</button>
                                            <button class="btn repair small" type="submit" name="action" value="quality_repair" formaction="?<?= h($currentQueryString) ?>" onclick="if(!confirm('Auto repair gambar <?= h($matrik) ?>?'))return false;this.form.querySelector('#singleMatrik').value='<?= h($matrik) ?>'">Repair</button>
                                            <button class="btn upload small" type="submit" name="action" value="quality_upload" formaction="?<?= h($currentQueryString) ?>" onclick="this.form.querySelector('#singleMatrik').value='<?= h($matrik) ?>'">Minta Upload</button>
                                            <?php if (!empty($row['quality_status'])): ?><button class="btn secondary small" type="submit" name="action" value="quality_reset" formaction="?<?= h($currentQueryString) ?>" onclick="this.form.querySelector('#singleMatrik').value='<?= h($matrik) ?>'">Reset</button><?php endif; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </div>
                            <?php if ($waContext['needed']): ?>
                                <div class="wa-line">
                                    <span id="waBox_<?= h($matrik) ?>" class="wa-box <?= $waSent ? 'sent' : '' ?>"><?= $waSent ? '✓' : '' ?></span>
                                    <button type="button" id="waCancel_<?= h($matrik) ?>" class="wa-cancel" style="<?= $waSent ? '' : 'display:none' ?>" onclick="unmarkWaSent('<?= h($matrik) ?>')">×</button>
                                    <span id="waText_<?= h($matrik) ?>" class="wa-time"><?= $waSent ? 'WhatsApp direkod' : 'Belum WhatsApp' ?></span>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="pagination-wrap">
                    <div class="small">Halaman <?= number_format($page) ?> daripada <?= number_format($totalPages) ?></div>
                    <nav class="pagination" aria-label="Navigasi halaman">
                        <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= h($paginationUrl(max(1, $page - 1))) ?>">‹</a>
                        <?php if ($paginationStart > 1): ?><a class="page-link" href="<?= h($paginationUrl(1)) ?>">1</a><?php if ($paginationStart > 2): ?><span class="small">…</span><?php endif; ?><?php endif; ?>
                        <?php for ($pageNo = $paginationStart; $pageNo <= $paginationEnd; $pageNo++): ?><a class="page-link <?= $pageNo === $page ? 'active' : '' ?>" href="<?= h($paginationUrl($pageNo)) ?>"><?= $pageNo ?></a><?php endfor; ?>
                        <?php if ($paginationEnd < $totalPages): ?><?php if ($paginationEnd < $totalPages - 1): ?><span class="small">…</span><?php endif; ?><a class="page-link" href="<?= h($paginationUrl($totalPages)) ?>"><?= $totalPages ?></a><?php endif; ?>
                        <a class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= h($paginationUrl(min($totalPages, $page + 1))) ?>">›</a>
                    </nav>
                </div>
            <?php endif; ?>
        </section>
    </form>
</div>

<script>
const auditCsrf = <?= json_encode($token) ?>;
const selectAll = document.getElementById('selectAll');
if (selectAll) {
    selectAll.addEventListener('change', () => {
        document.querySelectorAll('.rowCheck').forEach(cb => cb.checked = selectAll.checked);
    });
}
function selectedCount() {
    return document.querySelectorAll('.rowCheck:checked').length;
}
function confirmBulk(message) {
    const count = selectedCount();
    if (count < 1) { alert('Pilih sekurang-kurangnya satu pelajar.'); return false; }
    return confirm(message + '\nJumlah dipilih: ' + count);
}
function auditPost(action, matrik, extra = {}) {
    const fd = new FormData();
    fd.append('csrf', auditCsrf);
    fd.append('action', action);
    fd.append('matrik', matrik);
    Object.keys(extra).forEach((key) => fd.append(key, extra[key] ?? ''));
    return fetch(location.pathname + location.search, {
        method: 'POST', body: fd,
        headers: {'X-Requested-With': 'fetch'},
        credentials: 'same-origin'
    }).then((response) => {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    });
}
let autoBgRunning = false;
let autoBgStopRequested = false;
let autoBgInitialPending = 0;
let autoBgProcessed = 0;

function autoBgSetText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = Number(value || 0).toLocaleString('ms-MY');
}
function autoBgUpdateCounts(counts) {
    if (!counts) return;
    autoBgSetText('autoCountPending', counts.pending);
    autoBgSetText('autoCountAccepted', counts.accepted);
    autoBgSetText('autoCountReview', counts.review);
    autoBgSetText('autoCountRejected', counts.rejected);
    autoBgSetText('autoCountFailed', counts.failed);
    autoBgSetText('statBgPending', counts.pending);
    autoBgSetText('statBgAccepted', counts.accepted);
    autoBgSetText('statBgReview', counts.review);
    autoBgSetText('statBgRejected', counts.rejected);
    autoBgSetText('statBgFailed', counts.failed);

    const completed = Math.max(0, autoBgInitialPending - Number(counts.pending || 0));
    const pct = autoBgInitialPending > 0 ? Math.min(100, Math.round((completed / autoBgInitialPending) * 100)) : 100;
    const fill = document.getElementById('autoBgFill');
    const pctText = document.getElementById('autoBgPercent');
    if (fill) fill.style.width = pct + '%';
    if (pctText) pctText.textContent = pct + '%';
}
async function autoBgRequestBatch() {
    const fd = new FormData();
    fd.append('csrf', auditCsrf);
    fd.append('action', 'auto_background_batch');
    fd.append('batch_size', '10');
    const response = await fetch(location.pathname + location.search, {
        method: 'POST',
        body: fd,
        headers: {'X-Requested-With': 'fetch'},
        credentials: 'same-origin'
    });
    const raw = await response.text();
    let data;
    try { data = JSON.parse(raw); } catch (e) { throw new Error(raw.slice(0, 250) || 'Respons server tidak sah.'); }
    if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
    return data;
}
async function autoBgResetForRecheck() {
    const fd = new FormData();
    fd.append('csrf', auditCsrf);
    fd.append('action', 'reset_background_auto');
    const response = await fetch(location.pathname + location.search, {
        method: 'POST',
        body: fd,
        headers: {'X-Requested-With': 'fetch'},
        credentials: 'same-origin'
    });
    const raw = await response.text();
    let data;
    try { data = JSON.parse(raw); } catch (e) { throw new Error(raw.slice(0, 250) || 'Respons server tidak sah.'); }
    if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
    return data;
}
async function startAutoBackground(skipConfirm = false) {
    if (autoBgRunning) return;
    const startButton = document.getElementById('autoBgStart');
    const stopButton = document.getElementById('autoBgStop');
    const progress = document.getElementById('autoBgProgress');
    const status = document.getElementById('autoBgStatus');
    const log = document.getElementById('autoBgLog');
    const pending = Number(startButton?.dataset.pending || document.getElementById('autoCountPending')?.textContent.replace(/\D/g, '') || 0);

    if (pending < 1) {
        alert('Semua gambar yang tersedia sudah dinilai.');
        return;
    }
    if (!skipConfirm && !confirm('Nilai semua ' + pending.toLocaleString('ms-MY') + ' gambar yang belum dinilai?\n\nSkor 50% ke atas akan diterima. Proses berjalan 10 gambar setiap batch. Jangan tutup tab sehingga selesai.')) return;

    autoBgRunning = true;
    autoBgStopRequested = false;
    autoBgInitialPending = pending;
    autoBgProcessed = 0;
    if (startButton) { startButton.disabled = true; startButton.textContent = 'Sedang menilai…'; }
    if (stopButton) stopButton.hidden = false;
    if (progress) progress.hidden = false;
    if (status) status.textContent = 'Memulakan batch pertama…';
    if (log) log.textContent = 'Jangan tutup tab ini sehingga proses selesai.';

    try {
        while (!autoBgStopRequested) {
            const data = await autoBgRequestBatch();
            autoBgProcessed += Number(data.processed || 0);
            autoBgUpdateCounts(data.counts || {});
            if (status) status.textContent = 'Diproses ' + autoBgProcessed.toLocaleString('ms-MY') + ' gambar · Baki ' + Number(data.remaining || 0).toLocaleString('ms-MY');
            if (log) {
                const b = data.batch || {};
                log.textContent = 'Batch terakhir: ' + Number(data.processed || 0) + ' gambar — Diterima ' + Number(b.accepted || 0) + ', Semak Manual ' + Number(b.review || 0) + ', Ditolak ' + Number(b.rejected || 0) + ', Gagal ' + Number(b.failed || 0) + '.';
                if (Array.isArray(data.failures) && data.failures.length) log.textContent += ' ' + data.failures.join(' | ');
            }
            if (data.completed || Number(data.remaining || 0) < 1 || Number(data.processed || 0) < 1) break;
            await new Promise(resolve => setTimeout(resolve, 250));
        }

        if (autoBgStopRequested) {
            if (status) status.textContent = 'Proses dihentikan selepas batch semasa.';
            if (log) log.textContent = 'Klik “Nilai Semua Auto” untuk sambung baki gambar.';
        } else {
            if (status) status.textContent = 'Selesai — semua gambar telah diasingkan mengikut kategori.';
            const fill = document.getElementById('autoBgFill');
            const pctText = document.getElementById('autoBgPercent');
            if (fill) fill.style.width = '100%';
            if (pctText) pctText.textContent = '100%';
            if (log) log.textContent = 'Klik kad hijau, kuning, merah atau kelabu untuk menyemak setiap kategori.';
        }
    } catch (error) {
        if (status) status.textContent = 'Proses terhenti kerana ralat.';
        if (log) log.textContent = error.message || String(error);
    } finally {
        autoBgRunning = false;
        if (startButton) { startButton.disabled = false; startButton.textContent = 'Nilai Gambar Belum Dinilai'; startButton.dataset.pending = document.getElementById('autoCountPending')?.textContent.replace(/\D/g, '') || '0'; }
        if (stopButton) stopButton.hidden = true;
    }
}
const autoBgStart = document.getElementById('autoBgStart');
const autoBgRecheck = document.getElementById('autoBgRecheck');
const autoBgStop = document.getElementById('autoBgStop');
if (autoBgStart) autoBgStart.addEventListener('click', () => startAutoBackground(false));
if (autoBgRecheck) autoBgRecheck.addEventListener('click', async () => {
    if (autoBgRunning) return;
    if (!confirm('Nilai semula SEMUA gambar menggunakan ambang baharu 50%?\n\nKeputusan manual admin tidak akan dipadam. Keputusan Auto BG lama akan direset dan dinilai semula.')) return;
    autoBgRecheck.disabled = true;
    autoBgRecheck.textContent = 'Sedang reset…';
    try {
        const data = await autoBgResetForRecheck();
        autoBgUpdateCounts(data.counts || {});
        const pending = Number(data.counts?.pending || 0);
        if (autoBgStart) autoBgStart.dataset.pending = String(pending);
        if (pending < 1) {
            alert('Tiada gambar tersedia untuk dinilai semula.');
            return;
        }
        await startAutoBackground(true);
    } catch (error) {
        alert(error.message || String(error));
    } finally {
        autoBgRecheck.disabled = false;
        autoBgRecheck.textContent = 'Nilai Semula Semua';
    }
});
if (autoBgStop) autoBgStop.addEventListener('click', () => {
    autoBgStopRequested = true;
    autoBgStop.disabled = true;
    autoBgStop.textContent = 'Akan berhenti…';
});

function markWaSent(matrik, waType, waNote) {
    const box = document.getElementById('waBox_' + matrik);
    const cancel = document.getElementById('waCancel_' + matrik);
    const text = document.getElementById('waText_' + matrik);
    if (box) { box.classList.add('sent'); box.textContent = '✓'; }
    if (cancel) cancel.style.display = '';
    if (text) text.textContent = 'WA ' + (waType || '') + ': baru dibuka';
    auditPost('mark_whatsapp', matrik, {wa_type: waType || '', wa_note: waNote || ''})
        .then((data) => { if (text && data.time) text.textContent = 'WA ' + (data.type || waType || '') + ': ' + data.time; })
        .catch(() => { if (text) text.textContent = 'WA: refresh untuk sahkan'; });
}
function unmarkWaSent(matrik) {
    if (!confirm('Batal tanda WhatsApp untuk ' + matrik + '?')) return;
    const box = document.getElementById('waBox_' + matrik);
    const cancel = document.getElementById('waCancel_' + matrik);
    const text = document.getElementById('waText_' + matrik);
    if (box) { box.classList.remove('sent'); box.textContent = ''; }
    if (cancel) cancel.style.display = 'none';
    if (text) text.textContent = 'Belum WA';
    auditPost('unmark_whatsapp', matrik).catch(() => { if (text) text.textContent = 'Refresh untuk sahkan'; });
}
</script>
</body>
</html>

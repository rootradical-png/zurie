<?php
/**
 * Zurie Audit Gambar MIS - Fasa 6
 * Audit kewujudan + penilaian kualiti + auto repair ringan + WhatsApp pelajar.
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
        'tutoran', 'english', 'kokurikulum', 'jurusan',
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
        'bg_ok' => 0,
        'bg_review' => 0,
        'bg_reject' => 0,
        'perlu_whatsapp' => 0,
        'sudah_whatsapp' => 0,
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
        if ($backgroundStatus !== '') {
            $stats['bg_checked']++;
        }
        if (in_array($backgroundStatus, ['putih', 'hampir_putih'], true)) {
            $stats['bg_ok']++;
        } elseif ($backgroundStatus === 'semak') {
            $stats['bg_review']++;
        } elseif ($backgroundStatus === 'tolak') {
            $stats['bg_reject']++;
        }
        $waContext = audit_whatsapp_context($row);
        if ($waContext['needed']) {
            $stats['perlu_whatsapp']++;
            if ((int)($row['whatsapp_sent'] ?? 0) === 1) {
                $stats['sudah_whatsapp']++;
            }
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
$filter = (string)($_GET['filter'] ?? 'quality_pending');
$allowedFilters = [
    'quality_pending', 'good', 'repair', 'upload_new', 'missing',
    'bg_ok', 'bg_review', 'bg_reject',
    'wa_pending', 'wa_sent', 'exists', 'unchecked', 'all',
];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'quality_pending';
}
$search = trim(substr((string)($_GET['q'] ?? ''), 0, 120));
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

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['download'] ?? '') === 'hep_missing_pdf') {
        audit_download_missing_pdf($pdo, $phoneColumn, $search, $actor, $studentFilters);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_audit();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'reconcile_active') {
            $result = audit_reconcile_active_students($pdo);
            $messages[] = 'Selaras pelajar aktif selesai. Disync/kemas kini: ' . $result['synced'] .
                ', rekod lama ditanda tidak aktif: ' . $result['stale'] .
                ', roster aktif unik: ' . $result['roster_count'] . '.';
        } elseif ($action === 'audit_all' || $action === 'audit_sample') {
            $result = run_photo_audit($pdo, $action === 'audit_sample' ? 50 : 0);
            $messages[] = 'Audit selesai. Disemak: ' . $result['total'] .
                ', Ada gambar: ' . $result['exists'] . ', Tiada gambar: ' . $result['missing'] . '.';
            $filter = 'quality_pending';
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
            $filter = 'unchecked';
        } elseif ($action === 'clear_audit_uploads') {
            $pdo->exec("TRUNCATE TABLE student_photo_audit");
            $pdo->exec("TRUNCATE TABLE student_photo_uploads");
            $messages[] = 'Data audit dan rekod upload telah dikosongkan. Fail gambar fizikal tidak dipadam.';
            $filter = 'unchecked';
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

        return match ($filter) {
            'quality_pending' => $exists && $quality === '',
            'good' => $quality === 'baik',
            'repair' => $quality === 'repair',
            'upload_new' => $quality === 'upload_baru',
            'missing' => $checked && !$exists,
            'bg_ok' => in_array($backgroundStatus, ['putih', 'hampir_putih'], true),
            'bg_review' => $backgroundStatus === 'semak',
            'bg_reject' => $backgroundStatus === 'tolak',
            'wa_pending' => $waContext['needed'] && !$waSent,
            'wa_sent' => $waContext['needed'] && $waSent,
            'exists' => $exists,
            'unchecked' => !$checked,
            default => true,
        };
    }));

    $candidateRows = audit_filter_rows($tabRows, $studentFilters, $search);
    $filteredCount = count($candidateRows);
    $rows = array_slice($candidateRows, 0, 500);

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

$currentQueryParams = array_merge(['filter' => $filter, 'q' => $search], $studentFilters);
$currentQueryParams = array_filter(
    $currentQueryParams,
    static fn($value): bool => $value !== ''
);
$currentQueryString = http_build_query($currentQueryParams);
$filterSummary = audit_filter_summary($studentFilters, $search);
$hasAdvancedFilters = $filterSummary !== 'Semua rekod';

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
$resetAdvancedUrl = '?filter=' . rawurlencode($filter);
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit & Kualiti Gambar MIS | Zurie</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;margin:0;color:#0f172a}.wrap{max-width:1320px;margin:24px auto;padding:0 14px}.card{background:#fff;border-radius:16px;padding:18px;box-shadow:0 9px 28px rgba(15,23,42,.08);margin-bottom:14px}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.title{font-size:25px;font-weight:800;margin:0}.muted{color:#64748b}.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px}.stat b{display:block;font-size:22px}.toolbar{display:flex;gap:7px;flex-wrap:wrap;align-items:center}.tab,.btn{border:0;border-radius:9px;padding:9px 11px;font-weight:700;text-decoration:none;display:inline-block}.tab{background:#e2e8f0;color:#0f172a}.tab.active{background:#2563eb;color:#fff}.btn{background:#2563eb;color:#fff;cursor:pointer}.btn.warn{background:#b45309}.btn.danger{background:#dc2626}.btn.ghost{background:#f1f5f9;color:#0f172a}.btn.good{background:#15803d}.btn.repair{background:#7c3aed}.btn.upload{background:#ea580c}.btn.bg{background:#0369a1}.btn.wa{background:#16a34a;font-size:11px;padding:6px 8px}.btn.mini{font-size:11px;padding:6px 8px}.btn.reset{background:#64748b}.btn.pdf{background:#991b1b}.alert{padding:11px 13px;border-radius:11px;margin-bottom:11px}.err{background:#fee2e2;color:#991b1b}.ok{background:#dcfce7;color:#166534}input[type=text],select{padding:9px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#0f172a}select{min-width:130px}.filter-panel{display:grid;grid-template-columns:1.3fr repeat(6,minmax(125px,1fr)) auto auto;gap:9px;align-items:end;margin-top:14px;padding-top:14px;border-top:1px solid #e2e8f0}.filter-field{display:flex;flex-direction:column;gap:5px}.filter-field label{font-size:11px;font-weight:800;color:#475569;text-transform:uppercase}.filter-field.search-field input{width:100%;box-sizing:border-box}.filter-result{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}.filter-chip{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:700}.filter-count{font-weight:800;color:#0f172a}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:1250px}th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}th{font-size:12px;text-transform:uppercase;color:#475569;background:#f8fafc}.thumb{width:82px;height:102px;object-fit:cover;border-radius:9px;border:1px solid #cbd5e1;background:#f8fafc}.badge{padding:5px 8px;border-radius:999px;font-weight:800;font-size:11px;display:inline-block}.yes,.quality-good{background:#dcfce7;color:#166534}.no,.quality-missing{background:#fee2e2;color:#991b1b}.wait,.quality-pending{background:#e2e8f0;color:#334155}.quality-repair{background:#ede9fe;color:#5b21b6}.quality-upload{background:#ffedd5;color:#9a3412}.bg-good{background:#dcfce7;color:#166534}.bg-near{background:#ecfccb;color:#3f6212}.bg-review{background:#fef3c7;color:#92400e}.bg-reject{background:#fee2e2;color:#991b1b}.bg-failed{background:#f1f5f9;color:#475569}.bg-pending{background:#e0f2fe;color:#075985}.bg-details{margin-top:6px;padding:7px 8px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0}.color-dot{display:inline-block;width:12px;height:12px;border-radius:50%;border:1px solid #94a3b8;vertical-align:-2px;margin-right:4px}.baru{background:#fef3c7;color:#92400e}.lulus{background:#dcfce7;color:#166534}.tolak{background:#fee2e2;color:#991b1b}.small{font-size:11px;color:#64748b}.action-row{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:6px}.action-row form{margin:0}.wa-line{display:flex;gap:5px;align-items:center;margin-top:6px}.wa-box{width:17px;height:17px;border:1px solid #94a3b8;border-radius:4px;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:900;color:#16a34a}.wa-box.sent{border-color:#16a34a;background:#dcfce7}.wa-cancel{border:0;background:#fee2e2;color:#991b1b;border-radius:999px;width:17px;height:17px;cursor:pointer;font-weight:900;font-size:11px;padding:0}.wa-time{font-size:10px;color:#64748b}.breadcrumb{display:flex;gap:7px;align-items:center;flex-wrap:wrap;font-size:13px;margin-bottom:11px}.breadcrumb a{color:#2563eb;text-decoration:none;font-weight:700}.breadcrumb span{color:#64748b}.bulk{position:sticky;top:0;z-index:5;background:#fff;border:1px solid #dbeafe}.quality-guide{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.guide{border:1px solid #e2e8f0;border-radius:10px;padding:10px;font-size:12px}.guide b{display:block;margin-bottom:4px}.source-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:9px;margin-top:12px}.source-stat{border:1px solid #dbeafe;background:#f8fbff;border-radius:11px;padding:10px}.source-stat span{display:block;font-size:11px;color:#64748b}.source-stat b{font-size:20px}.source-note{margin-top:10px;padding:9px 11px;border-radius:9px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;font-size:12px}.source-ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}@media(max-width:1180px){.filter-panel{grid-template-columns:repeat(4,minmax(140px,1fr))}.filter-field.search-field{grid-column:span 2}}@media(max-width:950px){.stats,.quality-guide,.source-grid{grid-template-columns:repeat(2,1fr)}.filter-panel{grid-template-columns:repeat(2,minmax(140px,1fr))}.filter-field.search-field{grid-column:span 2}}@media(max-width:600px){.source-grid{grid-template-columns:1fr 1fr}.filter-panel{grid-template-columns:1fr}.filter-field.search-field{grid-column:span 1}.filter-panel .btn{width:100%;text-align:center}}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="/zurie/">Dashboard</a><span>›</span>
            <strong>Audit & Kualiti Gambar MIS</strong><span>›</span>
            <a href="/zurie/pages/upload_review.php">Semakan Upload</a><span>›</span>
            <a href="/zurie/pages/mis_sftp_setup.php">Tetapan SFTP</a>
        </nav>
        <div class="top">
            <div>
                <h1 class="title">Audit & Kualiti Gambar MIS</h1>
                <div class="muted">Nilai gambar MIS serta kesan background putih, hampir putih, berwarna, bayang dan background tidak bersih.</div>
            </div>
            <div>
                <a class="btn ghost" href="/zurie/pages/pg_live_lookup_setup.php">Tetapan Pelajar Aktif</a>
                <a class="btn ghost" href="/zurie/pages/upload_review.php">Semakan Upload</a>
                <a class="btn ghost" href="/zurie/upload/" target="_blank">Borang Upload</a>
            </div>
        </div>
    </div>

    <?php foreach ($messages as $message): ?><div class="alert ok"><?= h($message) ?></div><?php endforeach; ?>
    <?php foreach ($errors as $error): ?><div class="alert err"><?= h($error) ?></div><?php endforeach; ?>

    <?php if (!empty($activeReconciliation['ready'])): ?>
        <?php
            $needsActiveSync = (int)($activeReconciliation['missing_local'] ?? 0) > 0
                || (int)($activeReconciliation['stale_local'] ?? 0) > 0;
            $hasDuplicateRecords = (int)($activeReconciliation['duplicate_records'] ?? 0) > 0;
        ?>
        <div class="card">
            <div class="top">
                <div>
                    <strong>Semakan Sumber Pelajar Aktif</strong>
                    <div class="small">
                        PostgreSQL Semester <?= h((string)($activeReconciliation['semester'] ?? '-')) ?>,
                        status <?= h((string)($activeReconciliation['active_status'] ?? '-')) ?>.
                        Photo Audit hanya memaparkan pelajar yang masih aktif dalam roster ini.
                    </div>
                </div>
                <form method="post" onsubmit="return confirm('Selaras semua pelajar aktif PostgreSQL ke table senarai Zurie dan tandakan rekod lama sebagai TIDAK_AKTIF? Rekod audit/upload tidak dipadam.');">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <button class="btn <?= $needsActiveSync ? 'warn' : 'ghost' ?>" type="submit" name="action" value="reconcile_active">
                        Semak &amp; Selaras Pelajar Aktif
                    </button>
                </form>
            </div>
            <div class="source-grid">
                <div class="source-stat"><span>Rekod Aktif PostgreSQL</span><b><?= number_format((int)($activeReconciliation['active_records'] ?? 0)) ?></b></div>
                <div class="source-stat"><span>Pelajar Unik (No. KP)</span><b><?= number_format((int)($activeReconciliation['unique_kp'] ?? 0)) ?></b></div>
                <div class="source-stat"><span>Roster Ada No. Matrik</span><b><?= number_format((int)($activeReconciliation['roster_count'] ?? 0)) ?></b></div>
                <div class="source-stat"><span>Aktif Dalam Zurie</span><b><?= number_format((int)($activeReconciliation['matched'] ?? 0)) ?></b></div>
                <div class="source-stat"><span>Belum Sync ke Zurie</span><b><?= number_format((int)($activeReconciliation['missing_local'] ?? 0)) ?></b></div>
                <div class="source-stat"><span>Rekod Lama Masih AKTIF</span><b><?= number_format((int)($activeReconciliation['stale_local'] ?? 0)) ?></b></div>
            </div>
            <?php if ($hasDuplicateRecords): ?>
                <div class="source-note">
                    Perbezaan kiraan dikesan: <?= number_format((int)$activeReconciliation['active_records']) ?> ialah bilangan rekod pendaftaran,
                    tetapi hanya <?= number_format((int)$activeReconciliation['unique_kp']) ?> pelajar unik.
                    Terdapat <?= number_format((int)$activeReconciliation['duplicate_records']) ?> rekod aktif pendua berdasarkan No. KP.
                </div>
            <?php endif; ?>
            <?php if ((int)($activeReconciliation['blank_kp_records'] ?? 0) > 0): ?>
                <div class="source-note">
                    <?= number_format((int)$activeReconciliation['blank_kp_records']) ?> rekod aktif PostgreSQL tidak mempunyai No. KP yang boleh digunakan untuk padanan unik.
                </div>
            <?php endif; ?>
            <?php if ((int)($activeReconciliation['unmapped_students'] ?? 0) > 0): ?>
                <div class="source-note">
                    <?= number_format((int)$activeReconciliation['unmapped_students']) ?> pelajar aktif unik tidak dapat dipadankan kepada No. Matrik dalam table <code>personal</code>.
                </div>
            <?php endif; ?>
            <?php if ($needsActiveSync): ?>
                <div class="source-note">
                    Senarai Zurie belum selaras: <?= number_format((int)$activeReconciliation['missing_local']) ?> pelajar aktif belum ada dalam Zurie dan
                    <?= number_format((int)$activeReconciliation['stale_local']) ?> rekod lama termasuk pelajar berhenti masih ditanda AKTIF.
                    Klik <b>Semak &amp; Selaras Pelajar Aktif</b> sebelum Audit Semua.
                </div>
            <?php else: ?>
                <div class="source-note source-ok">Senarai aktif Zurie telah sepadan dengan roster aktif PostgreSQL. Pelajar berhenti tidak dimasukkan dalam filter intake.</div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="alert err">
            PostgreSQL aktif tidak dapat disemak. Photo Audit menggunakan status tempatan Zurie sahaja.
            <?= h((string)($activeReconciliation['error'] ?? $activeSnapshot['error'] ?? '')) ?>
        </div>
    <?php endif; ?>

    <div class="card quality-guide">
        <div class="guide"><b>🤖 Semak BG</b>Analisis background putih, hampir putih, warna, bayang dan keseragaman.</div>
        <div class="guide"><b>✅ BG Diterima</b>Putih atau hampir putih akan ditanda Gambar Baik jika belum dinilai.</div>
        <div class="guide"><b>⚠️ Semak Manual</b>Background berbayang atau tidak sekata perlu pengesahan admin.</div>
        <div class="guide"><b>⛔ BG Ditolak</b>Biru, berwarna, gelap atau tidak bersih akan dicadangkan Upload Baru.</div>
        <div class="guide"><b>🛠 Perlu Repair</b>Sistem ambil gambar MIS, crop 413×531 dan hantar ke Semakan Upload.</div>
        <div class="guide"><b>❌ Tiada Gambar</b>WhatsApp pelajar untuk muat naik gambar.</div>
    </div>

    <div class="card stats">
        <div class="stat"><span>Aktif Dalam Zurie</span><b><?= stat_int($stats,'aktif') ?></b></div>
        <div class="stat"><span>Ada MIS</span><b><?= stat_int($stats,'ada_mis') ?></b></div>
        <div class="stat"><span>Tiada MIS</span><b><?= stat_int($stats,'tiada_mis') ?></b></div>
        <div class="stat"><span>Belum Nilai</span><b><?= stat_int($stats,'belum_nilai') ?></b></div>
        <div class="stat"><span>Gambar Baik</span><b><?= stat_int($stats,'baik') ?></b></div>
        <div class="stat"><span>Perlu Repair</span><b><?= stat_int($stats,'repair') ?></b></div>
        <div class="stat"><span>Upload Baru</span><b><?= stat_int($stats,'upload_baru') ?></b></div>
        <div class="stat"><span>BG Sudah Semak</span><b><?= stat_int($stats,'bg_checked') ?></b></div>
        <div class="stat"><span>BG Diterima</span><b><?= stat_int($stats,'bg_ok') ?></b></div>
        <div class="stat"><span>BG Semak Manual</span><b><?= stat_int($stats,'bg_review') ?></b></div>
        <div class="stat"><span>BG Ditolak</span><b><?= stat_int($stats,'bg_reject') ?></b></div>
        <div class="stat"><span>Perlu WhatsApp</span><b><?= stat_int($stats,'perlu_whatsapp') ?></b></div>
        <div class="stat"><span>Sudah WhatsApp</span><b><?= stat_int($stats,'sudah_whatsapp') ?></b></div>
        <div class="stat"><span>Sudah Semak</span><b><?= stat_int($stats,'sudah_semak') ?></b></div>
        <div class="stat"><span>Belum Semak</span><b><?= stat_int($stats,'belum_semak') ?></b></div>
    </div>

    <div class="card">
        <form method="post" class="toolbar" onsubmit="return confirm('Audit semua pelajar aktif akan mengambil sedikit masa. Teruskan?');">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <button class="btn" type="submit" name="action" value="audit_all">Audit Semua Gambar MIS</button>
            <button class="btn warn" type="submit" name="action" value="audit_sample">Test 50 Pelajar</button>
            <span class="small">Hanya roster aktif PostgreSQL semasa. Prioriti fail: .jpg → .jpeg → .png, kemudian huruf besar.</span>
        </form>
        <hr style="border:0;border-top:1px solid #e2e8f0;margin:13px 0">
        <form method="post" class="toolbar" onsubmit="return confirm('Clear data audit untuk batch baru?');">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <button class="btn danger" type="submit" name="action" value="clear_audit">Clear Audit Batch</button>
            <button class="btn danger" type="submit" name="action" value="clear_audit_uploads" onclick="return confirm('Ini akan clear audit DAN rekod upload. Fail fizikal tidak dipadam. Teruskan?');">Clear Audit + Rekod Upload</button>
        </form>
    </div>

    <div class="card">
        <div class="toolbar">
            <?php foreach ([
                'quality_pending'=>'Belum Nilai', 'good'=>'Gambar Baik', 'repair'=>'Perlu Repair',
                'upload_new'=>'Upload Baru', 'missing'=>'Tiada Gambar',
                'bg_ok'=>'BG Diterima', 'bg_review'=>'BG Semak', 'bg_reject'=>'BG Tolak',
                'wa_pending'=>'Perlu WhatsApp',
                'wa_sent'=>'Sudah WhatsApp', 'unchecked'=>'Belum Audit', 'exists'=>'Semua Ada MIS', 'all'=>'Semua'
            ] as $key=>$label): ?>
                <a class="tab <?= $filter===$key?'active':'' ?>" href="<?= h($tabUrls[$key] ?? ('?filter=' . $key)) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
            <?php if ($filter === 'missing'): ?>
                <a class="btn pdf" href="<?= h($pdfDownloadUrl) ?>">Muat Turun PDF untuk HEP</a>
            <?php endif; ?>
        </div>

        <form method="get" class="filter-panel">
            <input type="hidden" name="filter" value="<?= h($filter) ?>">

            <div class="filter-field search-field">
                <label for="filterQ">Carian</label>
                <input id="filterQ" type="text" name="q" value="<?= h($search) ?>" placeholder="Matrik, nama, kelas, jurusan atau asrama">
            </div>

            <div class="filter-field">
                <label for="filterJantina">Jantina</label>
                <select id="filterJantina" name="jantina">
                    <option value="">Semua jantina</option>
                    <?php foreach ($filterOptions['jantina'] as $value): ?>
                        <option value="<?= h($value) ?>" <?= $studentFilters['jantina']===$value?'selected':'' ?>><?= h(audit_gender_label($value)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="filterIntake">Intake</label>
                <select id="filterIntake" name="intake">
                    <option value="">Semua intake</option>
                    <?php foreach ($filterOptions['intake'] as $value): ?>
                        <option value="<?= h($value) ?>" <?= $studentFilters['intake']===$value?'selected':'' ?>>Intake <?= h($value) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="filterPraktikum">Praktikum</label>
                <select id="filterPraktikum" name="praktikum">
                    <option value="">Semua praktikum</option>
                    <?php foreach ($filterOptions['praktikum'] as $value): ?>
                        <option value="<?= h($value) ?>" <?= $studentFilters['praktikum']===$value?'selected':'' ?>><?= h($value) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="filterKuliah">Kuliah</label>
                <select id="filterKuliah" name="kuliah">
                    <option value="">Semua kuliah</option>
                    <?php foreach ($filterOptions['kuliah'] as $value): ?>
                        <option value="<?= h($value) ?>" <?= $studentFilters['kuliah']===$value?'selected':'' ?>><?= h($value) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="filterJurusan">Jurusan</label>
                <select id="filterJurusan" name="jurusan">
                    <option value="">Semua jurusan</option>
                    <?php foreach ($filterOptions['jurusan'] as $value): ?>
                        <option value="<?= h($value) ?>" <?= $studentFilters['jurusan']===$value?'selected':'' ?>><?= h($value) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="filterAsrama">Asrama</label>
                <select id="filterAsrama" name="asrama">
                    <option value="">Semua asrama</option>
                    <?php foreach ($filterOptions['asrama'] as $value): ?>
                        <option value="<?= h($value) ?>" <?= $studentFilters['asrama']===$value?'selected':'' ?>><?= h($value) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="btn" type="submit">Tapis</button>
            <a class="btn ghost" href="<?= h($resetAdvancedUrl) ?>">Reset</a>
        </form>

        <div class="filter-result">
            <span class="filter-count"><?= number_format($filteredCount) ?> rekod sepadan</span>
            <?php if ($filteredCount > 500): ?><span class="small">Paparan dihadkan kepada 500 rekod pertama.</span><?php endif; ?>
            <?php if ($hasAdvancedFilters): ?><span class="filter-chip"><?= h($filterSummary) ?></span><?php endif; ?>
        </div>

        <?php if ($filter === 'missing'): ?>
            <div class="small" style="margin-top:10px">PDF HEP akan mengikut semua carian dan penapis yang dipilih, termasuk jantina, intake, praktikum, kuliah, jurusan dan asrama.</div>
        <?php endif; ?>
    </div>

    <form method="post" id="bulkForm" action="?<?= h($currentQueryString) ?>">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <div class="card bulk toolbar">
            <label><input type="checkbox" id="selectAll"> Pilih semua pada halaman</label>
            <button class="btn bg mini" type="submit" name="action" value="bulk_background_check" onclick="return confirmBulk('Analisis background gambar MIS terpilih? Maksimum 20 sekali. Keputusan putih/hampir putih atau tolak akan digunakan secara automatik hanya untuk rekod yang belum dinilai.')">Semak BG Terpilih</button>
            <button class="btn good mini" type="submit" name="action" value="bulk_good" onclick="return confirmBulk('Tanda Gambar Baik untuk rekod terpilih?')">Baik Terpilih</button>
            <button class="btn repair mini" type="submit" name="action" value="bulk_repair" onclick="return confirmBulk('Auto repair gambar MIS terpilih? Maksimum 20 sekali.')">Repair Terpilih</button>
            <button class="btn upload mini" type="submit" name="action" value="bulk_upload" onclick="return confirmBulk('Tanda perlu Upload Baru untuk rekod terpilih?')">Upload Baru Terpilih</button>
            <span class="small">Analisis BG dan repair pukal maksimum 20; tindakan lain maksimum 50. Semakan BG menggunakan PHP GD pada server.</span>
        </div>

        <div class="card table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>☐</th>
                        <th>Gambar MIS</th>
                        <th>Pelajar</th>
                        <th>Kelas / Program</th>
                        <th>Audit & Kualiti</th>
                        <th>Upload / Sync</th>
                        <th>Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="muted">Tiada rekod untuk paparan ini.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row):
                    $matrik = (string)$row['matrik'];
                    $checked = !empty($row['checked_at']);
                    $exists = (int)($row['photo_exists'] ?? 0) === 1;
                    $proxyUrl = '/zurie/student_photo.php?nomatrik=' . rawurlencode($matrik);
                    $uploadStatus = strtolower((string)($row['upload_status'] ?? ''));
                    $uploadClass = in_array($uploadStatus, ['baru','lulus','tolak'], true) ? $uploadStatus : 'wait';
                    [$qualityLabel, $qualityClass] = quality_badge($row['quality_status'] !== null ? (string)$row['quality_status'] : null);
                    $backgroundBadge = zurie_photo_background_badge($row['background_status'] !== null ? (string)$row['background_status'] : null);
                    $waContext = audit_whatsapp_context($row);
                    $needsWa = $waContext['needed'];
                    $waType = $waContext['type'];
                    $waNote = $waContext['note'];
                    $waLabel = $waContext['label'];
                    $waUrl = $waType === 'tolak' ? audit_reject_wa_url($row) : ($needsWa ? audit_wa_url($row) : '');
                    $waSent = (int)($row['whatsapp_sent'] ?? 0) === 1;
                ?>
                    <tr>
                        <td><?php if ($exists): ?><input class="rowCheck" type="checkbox" name="matriks[]" value="<?= h($matrik) ?>"><?php endif; ?></td>
                        <td>
                            <?php if ($exists): ?>
                                <a href="<?= h($proxyUrl) ?>" target="_blank" title="Buka gambar besar">
                                    <img class="thumb" src="<?= h($proxyUrl) ?>" alt="Gambar MIS <?= h($matrik) ?>" onerror="this.style.display='none';">
                                </a>
                            <?php else: ?>
                                <span class="badge no">TIADA</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <b><?= h($matrik) ?></b><br>
                            <?= h((string)$row['nama']) ?><br>
                            <span class="small">No KP: <?= h((string)($row['nokp'] ?? '')) ?></span><br>
                            <span class="small">Jantina: <?= h(audit_gender_label((string)($row['jantina'] ?? '-'))) ?></span>
                        </td>
                        <td>
                            Intake: <b><?= h((string)($row['stud_intake'] ?? '-')) ?></b><br>
                            Praktikum: <b><?= h((string)($row['praktikum'] ?? '-')) ?></b><br>
                            Kuliah: <?= h((string)($row['kuliah'] ?? '-')) ?><br>
                            Jurusan: <?= h((string)($row['jurusan'] ?? '-')) ?><br>
                            Asrama: <?= h((string)($row['asrama'] ?? '-')) ?>
                        </td>
                        <td>
                            <?php if (!$checked): ?>
                                <span class="badge wait">BELUM AUDIT</span>
                            <?php elseif ($exists): ?>
                                <span class="badge yes">ADA MIS</span>
                            <?php else: ?>
                                <span class="badge no">TIADA MIS</span>
                            <?php endif; ?>
                            <br><span class="badge <?= h($qualityClass) ?>" style="margin-top:6px"><?= h($qualityLabel) ?></span>
                            <?php if (!empty($row['quality_reason'])): ?><br><span class="small"><?= h((string)$row['quality_reason']) ?></span><?php endif; ?>
                            <?php if (!empty($row['quality_checked_by'])): ?><br><span class="small">Oleh: <?= h((string)$row['quality_checked_by']) ?></span><?php endif; ?>
                            <div class="bg-details">
                                <span class="badge <?= h($backgroundBadge['class']) ?>"><?= h($backgroundBadge['label']) ?></span>
                                <?php if (!empty($row['background_checked_at'])): ?>
                                    <br><span class="small">Skor: <b><?= number_format((float)($row['background_score'] ?? 0), 1) ?>%</b>
                                    · Putih: <?= number_format((float)($row['background_white_ratio'] ?? 0), 1) ?>%
                                    · Seragam: <?= number_format((float)($row['background_uniformity'] ?? 0), 1) ?>%</span>
                                    <br><span class="small"><span class="color-dot" style="background:<?= h((string)($row['background_dominant_hex'] ?? '#ffffff')) ?>"></span>
                                    <?= h((string)($row['background_dominant_color'] ?? '-')) ?>
                                    · Bayang: <?= number_format((float)($row['background_shadow_ratio'] ?? 0), 1) ?>%</span>
                                    <?php if (!empty($row['background_reason'])): ?><br><span class="small"><?= h((string)$row['background_reason']) ?></span><?php endif; ?>
                                <?php else: ?>
                                    <br><span class="small">Belum dianalisis.</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($uploadStatus !== ''): ?>
                                <span class="badge <?= h($uploadClass) ?>"><?= h(strtoupper($uploadStatus)) ?></span><br>
                                <span class="small">Repair: <?= h((string)($row['repair_status'] ?? '-')) ?></span><br>
                                <span class="small">Sync: <?= h(strtoupper((string)($row['sync_status'] ?? 'belum'))) ?></span><br>
                                <?php if ($uploadStatus === 'tolak' && !empty($row['reject_reason'])): ?><span class="small">Sebab: <?= h((string)$row['reject_reason']) ?></span><br><?php endif; ?>
                                <?php if ($uploadStatus === 'tolak' && !empty($row['reject_note'])): ?><span class="small">Catatan: <?= h((string)$row['reject_note']) ?></span><br><?php endif; ?>
                                <a class="small" href="/zurie/pages/upload_review.php?q=<?= rawurlencode($matrik) ?>">Buka semakan</a>
                            <?php else: ?>
                                <span class="badge wait">BELUM UPLOAD</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($exists): ?>
                                <div class="action-row">
                                    <button class="btn bg mini" type="submit" name="action" value="background_check" formaction="?<?= h($currentQueryString) ?>" onclick="if(!confirm('Analisis background <?= h($matrik) ?>? Jika belum dinilai, keputusan yang jelas akan ditanda Baik atau Upload Baru secara automatik.'))return false;this.form.querySelector('#singleMatrik').value='<?= h($matrik) ?>'">Semak BG</button>
                                    <button class="btn good mini" type="submit" name="action" value="quality_good" formaction="?<?= h($currentQueryString) ?>" onclick="this.form.querySelector('#singleMatrik').value='<?= h($matrik) ?>'">Baik</button>
                                    <button class="btn repair mini" type="submit" name="action" value="quality_repair" formaction="?<?= h($currentQueryString) ?>" onclick="if(!confirm('Ambil gambar MIS dan auto repair <?= h($matrik) ?>?'))return false;this.form.querySelector('#singleMatrik').value='<?= h($matrik) ?>'">Repair</button>
                                    <button class="btn upload mini" type="submit" name="action" value="quality_upload" formaction="?<?= h($currentQueryString) ?>" onclick="this.form.querySelector('#singleMatrik').value='<?= h($matrik) ?>'">Upload Baru</button>
                                    <?php if (!empty($row['quality_status'])): ?>
                                        <button class="btn reset mini" type="submit" name="action" value="quality_reset" onclick="this.form.querySelector('#singleMatrik').value='<?= h($matrik) ?>'">× Reset</button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($needsWa): ?>
                                <?php if ($waUrl !== ''): ?>
                                    <a class="btn wa" target="_blank" rel="noopener" href="<?= h($waUrl) ?>"
                                       onclick='markWaSent(<?= json_encode($matrik, JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= json_encode($waType, JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= json_encode($waNote, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><?= h($waLabel) ?></a>
                                    <div class="wa-line">
                                        <span id="waBox_<?= h($matrik) ?>" class="wa-box <?= $waSent?'sent':'' ?>"><?= $waSent?'✓':'' ?></span>
                                        <button type="button" id="waCancel_<?= h($matrik) ?>" class="wa-cancel" style="<?= $waSent?'':'display:none' ?>" onclick="unmarkWaSent('<?= h($matrik) ?>')">×</button>
                                        <span id="waText_<?= h($matrik) ?>" class="wa-time"><?= $waSent ? ('WA ' . h((string)($row['whatsapp_type'] ?? $waType)) . ': ' . h((string)($row['whatsapp_sent_at'] ?? '-'))) : 'Belum WA' ?></span>
                                    </div>
                                    <span class="small">No HP: <?= h((string)($row['nohp'] ?? '')) ?></span>
                                    <?php if (!empty($row['whatsapp_source'])): ?><br><span class="small">Sumber WA: <?= h((string)$row['whatsapp_source']) ?></span><?php endif; ?>
                                <?php else: ?>
                                    <span class="badge no">TIADA NO HP</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <input type="hidden" name="matrik" id="singleMatrik" value="">
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

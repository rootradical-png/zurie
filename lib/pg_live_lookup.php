<?php
declare(strict_types=1);

/**
 * Semakan pendaftaran aktif PostgreSQL secara langsung untuk modul upload foto.
 *
 * Keutamaan config:
 * 1. Environment ZURIE_PG_LIVE_CONFIG
 * 2. C:/xampp_baru/secure/zurie_pg_live_config.php (disyorkan)
 * 3. /zurie/config/pg_live_lookup_config.php (fallback sahaja)
 */

require_once __DIR__ . '/security.php';

/**
 * Sambungan MySQL Zurie untuk menyimpan tetapan operasi yang boleh diubah admin.
 */
function zurie_pg_live_settings_pdo(): PDO
{
    $configFile = dirname(__DIR__) . '/config/vault_config.php';
    $config = is_file($configFile) ? require $configFile : [];

    $dsn = (string)($config['dsn'] ?? 'mysql:host=localhost;dbname=zurie_noc;charset=utf8mb4');
    $username = (string)($config['username'] ?? 'root');
    $password = (string)($config['password'] ?? '');

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

function zurie_pg_live_ensure_settings_table(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value VARCHAR(255) NULL,
    updated_at DATETIME NULL,
    updated_by VARCHAR(100) NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $requiredColumns = [
        'setting_value' => "ALTER TABLE system_settings ADD COLUMN setting_value VARCHAR(255) NULL",
        'updated_at' => "ALTER TABLE system_settings ADD COLUMN updated_at DATETIME NULL",
        'updated_by' => "ALTER TABLE system_settings ADD COLUMN updated_by VARCHAR(100) NULL",
    ];
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_settings' AND COLUMN_NAME=?"
    );
    foreach ($requiredColumns as $column => $sql) {
        $check->execute([$column]);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function zurie_pg_live_load_settings(?PDO $pdo = null): array
{
    try {
        $pdo ??= zurie_pg_live_settings_pdo();
        zurie_pg_live_ensure_settings_table($pdo);
        $keys = ['pg_active_semester', 'pg_active_status', 'academic_session'];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ({$placeholders})");
        $stmt->execute($keys);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
        return $result;
    } catch (Throwable $e) {
        error_log('[ZURIE PG SETTINGS LOAD] ' . $e->getMessage());
        return [];
    }
}

function zurie_pg_live_save_settings(
    PDO $pdo,
    int $semester,
    string $activeStatus,
    string $academicSession,
    string $actor = 'admin'
): void {
    if ($semester <= 0) {
        throw new InvalidArgumentException('Semester aktif tidak sah.');
    }
    $activeStatus = strtoupper(trim($activeStatus));
    $academicSession = trim($academicSession);
    if ($activeStatus === '' || $academicSession === '') {
        throw new InvalidArgumentException('Kod aktif dan sesi akademik diperlukan.');
    }

    zurie_pg_live_ensure_settings_table($pdo);
    $sql = "INSERT INTO system_settings (setting_key, setting_value, updated_at, updated_by)
            VALUES (:setting_key, :setting_value, NOW(), :updated_by)
            ON DUPLICATE KEY UPDATE
                setting_value=VALUES(setting_value),
                updated_at=NOW(),
                updated_by=VALUES(updated_by)";
    $stmt = $pdo->prepare($sql);

    $values = [
        'pg_active_semester' => (string)$semester,
        'pg_active_status' => $activeStatus,
        'academic_session' => $academicSession,
    ];

    $pdo->beginTransaction();
    try {
        foreach ($values as $key => $value) {
            $stmt->execute([
                ':setting_key' => $key,
                ':setting_value' => $value,
                ':updated_by' => substr($actor, 0, 100),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function zurie_pg_live_semester_options(PDO $pgsql, string $activeStatus): array
{
    $sql = <<<'SQL'
WITH pelajar_semester AS (
    SELECT
        stud_semester,
        stud_status,
        NULLIF(regexp_replace(COALESCE(stud_kp, ''), '[^0-9]', '', 'g'), '') AS kp_bersih,
        stud_status = :active_status AS is_active
    FROM public.pelajar
    WHERE stud_semester IS NOT NULL
)
SELECT
    stud_semester,
    COUNT(*) AS total_count,
    SUM(CASE WHEN is_active THEN 1 ELSE 0 END) AS active_count,
    COUNT(DISTINCT CASE WHEN is_active THEN kp_bersih ELSE NULL END) AS active_unique_count,
    SUM(CASE WHEN is_active AND kp_bersih IS NULL THEN 1 ELSE 0 END) AS active_blank_kp_count
FROM pelajar_semester
GROUP BY stud_semester
ORDER BY stud_semester DESC
LIMIT 30
SQL;
    $stmt = $pgsql->prepare($sql);
    $stmt->bindValue(':active_status', $activeStatus, PDO::PARAM_STR);
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $activeCount = (int)($row['active_count'] ?? 0);
        $uniqueCount = (int)($row['active_unique_count'] ?? 0);
        $blankKpCount = (int)($row['active_blank_kp_count'] ?? 0);
        $rows[] = [
            'stud_semester' => (int)($row['stud_semester'] ?? 0),
            'total_count' => (int)($row['total_count'] ?? 0),
            'active_count' => $activeCount,
            'active_unique_count' => $uniqueCount,
            'active_blank_kp_count' => $blankKpCount,
            'active_duplicate_count' => max(0, $activeCount - $blankKpCount - $uniqueCount),
        ];
    }
    return $rows;
}

function zurie_pg_live_count_active(PDO $pgsql, int $semester, string $activeStatus): int
{
    $stmt = $pgsql->prepare(
        "SELECT COUNT(DISTINCT NULLIF(regexp_replace(COALESCE(stud_kp, ''), '[^0-9]', '', 'g'), ''))
         FROM public.pelajar
         WHERE stud_semester = :semester AND stud_status = :active_status"
    );
    $stmt->bindValue(':semester', $semester, PDO::PARAM_INT);
    $stmt->bindValue(':active_status', $activeStatus, PDO::PARAM_STR);
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function zurie_pg_live_config_candidates(): array
{
    $candidates = [];
    $envPath = trim((string)(getenv('ZURIE_PG_LIVE_CONFIG') ?: ''));
    if ($envPath !== '') {
        $candidates[] = $envPath;
    }

    $candidates[] = 'C:/xampp_baru/secure/zurie_pg_live_config.php';
    $candidates[] = dirname(__DIR__) . '/config/pg_live_lookup_config.php';

    return array_values(array_unique($candidates));
}

function zurie_pg_live_config(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $loaded = [];
    $configPath = '';
    foreach (zurie_pg_live_config_candidates() as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $value = require $candidate;
        if (is_array($value)) {
            $loaded = $value;
            $configPath = $candidate;
            break;
        }
    }

    $runtimeSettings = zurie_pg_live_load_settings();
    $configuredSemester = (int)($loaded['semester'] ?? 0);
    $configuredStatus = trim((string)($loaded['active_status'] ?? ''));
    $configuredSession = trim((string)($loaded['academic_session'] ?? ''));

    $runtimeSemester = (int)($runtimeSettings['pg_active_semester'] ?? 0);
    $runtimeStatus = trim((string)($runtimeSettings['pg_active_status'] ?? ''));
    $runtimeSession = trim((string)($runtimeSettings['academic_session'] ?? ''));

    $cached = [
        'enabled' => (bool)($loaded['enabled'] ?? false),
        'host' => trim((string)($loaded['host'] ?? '')),
        'port' => max(1, min(65535, (int)($loaded['port'] ?? 5432))),
        'dbname' => trim((string)($loaded['dbname'] ?? $loaded['database'] ?? '')),
        'user' => trim((string)($loaded['user'] ?? $loaded['username'] ?? '')),
        'password' => (string)($loaded['password'] ?? ''),
        'sslmode' => trim((string)($loaded['sslmode'] ?? 'prefer')) ?: 'prefer',
        'semester' => $runtimeSemester > 0 ? $runtimeSemester : $configuredSemester,
        'active_status' => $runtimeStatus !== '' ? $runtimeStatus : $configuredStatus,
        'academic_session' => $runtimeSession !== '' ? $runtimeSession : $configuredSession,
        'settings_source' => $runtimeSemester > 0 || $runtimeStatus !== '' || $runtimeSession !== '' ? 'mysql' : 'config',
        'connect_timeout' => max(2, min(15, (int)($loaded['connect_timeout'] ?? 5))),
        'config_path' => $configPath,
    ];

    return $cached;
}

function zurie_pg_live_connection_ready(array $config): bool
{
    return !empty($config['enabled'])
        && trim((string)($config['host'] ?? '')) !== ''
        && trim((string)($config['dbname'] ?? '')) !== ''
        && trim((string)($config['user'] ?? '')) !== ''
        && (string)($config['password'] ?? '') !== '';
}


function zurie_pg_live_active_lookup_ready(array $config): bool
{
    return zurie_pg_live_connection_ready($config)
        && (int)($config['semester'] ?? 0) > 0
        && trim((string)($config['active_status'] ?? '')) !== '';
}

function zurie_pg_live_config_ready(array $config): bool
{
    return zurie_pg_live_connection_ready($config)
        && (int)($config['semester'] ?? 0) > 0
        && trim((string)($config['active_status'] ?? '')) !== ''
        && trim((string)($config['academic_session'] ?? '')) !== '';
}

function zurie_pg_live_connect(?array $config = null): PDO
{
    $config ??= zurie_pg_live_config();

    if (!zurie_pg_live_connection_ready($config)) {
        throw new RuntimeException('Konfigurasi sambungan PostgreSQL langsung belum lengkap.');
    }
    if (!class_exists('PDO') || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO PostgreSQL tidak aktif pada PHP server NOC.');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=%d',
        $config['host'],
        (int)$config['port'],
        $config['dbname'],
        $config['sslmode'],
        (int)$config['connect_timeout']
    );

    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function zurie_pg_live_student_sql(): string
{
    return <<<'SQL'
SELECT
    personal.nomatrik AS matrik,
    personal.nama AS nama,
    personal.nokp AS nokp,
    COALESCE(NULLIF(TRIM(personal.nohp), ''), NULLIF(TRIM(personal.notel), '')) AS nohp,
    personal.jantina AS jantina,

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

    kl.kuliah_nama AS kuliah,
    pr.praktikum_nama AS praktikum,
    t.tutoran_nama AS tutoran,
    e.english_nama AS english,
    kk.koko_nama AS kokurikulum,
    jp.jp_jurusan AS jurusan,
    'AKTIF' AS status,
    pelajar.stud_intake AS stud_intake,
    pelajar.stud_semester AS stud_semester,
    pelajar.stud_status AS stud_status,
    'aktif' AS registration_status,
    'postgres_active' AS identity_source

FROM public.personal
INNER JOIN public.pelajar
    ON REPLACE(REPLACE(COALESCE(pelajar.stud_kp, ''), '-', ''), ' ', '')
     = REPLACE(REPLACE(COALESCE(personal.nokp, ''), '-', ''), ' ', '')
LEFT JOIN public.jurusan_pelajar jp
    ON REPLACE(REPLACE(COALESCE(jp.jp_nokp, ''), '-', ''), ' ', '')
     = REPLACE(REPLACE(COALESCE(personal.nokp, ''), '-', ''), ' ', '')
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

WHERE UPPER(personal.nomatrik) = :matrik
  AND REPLACE(REPLACE(COALESCE(personal.nokp, ''), '-', ''), ' ', '') = :nokp
  AND pelajar.stud_semester = :semester
  AND pelajar.stud_status = :active_status
ORDER BY pelajar.stud_intake, personal.nomatrik
LIMIT 1
SQL;
}

function zurie_pg_live_lookup_student(PDO $pgsql, string $matrik, string $nokpDigits, ?array $config = null): ?array
{
    $config ??= zurie_pg_live_config();
    $stmt = $pgsql->prepare(zurie_pg_live_student_sql());
    $stmt->bindValue(':matrik', strtoupper($matrik), PDO::PARAM_STR);
    $stmt->bindValue(':nokp', preg_replace('/\D+/', '', $nokpDigits) ?? '', PDO::PARAM_STR);
    $stmt->bindValue(':semester', (int)$config['semester'], PDO::PARAM_INT);
    $stmt->bindValue(':active_status', (string)$config['active_status'], PDO::PARAM_STR);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}


/**
 * Cari identiti dalam data mentah personal walaupun pendaftaran fizikal belum aktif.
 * Rekod ini hanya membenarkan upload awal; ia tidak disync ke table senarai sebagai AKTIF.
 */
function zurie_pg_live_raw_student_sql(): string
{
    return <<<'SQL'
SELECT
    personal.nomatrik AS matrik,
    personal.nama AS nama,
    personal.nokp AS nokp,
    COALESCE(NULLIF(TRIM(personal.nohp), ''), NULLIF(TRIM(personal.notel), '')) AS nohp,
    personal.jantina AS jantina,
    ''::text AS asrama,
    ''::text AS kuliah,
    ''::text AS praktikum,
    ''::text AS tutoran,
    ''::text AS english,
    ''::text AS kokurikulum,
    ''::text AS jurusan,
    'PENDING'::text AS status,
    NULL::text AS stud_intake,
    NULL::integer AS stud_semester,
    NULL::text AS stud_status,
    'pending'::text AS registration_status,
    'postgres_raw'::text AS identity_source
FROM public.personal
WHERE UPPER(personal.nomatrik) = :matrik
  AND REPLACE(REPLACE(COALESCE(personal.nokp, ''), '-', ''), ' ', '') = :nokp
LIMIT 1
SQL;
}

function zurie_pg_live_lookup_raw_student(PDO $pgsql, string $matrik, string $nokpDigits): ?array
{
    $stmt = $pgsql->prepare(zurie_pg_live_raw_student_sql());
    $stmt->bindValue(':matrik', strtoupper($matrik), PDO::PARAM_STR);
    $stmt->bindValue(':nokp', preg_replace('/\D+/', '', $nokpDigits) ?? '', PDO::PARAM_STR);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Semak aktif dahulu, kemudian fallback kepada rekod mentah personal.
 *
 * status: active | pending_registration | not_found
 */
function zurie_pg_live_check_registration(PDO $mysql, string $matrik, string $nokpDigits, ?array $config = null): array
{
    $config ??= zurie_pg_live_config();
    $pgsql = zurie_pg_live_connect($config);

    try {
        // Semakan AKTIF perlukan semester + kod aktif. Jika tetapan itu belum lengkap,
        // jangan gagalkan terus; teruskan semakan RAW supaya pelajar masih boleh upload awal.
        if (zurie_pg_live_active_lookup_ready($config)) {
            $active = zurie_pg_live_lookup_student($pgsql, $matrik, $nokpDigits, $config);
            if (is_array($active)) {
                $synced = zurie_pg_live_sync_student_to_mysql($mysql, $active);
                $synced['registration_status'] = 'aktif';
                $synced['identity_source'] = 'postgres_active';
                return [
                    'status' => 'active',
                    'student' => $synced,
                    'message' => 'Pendaftaran fizikal aktif disahkan.',
                ];
            }
        }

        $raw = zurie_pg_live_lookup_raw_student($pgsql, $matrik, $nokpDigits);
        if (is_array($raw)) {
            $message = zurie_pg_live_active_lookup_ready($config)
                ? 'Identiti ditemui dalam data mentah tetapi pendaftaran fizikal belum aktif.'
                : 'Identiti ditemui dalam data mentah. Tetapan semester/kod aktif belum lengkap, jadi rekod ditahan untuk semakan admin.';
            return [
                'status' => 'pending_registration',
                'student' => $raw,
                'message' => $message,
            ];
        }

        return [
            'status' => 'not_found',
            'student' => null,
            'message' => 'No Matrik dan No KP tidak sepadan.',
        ];
    } finally {
        $pgsql = null;
    }
}

function zurie_pg_live_ensure_senarai_table(PDO $mysql): void
{
    static $ensuredConnections = [];
    $connectionId = spl_object_id($mysql);
    if (isset($ensuredConnections[$connectionId])) {
        return;
    }

    $mysql->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS senarai (
    id INT AUTO_INCREMENT PRIMARY KEY,
    matrik VARCHAR(30) NOT NULL UNIQUE,
    nama VARCHAR(255) NOT NULL,
    nokp VARCHAR(30) NOT NULL,
    nohp VARCHAR(30) NULL,
    jantina VARCHAR(20) NULL,
    asrama VARCHAR(100) NULL,
    kuliah VARCHAR(100) NULL,
    praktikum VARCHAR(100) NULL,
    tutoran VARCHAR(100) NULL,
    english VARCHAR(100) NULL,
    kokurikulum VARCHAR(100) NULL,
    jurusan VARCHAR(100) NULL,
    stud_intake VARCHAR(30) NULL,
    stud_semester INT NULL,
    stud_status VARCHAR(30) NULL,
    status VARCHAR(30) DEFAULT 'AKTIF',
    synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_senarai_nokp (nokp),
    INDEX idx_senarai_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $columns = [
        'nohp' => "ALTER TABLE senarai ADD COLUMN nohp VARCHAR(30) NULL AFTER nokp",
        'stud_intake' => "ALTER TABLE senarai ADD COLUMN stud_intake VARCHAR(30) NULL AFTER jurusan",
        'stud_semester' => "ALTER TABLE senarai ADD COLUMN stud_semester INT NULL AFTER stud_intake",
        'stud_status' => "ALTER TABLE senarai ADD COLUMN stud_status VARCHAR(30) NULL AFTER stud_semester",
        'synced_at' => "ALTER TABLE senarai ADD COLUMN synced_at DATETIME DEFAULT CURRENT_TIMESTAMP",
    ];

    $check = $mysql->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='senarai' AND COLUMN_NAME=?");
    foreach ($columns as $column => $sql) {
        $check->execute([$column]);
        if ((int)$check->fetchColumn() === 0) {
            $mysql->exec($sql);
        }
    }

    $ensuredConnections[$connectionId] = true;
}

function zurie_pg_live_sync_student_to_mysql(PDO $mysql, array $student): array
{
    zurie_pg_live_ensure_senarai_table($mysql);

    $cleanDigits = static fn($value): string => preg_replace('/\D+/', '', (string)$value) ?? '';
    $cleanText = static fn($value): string => trim((string)$value);

    $row = [
        'matrik' => strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($student['matrik'] ?? '')) ?? ''),
        'nama' => $cleanText($student['nama'] ?? ''),
        'nokp' => $cleanDigits($student['nokp'] ?? ''),
        'nohp' => $cleanDigits($student['nohp'] ?? ''),
        'jantina' => $cleanText($student['jantina'] ?? ''),
        'asrama' => $cleanText($student['asrama'] ?? ''),
        'kuliah' => $cleanText($student['kuliah'] ?? ''),
        'praktikum' => $cleanText($student['praktikum'] ?? ''),
        'tutoran' => $cleanText($student['tutoran'] ?? ''),
        'english' => $cleanText($student['english'] ?? ''),
        'kokurikulum' => $cleanText($student['kokurikulum'] ?? ''),
        'jurusan' => $cleanText($student['jurusan'] ?? ''),
        'stud_intake' => $cleanText($student['stud_intake'] ?? ''),
        'stud_semester' => (int)($student['stud_semester'] ?? 0),
        'stud_status' => $cleanText($student['stud_status'] ?? ''),
        'status' => 'AKTIF',
    ];

    if ($row['matrik'] === '' || $row['nama'] === '' || $row['nokp'] === '') {
        throw new RuntimeException('Rekod PostgreSQL tidak lengkap untuk diselaraskan.');
    }

    $stmt = $mysql->prepare(<<<'SQL'
INSERT INTO senarai (
    matrik, nama, nokp, nohp, jantina, asrama, kuliah, praktikum,
    tutoran, english, kokurikulum, jurusan,
    stud_intake, stud_semester, stud_status, status, synced_at
) VALUES (
    :matrik, :nama, :nokp, :nohp, :jantina, :asrama, :kuliah, :praktikum,
    :tutoran, :english, :kokurikulum, :jurusan,
    :stud_intake, :stud_semester, :stud_status, :status, NOW()
)
ON DUPLICATE KEY UPDATE
    nama = VALUES(nama),
    nokp = VALUES(nokp),
    nohp = CASE WHEN VALUES(nohp) <> '' THEN VALUES(nohp) ELSE nohp END,
    jantina = CASE WHEN VALUES(jantina) <> '' THEN VALUES(jantina) ELSE jantina END,
    asrama = CASE WHEN VALUES(asrama) <> '' THEN VALUES(asrama) ELSE asrama END,
    kuliah = CASE WHEN VALUES(kuliah) <> '' THEN VALUES(kuliah) ELSE kuliah END,
    praktikum = CASE WHEN VALUES(praktikum) <> '' THEN VALUES(praktikum) ELSE praktikum END,
    tutoran = CASE WHEN VALUES(tutoran) <> '' THEN VALUES(tutoran) ELSE tutoran END,
    english = CASE WHEN VALUES(english) <> '' THEN VALUES(english) ELSE english END,
    kokurikulum = CASE WHEN VALUES(kokurikulum) <> '' THEN VALUES(kokurikulum) ELSE kokurikulum END,
    jurusan = CASE WHEN VALUES(jurusan) <> '' THEN VALUES(jurusan) ELSE jurusan END,
    stud_intake = CASE WHEN VALUES(stud_intake) <> '' THEN VALUES(stud_intake) ELSE stud_intake END,
    stud_semester = CASE WHEN VALUES(stud_semester) > 0 THEN VALUES(stud_semester) ELSE stud_semester END,
    stud_status = CASE WHEN VALUES(stud_status) <> '' THEN VALUES(stud_status) ELSE stud_status END,
    status = 'AKTIF',
    synced_at = NOW()
SQL);
    $stmt->execute($row);

    return $row;
}

function zurie_pg_live_attempt_file(): string
{
    return dirname(__DIR__) . '/data/pg_live_lookup_attempts.json';
}

function zurie_pg_live_attempt_key(string $matrik): string
{
    return hash('sha256', zurie_security_client_ip() . '|' . strtoupper($matrik));
}

function zurie_pg_live_read_attempts(): array
{
    $path = zurie_pg_live_attempt_file();
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function zurie_pg_live_write_attempts(array $attempts): void
{
    $path = zurie_pg_live_attempt_file();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $now = time();
    foreach ($attempts as $key => $entry) {
        if (!is_array($entry) || (int)($entry['updated_at'] ?? 0) < $now - 86400) {
            unset($attempts[$key]);
        }
    }

    @file_put_contents($path, json_encode($attempts, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function zurie_pg_live_rate_remaining(string $matrik): int
{
    $attempts = zurie_pg_live_read_attempts();
    $entry = $attempts[zurie_pg_live_attempt_key($matrik)] ?? [];
    return max(0, (int)($entry['locked_until'] ?? 0) - time());
}

function zurie_pg_live_record_miss(string $matrik): void
{
    $attempts = zurie_pg_live_read_attempts();
    $key = zurie_pg_live_attempt_key($matrik);
    $entry = is_array($attempts[$key] ?? null) ? $attempts[$key] : [];
    $now = time();

    if ((int)($entry['window_started'] ?? 0) < $now - 600) {
        $entry = ['count' => 0, 'window_started' => $now, 'locked_until' => 0];
    }

    $entry['count'] = (int)($entry['count'] ?? 0) + 1;
    $entry['updated_at'] = $now;
    if ($entry['count'] >= 6) {
        $entry['locked_until'] = $now + 600;
    }

    $attempts[$key] = $entry;
    zurie_pg_live_write_attempts($attempts);
}

function zurie_pg_live_clear_attempts(string $matrik): void
{
    $attempts = zurie_pg_live_read_attempts();
    unset($attempts[zurie_pg_live_attempt_key($matrik)]);
    zurie_pg_live_write_attempts($attempts);
}

/**
 * Cari pelajar terus daripada PostgreSQL.
 *
 * Keputusan:
 * - found: aktif dan telah disync ke MySQL senarai
 * - pending_registration: identiti sah dalam personal, belum aktif
 * - not_found: matrik/KP tidak sepadan
 * - rate_limited | unavailable | disabled
 */
function zurie_pg_live_find_and_sync(PDO $mysql, string $matrik, string $nokpDigits): array
{
    $config = zurie_pg_live_config();
    if (empty($config['enabled'])) {
        return ['status' => 'disabled', 'student' => null, 'message' => 'Semakan langsung tidak diaktifkan.'];
    }
    if (!zurie_pg_live_connection_ready($config)) {
        return ['status' => 'unavailable', 'student' => null, 'message' => 'Konfigurasi sambungan PostgreSQL langsung belum lengkap.'];
    }

    $remaining = zurie_pg_live_rate_remaining($matrik);
    if ($remaining > 0) {
        return ['status' => 'rate_limited', 'student' => null, 'retry_after' => $remaining, 'message' => 'Cubaan terlalu kerap.'];
    }

    try {
        $result = zurie_pg_live_check_registration($mysql, $matrik, $nokpDigits, $config);

        if (($result['status'] ?? '') === 'active') {
            zurie_pg_live_clear_attempts($matrik);
            return [
                'status' => 'found',
                'student' => $result['student'],
                'message' => 'Pendaftaran aktif disahkan secara langsung.',
            ];
        }

        if (($result['status'] ?? '') === 'pending_registration') {
            zurie_pg_live_clear_attempts($matrik);
            return [
                'status' => 'pending_registration',
                'student' => $result['student'],
                'message' => 'Identiti sah, tetapi pendaftaran fizikal belum aktif.',
            ];
        }

        zurie_pg_live_record_miss($matrik);
        return ['status' => 'not_found', 'student' => null, 'message' => 'No Matrik dan No KP tidak sepadan.'];
    } catch (Throwable $e) {
        error_log('[ZURIE PG LIVE LOOKUP] ' . $e->getMessage());
        return ['status' => 'unavailable', 'student' => null, 'message' => 'Server pendaftaran tidak dapat dicapai.'];
    }
}

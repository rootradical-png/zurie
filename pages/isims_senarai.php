<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/portal_auth.php';
zurie_portal_require_extract_access();
require_once dirname(__DIR__) . '/lib/pg_runtime_auth.php';
zurie_pg_runtime_gate('isims_senarai', 'i-SIMS Table Senarai');

// Personal NOC Dashboard - i-SIMS Table Senarai PostgreSQL -> CSV
// Guna konfigurasi PostgreSQL yang sama dengan modul ILMU:
// /zurie/config/ilmu_pg_config.php

function ss_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ss_prefix($value, string $fallback): string
{
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    return $value !== '' ? $value : $fallback;
}

function ss_inputs(array $source): array
{
    $limit = isset($source['limit']) ? (int)$source['limit'] : 1500;
    $limit = max(1, min(5000, $limit));

    return [
        'matrik1' => ss_prefix($source['matrik1'] ?? 'MA26', 'MA26'),
        'matrik2' => ss_prefix($source['matrik2'] ?? 'MS26', 'MS26'),
        'nokp1' => ss_prefix($source['nokp1'] ?? '07', '07'),
        'nokp2' => ss_prefix($source['nokp2'] ?? '08', '08'),
        'nokp3' => ss_prefix($source['nokp3'] ?? '09', '09'),
        'limit' => $limit,
        'bom' => isset($source['bom']) && (string)$source['bom'] === '1',
    ];
}

function ss_config(): array
{
    return zurie_pg_runtime_config('isims_senarai');
}

function ss_config_ready(array $config): bool
{
    return $config['host'] !== '' && $config['dbname'] !== '' && $config['user'] !== '';
}

function ss_connect(array $config): PDO
{
    if (!class_exists('PDO') || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO PostgreSQL belum aktif dalam PHP. Aktifkan extension=pdo_pgsql dan restart Apache.');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        $config['host'],
        $config['port'],
        $config['dbname'],
        $config['sslmode'] !== '' ? $config['sslmode'] : 'prefer'
    );

    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}


function ss_mysql_connect(): PDO
{
    $configFile = dirname(__DIR__) . '/config/vault_config.php';
    $db = is_file($configFile) ? require $configFile : [];

    $dsn = $db['dsn'] ?? 'mysql:host=localhost;dbname=zurie_noc;charset=utf8mb4';
    $username = $db['username'] ?? 'root';
    $password = $db['password'] ?? '';

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function ss_mysql_ensure_senarai_table(PDO $mysql): void
{
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
    status VARCHAR(30) DEFAULT 'AKTIF',
    synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_senarai_nokp (nokp),
    INDEX idx_senarai_nohp (nohp),
    INDEX idx_senarai_status (status),
    INDEX idx_senarai_praktikum (praktikum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $columns = [
        'nama' => "ALTER TABLE senarai ADD COLUMN nama VARCHAR(255) NOT NULL DEFAULT ''",
        'nokp' => "ALTER TABLE senarai ADD COLUMN nokp VARCHAR(30) NOT NULL DEFAULT ''",
        'nohp' => "ALTER TABLE senarai ADD COLUMN nohp VARCHAR(30) NULL AFTER nokp",
        'jantina' => "ALTER TABLE senarai ADD COLUMN jantina VARCHAR(20) NULL",
        'asrama' => "ALTER TABLE senarai ADD COLUMN asrama VARCHAR(100) NULL",
        'kuliah' => "ALTER TABLE senarai ADD COLUMN kuliah VARCHAR(100) NULL",
        'praktikum' => "ALTER TABLE senarai ADD COLUMN praktikum VARCHAR(100) NULL",
        'tutoran' => "ALTER TABLE senarai ADD COLUMN tutoran VARCHAR(100) NULL",
        'english' => "ALTER TABLE senarai ADD COLUMN english VARCHAR(100) NULL",
        'kokurikulum' => "ALTER TABLE senarai ADD COLUMN kokurikulum VARCHAR(100) NULL",
        'jurusan' => "ALTER TABLE senarai ADD COLUMN jurusan VARCHAR(100) NULL",
        'status' => "ALTER TABLE senarai ADD COLUMN status VARCHAR(30) DEFAULT 'AKTIF'",
        'synced_at' => "ALTER TABLE senarai ADD COLUMN synced_at DATETIME DEFAULT CURRENT_TIMESTAMP",
    ];

    $check = $mysql->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'senarai' AND COLUMN_NAME = ?");
    foreach ($columns as $column => $alterSql) {
        $check->execute([$column]);
        if ((int)$check->fetchColumn() === 0) {
            $mysql->exec($alterSql);
        }
    }
}

function ss_sync_rows_to_mysql(PDO $pgsql, PDO $mysql, array $input): int
{
    ss_mysql_ensure_senarai_table($mysql);

    $stmt = $pgsql->prepare(ss_sql(true));
    ss_bind($stmt, $input, true);
    $stmt->execute();

    $upsert = $mysql->prepare(<<<'SQL'
INSERT INTO senarai (
    matrik, nama, nokp, nohp, jantina, asrama,
    kuliah, praktikum, tutoran, english,
    kokurikulum, jurusan, status, synced_at
) VALUES (
    :matrik, :nama, :nokp, :nohp, :jantina, :asrama,
    :kuliah, :praktikum, :tutoran, :english,
    :kokurikulum, :jurusan, :status, NOW()
)
ON DUPLICATE KEY UPDATE
    nama = VALUES(nama),
    nokp = VALUES(nokp),
    nohp = VALUES(nohp),
    jantina = VALUES(jantina),
    asrama = VALUES(asrama),
    kuliah = VALUES(kuliah),
    praktikum = VALUES(praktikum),
    tutoran = VALUES(tutoran),
    english = VALUES(english),
    kokurikulum = VALUES(kokurikulum),
    jurusan = VALUES(jurusan),
    status = VALUES(status),
    synced_at = NOW()
SQL);

    $count = 0;
    $mysql->beginTransaction();
    try {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $upsert->execute([
                ':matrik' => trim((string)($row['matrik'] ?? '')),
                ':nama' => trim((string)($row['nama'] ?? '')),
                ':nokp' => preg_replace('/\D+/', '', (string)($row['nokp'] ?? '')),
                ':nohp' => preg_replace('/\D+/', '', (string)($row['nohp'] ?? '')),
                ':jantina' => trim((string)($row['jantina'] ?? '')),
                ':asrama' => trim((string)($row['asrama'] ?? '')),
                ':kuliah' => trim((string)($row['kuliah'] ?? '')),
                ':praktikum' => trim((string)($row['praktikum'] ?? '')),
                ':tutoran' => trim((string)($row['tutoran'] ?? '')),
                ':english' => trim((string)($row['english'] ?? '')),
                ':kokurikulum' => trim((string)($row['kokurikulum'] ?? '')),
                ':jurusan' => trim((string)($row['jurusan'] ?? '')),
                ':status' => trim((string)($row['status'] ?? 'AKTIF')) ?: 'AKTIF',
            ]);
            $count++;
        }
        $mysql->commit();
    } catch (Throwable $e) {
        $mysql->rollBack();
        throw $e;
    }

    return $count;
}


function ss_isims_config(): array
{
    $configFile = 'C:/xampp_baru/secure/isims_mysql_config.php';
    $config = is_file($configFile) ? require $configFile : [];

    // Modul ini WAJIB menyasarkan kedua-dua table. Ini juga memastikan
    // config lama yang hanya mempunyai 'table' tidak menyebabkan satu table
    // sahaja diuji atau disync secara senyap.
    $requiredTables = ['senarai_mis_lengkap', 'senarai'];

    return [
        'config_path' => $configFile,
        'enabled' => (bool)($config['enabled'] ?? false),
        'host' => trim((string)($config['host'] ?? '')),
        'port' => (int)($config['port'] ?? 3306),
        'dbname' => trim((string)($config['dbname'] ?? 'db_pelajarkmp')),
        'user' => trim((string)($config['user'] ?? '')),
        'password' => (string)($config['password'] ?? ''),
        'charset' => trim((string)($config['charset'] ?? 'utf8')) ?: 'utf8',
        'tables' => $requiredTables,
        'timeout' => max(2, min(30, (int)($config['timeout'] ?? 8))),
    ];
}

function ss_isims_identifier(string $value, string $label): string
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        throw new RuntimeException($label . ' tidak sah. Hanya huruf, nombor dan underscore dibenarkan.');
    }
    return '`' . str_replace('`', '``', $value) . '`';
}

function ss_isims_config_ready(array $config): bool
{
    return $config['enabled']
        && $config['host'] !== ''
        && $config['dbname'] !== ''
        && $config['user'] !== ''
        && !empty($config['tables']);
}

function ss_isims_connect(array $config): PDO
{
    if (!ss_isims_config_ready($config)) {
        throw new RuntimeException('Konfigurasi MySQL i-SIMS belum lengkap. Isi C:\\xampp_baru\\secure\\isims_mysql_config.php.');
    }
    if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO MySQL belum aktif dalam PHP.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['dbname'],
        $config['charset']
    );

    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => $config['timeout'],
    ]);
}

function ss_isims_table_columns(PDO $pdo, array $config, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = :schema_name AND TABLE_NAME = :table_name '
        . 'ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([
        ':schema_name' => $config['dbname'],
        ':table_name' => $table,
    ]);

    $columns = [];
    while (($name = $stmt->fetchColumn()) !== false) {
        $columns[strtolower((string)$name)] = (string)$name;
    }
    if (!$columns) {
        throw new RuntimeException(
            'Table ' . $config['dbname'] . '.' . $table . ' tidak ditemui atau akaun tiada akses.'
        );
    }
    return $columns;
}


function ss_isims_target_info(PDO $pdo, array $config, string $table): array
{
    $columns = ss_isims_table_columns($pdo, $config, $table);

    if (isset($columns['matrik'])) {
        $keyColumn = $columns['matrik'];
    } elseif (isset($columns['nomatrik'])) {
        $keyColumn = $columns['nomatrik'];
    } else {
        throw new RuntimeException(
            'Table ' . $config['dbname'] . '.' . $table . ' tidak mempunyai column matrik atau nomatrik.'
        );
    }

    // Probe SELECT yang ringan untuk pastikan akaun boleh membaca table.
    $dbName = ss_isims_identifier($config['dbname'], 'Nama database');
    $tableName = ss_isims_identifier($table, 'Nama table');
    $keyQuoted = ss_isims_identifier($keyColumn, 'Column matrik');
    $pdo->query('SELECT ' . $keyQuoted . ' FROM ' . $dbName . '.' . $tableName . ' LIMIT 1');

    return [
        'table' => $table,
        'columns' => count($columns),
        'key' => $keyColumn,
    ];
}

function ss_isims_validate_targets(PDO $pdo, array $config): array
{
    $expected = ['senarai_mis_lengkap', 'senarai'];
    if ($config['tables'] !== $expected) {
        throw new RuntimeException('Sasaran i-SIMS tidak lengkap. Kedua-dua table wajib: senarai_mis_lengkap dan senarai.');
    }

    $targets = [];
    foreach ($expected as $table) {
        $targets[] = ss_isims_target_info($pdo, $config, $table);
    }

    if (count($targets) !== 2) {
        throw new RuntimeException('Ujian i-SIMS tidak lengkap. Hanya ' . count($targets) . '/2 table berjaya disahkan.');
    }

    return $targets;
}

function ss_normalize_sync_row(array $row): array
{
    return [
        'matrik' => strtoupper(trim((string)($row['matrik'] ?? ''))),
        'nama' => trim((string)($row['nama'] ?? '')),
        'nokp' => preg_replace('/\\D+/', '', (string)($row['nokp'] ?? '')) ?? '',
        'nohp' => preg_replace('/\\D+/', '', (string)($row['nohp'] ?? '')) ?? '',
        'jantina' => trim((string)($row['jantina'] ?? '')),
        'asrama' => trim((string)($row['asrama'] ?? '')),
        'kuliah' => trim((string)($row['kuliah'] ?? '')),
        'praktikum' => trim((string)($row['praktikum'] ?? '')),
        'tutoran' => trim((string)($row['tutoran'] ?? '')),
        'english' => trim((string)($row['english'] ?? '')),
        'kokurikulum' => trim((string)($row['kokurikulum'] ?? '')),
        'jurusan' => trim((string)($row['jurusan'] ?? '')),
        'status' => trim((string)($row['status'] ?? 'AKTIF')) ?: 'AKTIF',
    ];
}

function ss_fetch_source_rows(PDO $pgsql, array $input): array
{
    $sourceStmt = $pgsql->prepare(ss_sql(true));
    ss_bind($sourceStmt, $input, true);
    $sourceStmt->execute();

    $rows = [];
    while ($row = $sourceStmt->fetch(PDO::FETCH_ASSOC)) {
        $normalized = ss_normalize_sync_row($row);
        if ($normalized['matrik'] !== '') {
            $rows[] = $normalized;
        }
    }
    return $rows;
}

function ss_sync_data_to_isims_table(PDO $isims, array $rows, array $config, string $table): array
{
    $available = ss_isims_table_columns($isims, $config, $table);
    $sourceFields = [
        'nama', 'nokp', 'nohp', 'matrik', 'jantina', 'asrama', 'kuliah',
        'praktikum', 'tutoran', 'english', 'kokurikulum', 'jurusan', 'status',
    ];

    $keySource = 'matrik';
    if (isset($available['matrik'])) {
        $keyTarget = $available['matrik'];
    } elseif (isset($available['nomatrik'])) {
        $keyTarget = $available['nomatrik'];
    } else {
        throw new RuntimeException('Table ' . $table . ' mesti mempunyai column matrik atau nomatrik.');
    }

    $fieldMap = [];
    foreach ($sourceFields as $field) {
        if ($field === 'matrik') {
            $fieldMap[$field] = $keyTarget;
        } elseif (isset($available[$field])) {
            $fieldMap[$field] = $available[$field];
        }
    }

    $dbName = ss_isims_identifier($config['dbname'], 'Nama database');
    $tableName = ss_isims_identifier($table, 'Nama table');
    $qualified = $dbName . '.' . $tableName;
    $keyQuoted = ss_isims_identifier($keyTarget, 'Column matrik');

    $existsStmt = $isims->prepare('SELECT 1 FROM ' . $qualified . ' WHERE ' . $keyQuoted . ' = :key_value LIMIT 1');

    $insertColumns = [];
    $insertParams = [];
    foreach ($fieldMap as $source => $target) {
        $insertColumns[] = ss_isims_identifier($target, 'Column ' . $target);
        $insertParams[] = ':' . $source;
    }
    $insertSql = 'INSERT INTO ' . $qualified
        . ' (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertParams) . ')';
    $insertStmt = $isims->prepare($insertSql);

    $updateParts = [];
    foreach ($fieldMap as $source => $target) {
        if ($source === $keySource) {
            continue;
        }
        $updateParts[] = ss_isims_identifier($target, 'Column ' . $target) . ' = :' . $source;
    }
    $updateStmt = $updateParts
        ? $isims->prepare('UPDATE ' . $qualified . ' SET ' . implode(', ', $updateParts) . ' WHERE ' . $keyQuoted . ' = :_key')
        : null;

    $result = [
        'table' => $table,
        'source' => count($rows),
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'mapped_columns' => array_values($fieldMap),
    ];

    foreach ($rows as $data) {
        $params = [];
        foreach ($fieldMap as $source => $target) {
            $params[':' . $source] = $data[$source] ?? '';
        }

        $existsStmt->execute([':key_value' => $data['matrik']]);
        $exists = (bool)$existsStmt->fetchColumn();

        if ($exists) {
            if ($updateStmt === null) {
                $result['skipped']++;
                continue;
            }
            $updateParams = $params;
            unset($updateParams[':matrik']);
            $updateParams[':_key'] = $data['matrik'];
            $updateStmt->execute($updateParams);
            $result['updated']++;
        } else {
            $insertStmt->execute($params);
            $result['inserted']++;
        }
    }

    return $result;
}

function ss_sync_rows_to_isims(PDO $pgsql, PDO $isims, array $input, array $config): array
{
    // Preflight WAJIB: sync tidak bermula jika mana-mana satu daripada dua
    // table tidak ditemui, tidak boleh dibaca atau tiada kunci matrik.
    ss_isims_validate_targets($isims, $config);

    $rows = ss_fetch_source_rows($pgsql, $input);
    $results = [];

    $isims->beginTransaction();
    try {
        foreach ($config['tables'] as $table) {
            $results[] = ss_sync_data_to_isims_table($isims, $rows, $config, $table);
        }
        $isims->commit();
    } catch (Throwable $e) {
        if ($isims->inTransaction()) {
            $isims->rollBack();
        }
        throw $e;
    }

    return $results;
}

function ss_sql(bool $withLimit = true): string
{
    $sql = <<<'SQL'
SELECT
    personal.nama AS "nama",
    personal.nokp AS "nokp",
    COALESCE(NULLIF(TRIM(personal.nohp), ''), NULLIF(TRIM(personal.notel), '')) AS "nohp",
    personal.nomatrik AS "matrik",
    personal.jantina AS "jantina",

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
    END AS "asrama",

    kl.kuliah_nama AS "kuliah",
    pr.praktikum_nama AS "praktikum",
    t.tutoran_nama AS "tutoran",
    e.english_nama AS "english",
    kk.koko_nama AS "kokurikulum",
    jurusan_pelajar.jp_jurusan AS "jurusan",
    'AKTIF' AS "status"

FROM public.personal
INNER JOIN public.pelajar
    ON public.pelajar.stud_kp = personal.nokp
INNER JOIN public.jurusan_pelajar
    ON jurusan_pelajar.jp_nokp = personal.nokp
INNER JOIN public.status_pendaftaran
    ON status_pendaftaran.status_kp = personal.nokp
LEFT JOIN public.asrama a
    ON a.asr_profileid = personal.profileid
LEFT JOIN public.katil k
    ON k.ktl_id = a.asr_katil
LEFT JOIN public.blok b
    ON b.blok_id = k.ktl_blok
LEFT JOIN public.tutoran t
    ON t.tutoran_id = jurusan_pelajar.jp_tutoran
LEFT JOIN public.kuliah kl
    ON kl.kuliah_id = t.tutoran_kuliah
LEFT JOIN public.praktikum pr
    ON pr.praktikum_id = t.tutoran_praktikum
LEFT JOIN public.english e
    ON e.english_id = jurusan_pelajar.jp_english
LEFT JOIN public.koko kk
    ON kk.koko_id = jurusan_pelajar.jp_koko

WHERE (
        personal.nomatrik ILIKE :matrik1_pattern
        OR personal.nomatrik ILIKE :matrik2_pattern
      )
  AND (
        personal.nokp LIKE :nokp1_pattern
        OR personal.nokp LIKE :nokp2_pattern
        OR personal.nokp LIKE :nokp3_pattern
      )
  AND status_pendaftaran.status_daftar = '1'
  AND t.tutoran_nama IS NOT NULL
ORDER BY personal.nomatrik
SQL;

    return $withLimit ? $sql . "\nLIMIT :row_limit" : $sql;
}

function ss_bind(PDOStatement $stmt, array $input, bool $withLimit = true): void
{
    $stmt->bindValue(':matrik1_pattern', $input['matrik1'] . '%', PDO::PARAM_STR);
    $stmt->bindValue(':matrik2_pattern', $input['matrik2'] . '%', PDO::PARAM_STR);
    $stmt->bindValue(':nokp1_pattern', $input['nokp1'] . '%', PDO::PARAM_STR);
    $stmt->bindValue(':nokp2_pattern', $input['nokp2'] . '%', PDO::PARAM_STR);
    $stmt->bindValue(':nokp3_pattern', $input['nokp3'] . '%', PDO::PARAM_STR);

    if ($withLimit) {
        $stmt->bindValue(':row_limit', $input['limit'], PDO::PARAM_INT);
    }
}

function ss_headers(string $sql): array
{
    preg_match_all('/\sAS\s+"([^"]+)"/i', $sql, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

function ss_public_headers(string $sql): array
{
    // CSV dan preview kekal format asal.
    // nohp hanya digunakan untuk sync MySQL table senarai, bukan untuk fail CSV.
    return array_values(array_filter(ss_headers($sql), static function ($header) {
        return $header !== 'nohp';
    }));
}

function ss_filename(array $input): string
{
    return sprintf(
        'SENARAI_%s_%s_%s.csv',
        $input['matrik1'],
        $input['matrik2'],
        date('Ymd_His')
    );
}

$input = ss_inputs($_POST ?: $_GET);
$config = ss_config();
$isimsConfig = ss_isims_config();
$action = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? (string)($_POST['action'] ?? '') : '';
if ($action !== '') { zurie_security_require_valid_csrf(); }
$error = '';
$success = '';
$previewRows = [];
$previewCount = null;



if ($action === 'test_isims') {
    try {
        $isims = ss_isims_connect($isimsConfig);
        $targets = ss_isims_validate_targets($isims, $isimsConfig);
        $found = [];
        foreach ($targets as $target) {
            $found[] = sprintf(
                '✓ %s.%s (%d column, kunci: %s)',
                $isimsConfig['dbname'],
                $target['table'],
                $target['columns'],
                $target['key']
            );
        }
        $success = 'Sambungan i-SIMS berjaya. 2/2 table disahkan: '
            . implode(' | ', $found)
            . '. Sync hanya bermula jika kedua-dua table lulus; jika satu gagal, semua perubahan akan rollback.';
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($action === 'sync_isims') {
    try {
        if (!ss_config_ready($config)) {
            throw new RuntimeException('Konfigurasi PostgreSQL belum lengkap.');
        }
        $pgsql = ss_connect($config);
        $isims = ss_isims_connect($isimsConfig);
        $syncResults = ss_sync_rows_to_isims($pgsql, $isims, $input, $isimsConfig);
        $parts = [];
        foreach ($syncResults as $syncResult) {
            $parts[] = sprintf(
                '%s — sumber: %d, baharu: %d, dikemas kini: %d, dilangkau: %d',
                $syncResult['table'],
                $syncResult['source'],
                $syncResult['inserted'],
                $syncResult['updated'],
                $syncResult['skipped']
            );
        }
        $success = 'Sync terus ke i-SIMS berjaya. ' . implode(' | ', $parts) . '. Tiada rekod lama dipadam.';
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($action === 'sync_mysql') {
    try {
        if (!ss_config_ready($config)) {
            throw new RuntimeException('Konfigurasi PostgreSQL belum lengkap. Isi fail /zurie/config/ilmu_pg_config.php dahulu.');
        }

        $pgsql = ss_connect($config);
        $mysql = ss_mysql_connect();
        $synced = ss_sync_rows_to_mysql($pgsql, $mysql, $input);
        $success = 'Sync ke MySQL berjaya. ' . $synced . ' rekod telah dimasukkan/dikemas kini dalam table senarai.';
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($action === 'export') {
    try {
        if (!ss_config_ready($config)) {
            throw new RuntimeException('Konfigurasi PostgreSQL belum lengkap. Isi fail /zurie/config/ilmu_pg_config.php dahulu.');
        }

        $pdo = ss_connect($config);
        $exportSql = ss_sql(true);
        $stmt = $pdo->prepare($exportSql);
        ss_bind($stmt, $input, true);
        $stmt->execute();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . ss_filename($input) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new RuntimeException('Tidak dapat membuka output CSV.');
        }

        if ($input['bom']) {
            fwrite($out, "\xEF\xBB\xBF");
        }

        $headers = ss_public_headers(ss_sql(false));
        fputcsv($out, zurie_security_csv_row($headers), ',', '"', '\\', "\r\n");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ordered = [];
            foreach ($headers as $header) {
                $ordered[] = $row[$header] ?? '';
            }
            fputcsv($out, zurie_security_csv_row($ordered), ',', '"', '\\', "\r\n");
        }

        fclose($out);
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($action === 'preview' || $action === 'test') {
    try {
        if (!ss_config_ready($config)) {
            throw new RuntimeException('Konfigurasi PostgreSQL belum lengkap. Isi fail /zurie/config/ilmu_pg_config.php dahulu.');
        }

        $pdo = ss_connect($config);

        if ($action === 'test') {
            $pdo->query('SELECT 1');
            $success = 'Sambungan PostgreSQL berjaya.';
        } else {
                $baseSql = ss_sql(false);
            $countSql = 'SELECT COUNT(*) FROM (' . $baseSql . ') AS senarai_count';
            $countStmt = $pdo->prepare($countSql);
            ss_bind($countStmt, $input, false);
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();
            $previewCount = min($total, $input['limit']);

            $previewSql = $baseSql . "\nLIMIT 20";
            $previewStmt = $pdo->prepare($previewSql);
            ss_bind($previewStmt, $input, false);
            $previewStmt->execute();
            $previewRows = $previewStmt->fetchAll(PDO::FETCH_ASSOC);
            $success = 'Query berjaya. ' . $previewCount . ' rekod akan dieksport berdasarkan limit semasa.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$pgsqlDriverAvailable = class_exists('PDO') && in_array('pgsql', PDO::getAvailableDrivers(), true);
$mysqlDriverAvailable = class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true);
$configExists = is_file($config['config_path']);
$isimsConfigExists = is_file($isimsConfig['config_path']);
$isimsReady = ss_isims_config_ready($isimsConfig);
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>i-SIMS Table Senarai CSV</title>
<link rel="icon" href="/zurie/image/zuriex.jpg">
<style>
:root{--bg:#07111f;--card:#0d1c2e;--line:rgba(130,170,210,.18);--text:#eaf4ff;--muted:#86a0b8;--cyan:#55d9ff;--green:#51e3a4;--red:#ff7183}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top left,#123456 0,#07111f 36%,#040b14 100%);color:var(--text);font-family:Segoe UI,Arial,sans-serif;font-size:13px}.wrap{max-width:1400px;margin:0 auto;padding:18px}.top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px}.top a{color:#9ddfff;text-decoration:none}.title h1{margin:0;font-size:22px}.title p{margin:4px 0 0;color:var(--muted)}.card{background:linear-gradient(145deg,rgba(13,28,46,.96),rgba(8,18,31,.96));border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.25);padding:16px;margin-bottom:14px}.status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.status{padding:10px;border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.02)}.status span{display:block;color:var(--muted);font-size:10px}.status b{display:block;margin-top:3px}.ok{color:var(--green)}.bad{color:var(--red)}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.field label{display:block;color:var(--muted);font-size:11px;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em}.field input{width:100%;border:1px solid rgba(130,170,210,.25);background:#081523;color:var(--text);border-radius:10px;padding:10px 11px;outline:none}.field input:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(85,217,255,.09)}.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}.btn{border:1px solid rgba(85,217,255,.32);background:rgba(85,217,255,.10);color:#bff0ff;border-radius:10px;padding:10px 13px;text-decoration:none;cursor:pointer;font-weight:700}.tip{position:relative;display:inline-flex;align-items:center;gap:6px}.tip .info{display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;border:1px solid currentColor;border-radius:50%;font-size:9px;line-height:1;opacity:.78}.tip:hover::after,.tip:focus-visible::after{content:attr(data-tip);position:absolute;left:50%;bottom:calc(100% + 9px);transform:translateX(-50%);width:280px;max-width:75vw;padding:9px 11px;border:1px solid rgba(130,170,210,.32);border-radius:9px;background:#020914;color:#eaf4ff;font-size:11px;font-weight:500;line-height:1.45;white-space:normal;box-shadow:0 12px 32px rgba(0,0,0,.45);z-index:50;pointer-events:none}.tip:hover::before,.tip:focus-visible::before{content:'';position:absolute;left:50%;bottom:calc(100% + 3px);transform:translateX(-50%);border:6px solid transparent;border-top-color:#020914;z-index:51}.btn.primary{background:linear-gradient(135deg,rgba(85,217,255,.22),rgba(81,227,164,.14));border-color:rgba(85,217,255,.55)}.btn.export{background:linear-gradient(135deg,rgba(81,227,164,.22),rgba(85,217,255,.12));border-color:rgba(81,227,164,.5);color:#aaffd9}.alert{padding:11px 13px;border-radius:11px;margin-bottom:13px}.alert.error{border:1px solid rgba(255,113,131,.3);background:rgba(255,113,131,.08);color:#ffc1c9}.alert.success{border:1px solid rgba(81,227,164,.28);background:rgba(81,227,164,.08);color:#aaffd9}.setup{color:var(--muted);font-size:12px;line-height:1.6}.setup code{color:#c9efff;background:#06111d;padding:2px 5px;border-radius:5px}.preview-wrap{overflow:auto;max-height:560px;border:1px solid var(--line);border-radius:12px}.preview{width:100%;border-collapse:collapse;font-size:11px;white-space:nowrap}.preview th,.preview td{padding:7px 9px;border-bottom:1px solid rgba(130,170,210,.1);border-right:1px solid rgba(130,170,210,.07);text-align:left}.preview th{position:sticky;top:0;background:#10263d;color:#9de7ff;z-index:1}.preview td{color:#c4d5e4}.check{display:flex;align-items:center;gap:7px;color:var(--muted);font-size:12px}.check input{accent-color:#51e3a4}@media(max-width:850px){.grid{grid-template-columns:repeat(2,1fr)}.status-grid{grid-template-columns:1fr}.top{display:block}.wrap{padding:12px}}@media(max-width:480px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="title">
      <h1>i-SIMS — Table Senarai</h1>
      <p>Ambil data daripada PostgreSQL MIS, preview atau CSV, kemudian pilih destinasi sync: Zurie atau i-SIMS.</p>
    </div>
    <a href="../index.php">← Dashboard</a>
  </div>

  <?php if ($error !== ''): ?><div class="alert error"><?= ss_e($error) ?></div><?php endif; ?>
  <?php if ($success !== ''): ?><div class="alert success"><?= ss_e($success) ?></div><?php endif; ?>

  <section class="card">
    <div class="status-grid">
      <div class="status"><span>PDO PostgreSQL</span><b class="<?= $pgsqlDriverAvailable ? 'ok' : 'bad' ?>"><?= $pgsqlDriverAvailable ? 'AKTIF' : 'TIDAK AKTIF' ?></b></div>
      <div class="status"><span>Config PostgreSQL</span><b class="<?= $configExists ? 'ok' : 'bad' ?>"><?= $configExists ? 'DIJUMPAI' : 'BELUM ADA' ?></b></div>
      <div class="status"><span>PDO MySQL</span><b class="<?= $mysqlDriverAvailable ? 'ok' : 'bad' ?>"><?= $mysqlDriverAvailable ? 'AKTIF' : 'TIDAK AKTIF' ?></b></div>
      <div class="status"><span>Config i-SIMS</span><b class="<?= $isimsConfigExists ? 'ok' : 'bad' ?>"><?= $isimsConfigExists ? 'DIJUMPAI' : 'BELUM ADA' ?></b></div>
      <div class="status"><span>Sasaran i-SIMS</span><b class="<?= $isimsReady ? 'ok' : 'bad' ?>"><?= ss_e($isimsConfig['dbname'] . '.' . implode(' + ', $isimsConfig['tables'])) ?></b></div>
      <div class="status"><span>Kaedah Sync</span><b class="ok">INSERT + UPDATE sahaja</b></div>
    </div>
    <p class="setup">PostgreSQL menggunakan <code>/zurie/config/ilmu_pg_config.php</code>. i-SIMS menggunakan <code>C:\xampp_baru\secure\isims_mysql_config.php</code>. Sync i-SIMS tidak menjalankan TRUNCATE, DELETE atau REPLACE INTO.</p>
  </section>

  <form class="card" method="post" action="isims_senarai.php">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(zurie_security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <div class="grid">
      <div class="field"><label>Prefix Matrik 1</label><input name="matrik1" value="<?= ss_e($input['matrik1']) ?>" maxlength="6"></div>
      <div class="field"><label>Prefix Matrik 2</label><input name="matrik2" value="<?= ss_e($input['matrik2']) ?>" maxlength="6"></div>
      <div class="field"><label>No KP Prefix 1</label><input name="nokp1" value="<?= ss_e($input['nokp1']) ?>" maxlength="4"></div>
      <div class="field"><label>No KP Prefix 2</label><input name="nokp2" value="<?= ss_e($input['nokp2']) ?>" maxlength="4"></div>
      <div class="field"><label>No KP Prefix 3</label><input name="nokp3" value="<?= ss_e($input['nokp3']) ?>" maxlength="4"></div>
      <div class="field"><label>Had Rekod</label><input name="limit" value="<?= (int)$input['limit'] ?>" type="number" min="1" max="5000"></div>
    </div>

    <div class="setup" style="margin-top:14px">
      <b>Ringkas:</b> <span style="color:#9de7ff">Sync ke Zurie</span> untuk modul upload/audit gambar.
      <span style="margin:0 6px;color:#526b82">•</span>
      <span style="color:#aaffd9">Sync ke i-SIMS</span> untuk kemas kini dua table i-SIMS. Arahkan tetikus pada simbol <b>i</b> untuk penerangan.
    </div>

    <div class="actions">
      <button class="btn tip" type="submit" name="action" value="test" data-tip="Uji sambungan ke PostgreSQL MIS. Tiada data diubah." title="Uji sambungan ke PostgreSQL MIS. Tiada data diubah.">Test PostgreSQL <span class="info">i</span></button>
      <button class="btn primary tip" type="submit" name="action" value="preview" data-tip="Papar 20 rekod pertama dan jumlah rekod berdasarkan tapisan semasa. Tiada data disimpan." title="Preview data tanpa menyimpan apa-apa.">Preview Data <span class="info">i</span></button>
      <button class="btn export tip" type="submit" name="action" value="export" data-tip="Muat turun fail CSV dalam format asal. Nombor telefon tidak ditambah ke dalam CSV." title="Download CSV asal tanpa nohp.">Download CSV Asal <span class="info">i</span></button>
      <button class="btn tip" type="submit" name="action" value="test_isims" data-tip="Uji sambungan ke database i-SIMS dan semak kedua-dua table sasaran. Tiada data diubah." title="Uji sambungan database i-SIMS.">Test i-SIMS <span class="info">i</span></button>
      <button class="btn primary tip" type="submit" name="action" value="sync_mysql" data-tip="Sync ke database Zurie, table senarai. Data ini digunakan oleh modul upload gambar, audit dan WhatsApp. Rekod matrik sedia ada dikemas kini; rekod lain tidak dipadam." title="Sync untuk kegunaan modul Zurie." onclick="return confirm('Sync ke database Zurie, table senarai? Rekod lama tidak dipadam.')">Sync ke Zurie <span class="info">i</span></button>
      <button class="btn export tip" type="submit" name="action" value="sync_isims" data-tip="Sync ke database i-SIMS: table senarai_mis_lengkap dan senarai. Rekod matrik sedia ada dikemas kini; rekod lain tidak dipadam." title="Sync ke dua table i-SIMS." onclick="return confirm('Sync terus ke db_pelajarkmp.senarai_mis_lengkap dan db_pelajarkmp.senarai? Rekod matrik sedia ada akan dikemas kini. Rekod lain tidak dipadam.')">Sync ke i-SIMS <span class="info">i</span></button>
      <label class="check"><input type="checkbox" name="bom" value="1" <?= $input['bom'] ? 'checked' : '' ?>> UTF-8 BOM untuk Excel</label>
    </div>
  </form>

  <?php if ($previewCount !== null): ?>
  <section class="card">
    <h2 style="margin-top:0;font-size:15px">Preview 20 rekod pertama — <?= (int)$previewCount ?> rekod akan dieksport</h2>
    <?php if ($previewRows): ?>
    <div class="preview-wrap"><table class="preview">
      <?php $previewHeaders = ss_public_headers(ss_sql(false)); ?>
      <thead><tr><?php foreach ($previewHeaders as $column): ?><th><?= ss_e($column) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($previewRows as $row): ?><tr><?php foreach ($previewHeaders as $column): ?><td><?= ss_e($row[$column] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?><p class="setup">Tiada rekod sepadan dengan tapisan.</p><?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php zurie_pg_runtime_widget('isims_senarai'); ?>
</body>
</html>

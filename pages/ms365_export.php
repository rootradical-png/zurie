<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/portal_auth.php';
zurie_portal_require_extract_access();
require_once dirname(__DIR__) . '/lib/pg_runtime_auth.php';
zurie_pg_runtime_gate('ms365_export', 'Microsoft 365 Student Export');

// Personal NOC Dashboard - Microsoft 365 PostgreSQL -> CSV Export
// Fail: /zurie/pages/ms365_export.php

function ms365_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ms365_clean_prefix($value, string $fallback): string {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    return $value !== '' ? $value : $fallback;
}

function ms365_clean_year($value, int $fallback = 2027): int {
    $year = (int)preg_replace('/[^0-9]/', '', (string)$value);
    if ($year < 2000 || $year > 2099) {
        return $fallback;
    }
    return $year;
}

function ms365_two_digit_year(int $year): string {
    return str_pad((string)($year % 100), 2, '0', STR_PAD_LEFT);
}

function ms365_ic_prefixes_from_year(int $year): array {
    // Contoh KMP: Tahun 2027 -> IC 06, 07, 08.
    // Formula: ambil dua digit tahun dan tolak 20 untuk dapat tahun lahir semasa.
    $mid = ($year % 100) - 20;
    return [
        str_pad((string)(($mid - 1 + 100) % 100), 2, '0', STR_PAD_LEFT),
        str_pad((string)(($mid + 100) % 100), 2, '0', STR_PAD_LEFT),
        str_pad((string)(($mid + 1 + 100) % 100), 2, '0', STR_PAD_LEFT),
    ];
}

function ms365_clean_domain($value, string $fallback): string {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/^https?:\/\//i', '', $value) ?? '';
    $value = trim($value, " \t\n\r\0\x0B/.");
    return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $value) ? $value : $fallback;
}

function ms365_text($value, string $fallback = ''): string {
    $value = trim((string)$value);
    return $value !== '' ? $value : $fallback;
}

function ms365_inputs(array $source): array {
    $limit = isset($source['limit']) ? (int)$source['limit'] : 1500;
    $limit = max(1, min(10000, $limit));
    $batchSize = isset($source['batch_size']) ? (int)$source['batch_size'] : 249;
    $batchSize = max(1, min(249, $batchSize));
    $batchNo = isset($source['batch_no']) ? (int)$source['batch_no'] : 1;
    $batchNo = max(1, $batchNo);

    $year = ms365_clean_year($source['year'] ?? '2027', 2027);
    $yy = ms365_two_digit_year($year);
    [$nokpBefore, $nokpCurrent, $nokpAfter] = ms365_ic_prefixes_from_year($year);

    return [
        'year' => $year,
        'matrik1' => 'MA' . $yy,
        'matrik2' => 'MS' . $yy,
        'nokp1' => $nokpBefore,
        'nokp2' => $nokpCurrent,
        'nokp3' => $nokpAfter,
        'semester' => ms365_clean_prefix($source['semester'] ?? '49', '49'),
        'status' => ms365_clean_prefix($source['status'] ?? '01', '01'),
        'intake' => in_array((string)($source['intake'] ?? 'ALL'), ['ALL','1','2','3','4'], true) ? (string)($source['intake'] ?? 'ALL') : 'ALL',
        'first_name' => ms365_text($source['first_name'] ?? ('KMP_' . $year), 'KMP_' . $year),
        'job_title' => ms365_text($source['job_title'] ?? ('KMP_STUDENT_' . $year), 'KMP_STUDENT_' . $year),
        'department1' => ms365_text($source['department1'] ?? 'Perakaunan', 'Perakaunan'),
        'department2' => ms365_text($source['department2'] ?? 'Sains', 'Sains'),
        'username_domain' => ms365_clean_domain($source['username_domain'] ?? 'kmp365.matrik.edu.my', 'kmp365.matrik.edu.my'),
        'alternate_domain' => ms365_clean_domain($source['alternate_domain'] ?? 'moe-dl.edu.my', 'moe-dl.edu.my'),
        'address' => ms365_text($source['address'] ?? 'KMP', 'KMP'),
        'city' => ms365_text($source['city'] ?? 'Arau', 'Arau'),
        'state' => ms365_text($source['state'] ?? 'Perlis', 'Perlis'),
        'postcode' => ms365_text($source['postcode'] ?? '02600', '02600'),
        'country' => ms365_text($source['country'] ?? 'Malaysia', 'Malaysia'),
        'limit' => $limit,
        'batch_size' => $batchSize,
        'batch_no' => $batchNo,
        'bom' => !isset($source['bom']) || (string)$source['bom'] === '1',
    ];
}

function ms365_config(): array
{
    return zurie_pg_runtime_config('ms365_export');
}

function ms365_config_ready(array $config): bool {
    return $config['host'] !== '' && $config['dbname'] !== '' && $config['user'] !== '';
}

function ms365_connect(array $config): PDO {
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

function ms365_sql(bool $withLimit = true): string {
    $sql = <<<'SQL'
WITH param AS (
    SELECT
        CAST(:matrik1 AS text) AS matrik1,
        CAST(:matrik2 AS text) AS matrik2,
        CAST(:nokp1 AS text) AS nokp1,
        CAST(:nokp2 AS text) AS nokp2,
        CAST(:nokp3 AS text) AS nokp3,
        CAST(:semester AS text) AS semester,
        CAST(:status AS text) AS status,
        CAST(:intake AS text) AS intake,
        CAST(:first_name AS text) AS first_name,
        CAST(:job_title AS text) AS job_title,
        CAST(:department1 AS text) AS department1,
        CAST(:department2 AS text) AS department2,
        CAST(:username_domain AS text) AS username_domain,
        CAST(:alternate_domain AS text) AS alternate_domain,
        CAST(:address AS text) AS address,
        CAST(:city AS text) AS city,
        CAST(:state AS text) AS state,
        CAST(:postcode AS text) AS postcode,
        CAST(:country AS text) AS country
)
SELECT DISTINCT
    UPPER(TRIM(p.nomatrik)) || '@' || param.username_domain AS "Username",
    param.first_name AS "First name",
    UPPER(TRIM(p.nama)) AS "Last name",
    param.first_name || ' ' || UPPER(TRIM(p.nama)) AS "Display name",
    param.job_title AS "Job title",
    CASE
        WHEN p.nomatrik ILIKE param.matrik1 || '%' THEN param.department1
        WHEN p.nomatrik ILIKE param.matrik2 || '%' THEN param.department2
        ELSE COALESCE(NULLIF(TRIM(jp.jp_jurusan), ''), '')
    END AS "Department",
    '' AS "Office number",
    '' AS "Office phone",
    '' AS "Mobile phone",
    '' AS "Fax",
    UPPER(TRIM(p.nomatrik)) || '@' || param.alternate_domain AS "Alternate email address",
    param.address AS "Address",
    param.city AS "City",
    param.state AS "State or province",
    param.postcode AS "ZIP or postal code",
    param.country AS "Country or region"
FROM public.personal p
CROSS JOIN param
INNER JOIN public.pelajar pl
    ON pl.stud_kp = REPLACE(REPLACE(p.nokp, '-', ''), ' ', '')
LEFT JOIN public.jurusan_pelajar jp
    ON jp.jp_nokp = p.nokp
LEFT JOIN public.tutoran t
    ON t.tutoran_id = jp.jp_tutoran
WHERE pl.stud_semester::text = param.semester
  AND pl.stud_status = param.status
  AND (param.intake = 'ALL' OR pl.stud_intake = param.intake)
  AND NULLIF(TRIM(p.nomatrik), '') IS NOT NULL
ORDER BY "Username"
SQL;

    return $withLimit ? $sql . "\nLIMIT :row_limit" : $sql;
}

function ms365_bind(PDOStatement $stmt, array $input, bool $withLimit = true): void {
    $fields = [
        'matrik1', 'matrik2', 'nokp1', 'nokp2', 'nokp3',
        'semester', 'status', 'intake',
        'first_name', 'job_title', 'department1', 'department2',
        'username_domain', 'alternate_domain', 'address', 'city',
        'state', 'postcode', 'country'
    ];

    foreach ($fields as $field) {
        $stmt->bindValue(':' . $field, $input[$field], PDO::PARAM_STR);
    }

    if ($withLimit) {
        $stmt->bindValue(':row_limit', $input['limit'], PDO::PARAM_INT);
    }
}

function ms365_headers(): array {
    return [
        'Username',
        'First name',
        'Last name',
        'Display name',
        'Job title',
        'Department',
        'Office number',
        'Office phone',
        'Mobile phone',
        'Fax',
        'Alternate email address',
        'Address',
        'City',
        'State or province',
        'ZIP or postal code',
        'Country or region',
    ];
}

function ms365_filename(array $input): string {
    return sprintf(
        'MS365_USERS_%s_SEM%s_INTAKE_%s_%s.csv',
        $input['year'],
        $input['semester'],
        $input['intake'],
        date('Ymd_His')
    );
}


function ms365_batch_filename(array $input, int $batchNo, int $batchCount): string {
    return sprintf(
        'MS365_USERS_%s_SEM%s_INTAKE_%s_BATCH_%03d_OF_%03d_%s.csv',
        $input['year'],
        $input['semester'],
        $input['intake'],
        $batchNo,
        $batchCount,
        date('Ymd_His')
    );
}

function ms365_zip_filename(array $input, int $batchCount): string {
    return sprintf(
        'MS365_USERS_%s_SEM%s_INTAKE_%s_%03d_BATCHES_%s.zip',
        $input['year'],
        $input['semester'],
        $input['intake'],
        $batchCount,
        date('Ymd_His')
    );
}

function ms365_fetch_rows(PDO $pdo, array $input): array {
    $stmt = $pdo->prepare(ms365_sql(true));
    ms365_bind($stmt, $input, true);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ms365_csv_content(array $rows, array $input): string {
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Tidak dapat membina CSV sementara.');
    }

    if ($input['bom']) {
        fwrite($stream, "\xEF\xBB\xBF");
    }

    $headers = ms365_headers();
    fputcsv($stream, zurie_security_csv_row($headers), ',', '"', '\\', "\r\n");
    foreach ($rows as $row) {
        $ordered = [];
        foreach ($headers as $header) {
            $ordered[] = $row[$header] ?? '';
        }
        fputcsv($stream, zurie_security_csv_row($ordered), ',', '"', '\\', "\r\n");
    }

    rewind($stream);
    $content = stream_get_contents($stream);
    fclose($stream);
    if ($content === false) {
        throw new RuntimeException('Tidak dapat membaca kandungan CSV.');
    }
    return $content;
}

function ms365_output_csv(array $rows, array $input, string $filename): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen(ms365_csv_content($rows, $input)));
    header('Pragma: no-cache');
    header('Expires: 0');
    echo ms365_csv_content($rows, $input);
    exit;
}


function ms365_isims_config(): array {
    // Gunakan fail config yang sama seperti halaman i-SIMS Extract/Senarai.
    // Fail ini berada di luar htdocs supaya kata laluan DB tidak terdedah.
    $configFile = 'C:/xampp_baru/secure/isims_mysql_config.php';
    $loaded = is_file($configFile) ? require $configFile : [];
    $loaded = is_array($loaded) ? $loaded : [];

    return [
        'config_path' => $configFile,
        'enabled' => (bool)($loaded['enabled'] ?? false),
        'host' => trim((string)($loaded['host'] ?? '')),
        'port' => (int)($loaded['port'] ?? 3306),
        'dbname' => trim((string)($loaded['dbname'] ?? $loaded['database'] ?? 'db_pelajarkmp')),
        'user' => trim((string)($loaded['user'] ?? $loaded['username'] ?? '')),
        'password' => (string)($loaded['password'] ?? ''),
        'charset' => trim((string)($loaded['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
        'timeout' => max(2, min(30, (int)($loaded['timeout'] ?? 8))),
        'table' => 'm365',
    ];
}

function ms365_isims_config_ready(array $config): bool {
    return $config['enabled']
        && $config['host'] !== ''
        && $config['dbname'] !== ''
        && $config['user'] !== '';
}

function ms365_isims_connect(array $config): PDO {
    if (!ms365_isims_config_ready($config)) {
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

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => $config['timeout'],
    ];
    return new PDO($dsn, $config['user'], $config['password'], $options);
}

function ms365_csv_header_key($value): string {
    $value = preg_replace('/^\xEF\xBB\xBF/', '', (string)$value) ?? (string)$value;
    $value = trim($value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/i', '', $value) ?? '';
    return $value;
}

function ms365_detect_csv_delimiter(string $path): string {
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Fail CSV tidak dapat dibuka.');
    }
    $line = '';
    while (!feof($handle)) {
        $candidate = fgets($handle);
        if ($candidate === false) {
            break;
        }
        if (trim($candidate) !== '') {
            $line = $candidate;
            break;
        }
    }
    fclose($handle);
    if ($line === '') {
        throw new RuntimeException('Fail CSV kosong.');
    }

    $bestDelimiter = ',';
    $bestCount = 0;
    foreach ([",", ";", "\t"] as $delimiter) {
        $columns = str_getcsv($line, $delimiter, '"', '\\');
        if (count($columns) > $bestCount) {
            $bestCount = count($columns);
            $bestDelimiter = $delimiter;
        }
    }
    if ($bestCount < 2) {
        throw new RuntimeException('Format CSV tidak dikenal pasti. Pastikan fail mempunyai header Display name, Username, Password dan Licenses.');
    }
    return $bestDelimiter;
}

function ms365_validate_uploaded_password_csv(array $file): string {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException($error === UPLOAD_ERR_NO_FILE
            ? 'Pilih fail CSV kata laluan sementara Microsoft 365 terlebih dahulu.'
            : 'Upload CSV gagal. Kod ralat: ' . $error . '.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('Saiz CSV mesti antara 1 bait hingga 10 MB.');
    }
    $name = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'txt', 'tsv'], true)) {
        throw new RuntimeException('Hanya fail .csv, .txt atau .tsv dibenarkan.');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Fail upload tidak sah.');
    }
    return $tmp;
}

function ms365_extract_student_name(string $displayName, string $nomatrik): string {
    $name = trim($displayName);
    $name = preg_replace('/^KMP[\s_-]*\d{4}\s+/iu', '', $name) ?? $name;
    $name = preg_replace('/^' . preg_quote($nomatrik, '/') . '\s*[-:]?\s*/iu', '', $name) ?? $name;
    return trim($name);
}

function ms365_mask_password(string $password): string {
    $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    if ($length <= 4) {
        return str_repeat('•', max(1, $length));
    }
    $first = function_exists('mb_substr') ? mb_substr($password, 0, 3, 'UTF-8') : substr($password, 0, 3);
    $last = function_exists('mb_substr') ? mb_substr($password, -2, null, 'UTF-8') : substr($password, -2);
    return $first . str_repeat('•', min(10, max(3, $length - 5))) . $last;
}

function ms365_read_password_csv(string $path, int $maxRows = 10000): array {
    $delimiter = ms365_detect_csv_delimiter($path);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Fail CSV tidak dapat dibuka.');
    }

    $header = null;
    $headerLineNo = 0;
    while (($candidateHeader = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $headerLineNo++;
        if (count(array_filter($candidateHeader, static fn($v): bool => trim((string)$v) !== '')) > 0) {
            $header = $candidateHeader;
            break;
        }
    }
    if (!is_array($header)) {
        fclose($handle);
        throw new RuntimeException('Header CSV tidak dijumpai.');
    }

    $positions = [];
    foreach ($header as $index => $column) {
        $positions[ms365_csv_header_key($column)] = $index;
    }

    $aliases = [
        'display_name' => ['displayname', 'display'],
        'username' => ['username', 'userprincipalname', 'upn', 'email', 'emailaddress'],
        'password' => ['password', 'temporarypassword', 'temppassword', 'initialpassword'],
        'licenses' => ['licenses', 'license', 'licences', 'licence'],
    ];
    $columnIndex = [];
    foreach ($aliases as $logical => $keys) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $positions)) {
                $columnIndex[$logical] = $positions[$key];
                break;
            }
        }
    }

    foreach (['display_name', 'username', 'password'] as $required) {
        if (!isset($columnIndex[$required])) {
            fclose($handle);
            throw new RuntimeException('Kolum wajib tidak dijumpai: ' . str_replace('_', ' ', $required) . '. Header diterima: Display name, Username, Password, Licenses.');
        }
    }

    $rowsByMatric = [];
    $issues = [];
    $lineNo = $headerLineNo;
    $duplicates = 0;
    while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $lineNo++;
        if ($data === [null] || $data === [] || count(array_filter($data, static fn($v): bool => trim((string)$v) !== '')) === 0) {
            continue;
        }

        $displayName = trim((string)($data[$columnIndex['display_name']] ?? ''));
        $username = trim((string)($data[$columnIndex['username']] ?? ''));
        $password = (string)($data[$columnIndex['password']] ?? '');
        $licenses = isset($columnIndex['licenses']) ? trim((string)($data[$columnIndex['licenses']] ?? '')) : '';

        if ($username === '' || $password === '') {
            $issues[] = 'Baris ' . $lineNo . ': Username atau Password kosong.';
            continue;
        }
        if (!str_contains($username, '@')) {
            $issues[] = 'Baris ' . $lineNo . ': Username bukan alamat Microsoft 365 yang sah.';
            continue;
        }

        [$localPart] = explode('@', $username, 2);
        $nomatrik = strtoupper(trim($localPart));
        if ($nomatrik === '' || !preg_match('/^[A-Z0-9._-]{4,30}$/', $nomatrik)) {
            $issues[] = 'Baris ' . $lineNo . ': nombor matrik tidak dapat diambil daripada Username.';
            continue;
        }

        $nama = ms365_extract_student_name($displayName, $nomatrik);
        if (isset($rowsByMatric[$nomatrik])) {
            $duplicates++;
        }
        $rowsByMatric[$nomatrik] = [
            'nomatrik' => $nomatrik,
            'nama' => $nama,
            'display_name' => $displayName,
            'username' => $username,
            'password' => $password,
            'licenses' => $licenses,
        ];
        if (count($rowsByMatric) > $maxRows) {
            fclose($handle);
            throw new RuntimeException('CSV melebihi had ' . $maxRows . ' rekod.');
        }
    }
    fclose($handle);

    if (!$rowsByMatric) {
        $detail = $issues ? ' ' . implode(' ', array_slice($issues, 0, 3)) : '';
        throw new RuntimeException('Tiada rekod sah ditemui dalam CSV.' . $detail);
    }

    return [
        'rows' => array_values($rowsByMatric),
        'issues' => $issues,
        'duplicates' => $duplicates,
        'delimiter' => $delimiter === "\t" ? 'TAB' : $delimiter,
    ];
}

function ms365_quote_mysql_identifier(string $value): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        throw new RuntimeException('Nama database, table atau column i-SIMS tidak sah.');
    }
    return '`' . $value . '`';
}

function ms365_isims_table_ref(array $config): string {
    return ms365_quote_mysql_identifier($config['dbname']) . '.' . ms365_quote_mysql_identifier($config['table']);
}

function ms365_isims_identity(PDO $pdo): array {
    $row = $pdo->query('SELECT USER() AS session_user, CURRENT_USER() AS grant_user, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function ms365_isims_permission_message(PDO $pdo, array $config, Throwable $exception): string {
    $identity = [];
    try {
        $identity = ms365_isims_identity($pdo);
    } catch (Throwable $ignored) {
        $identity = [];
    }

    $grantUser = trim((string)($identity['grant_user'] ?? ''));
    $accountText = $grantUser !== '' ? $grantUser : (string)$config['user'];
    $grantSql = '';
    if (preg_match('/^([^@]+)@(.+)$/', $grantUser, $matches)) {
        $user = str_replace("'", "''", $matches[1]);
        $host = str_replace("'", "''", $matches[2]);
        $grantSql = " GRANT SELECT, INSERT, UPDATE ON `{$config['dbname']}`.`{$config['table']}` TO '{$user}'@'{$host}';";
    }

    return 'Akses table ' . $config['dbname'] . '.' . $config['table']
        . ' belum lengkap untuk ' . $accountText . '.'
        . $grantSql
        . ' Ralat MySQL: ' . $exception->getMessage();
}

function ms365_check_isims_table(PDO $pdo, array $config): array {
    $table = ms365_isims_table_ref($config);
    // LIMIT 0 mengesahkan table dan ketiga-tiga kolum tanpa membaca kata laluan.
    $pdo->query("SELECT `nomatrik`, `acc`, `pass` FROM {$table} LIMIT 0");
    $count = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    return [
        'identity' => ms365_isims_identity($pdo),
        'count' => $count,
        'columns' => ['nomatrik', 'acc', 'pass'],
    ];
}

function ms365_fetch_synced_accounts(PDO $pdo, array $config, array $rows, int $limit = 100): array {
    $matrics = [];
    $expected = [];
    foreach ($rows as $row) {
        $matric = strtoupper(trim((string)($row['nomatrik'] ?? '')));
        if ($matric === '' || isset($expected[$matric])) {
            continue;
        }
        $expected[$matric] = trim((string)($row['username'] ?? ''));
        $matrics[] = $matric;
        if (count($matrics) >= $limit) {
            break;
        }
    }
    if (!$matrics) {
        return [];
    }

    $table = ms365_isims_table_ref($config);
    $placeholders = implode(',', array_fill(0, count($matrics), '?'));
    $stmt = $pdo->prepare("SELECT `nomatrik`, `acc` FROM {$table} WHERE `nomatrik` IN ({$placeholders})");
    $stmt->execute($matrics);
    $found = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtoupper(trim((string)($row['nomatrik'] ?? '')));
        if ($key !== '') {
            $found[$key] = trim((string)($row['acc'] ?? ''));
        }
    }

    $result = [];
    foreach ($matrics as $matric) {
        $actual = $found[$matric] ?? '';
        $wanted = $expected[$matric] ?? '';
        $result[] = [
            'nomatrik' => $matric,
            'acc' => $actual,
            'status' => $actual === '' ? 'TIDAK DIJUMPAI' : (strcasecmp($actual, $wanted) === 0 ? 'OK' : 'BERBEZA'),
        ];
    }
    return $result;
}

function ms365_import_password_rows(PDO $pdo, array $config, array $rows): array {
    // Struktur table sebenar: nomatrik | acc | pass.
    // UPSERT memastikan rekod baharu ditambah dan rekod lama dikemas kini berdasarkan PRIMARY KEY nomatrik.
    $table = ms365_isims_table_ref($config);

    $upsertStmt = $pdo->prepare(
        "INSERT INTO {$table} (`nomatrik`, `acc`, `pass`) VALUES (?, ?, ?) "
        . "ON DUPLICATE KEY UPDATE `acc` = VALUES(`acc`), `pass` = VALUES(`pass`)"
    );

    $inserted = 0;
    $updated = 0;
    $unchanged = 0;
    $processed = 0;

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $nomatrik = trim((string)($row['nomatrik'] ?? ''));
            $account = trim((string)($row['username'] ?? ''));
            $password = (string)($row['password'] ?? '');

            if ($nomatrik === '' || $account === '' || $password === '') {
                continue;
            }

            $upsertStmt->execute([$nomatrik, $account, $password]);
            $affected = $upsertStmt->rowCount();
            $processed++;

            // MySQL: 1 = insert, 2 = duplicate dikemas kini, 0 = nilai sama.
            if ($affected === 1) {
                $inserted++;
            } elseif ($affected === 2) {
                $updated++;
            } else {
                $unchanged++;
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($exception instanceof PDOException) {
            $driverCode = (int)($exception->errorInfo[1] ?? 0);
            if ($driverCode === 1142) {
                throw new RuntimeException(ms365_isims_permission_message($pdo, $config, $exception), 0, $exception);
            }
        }
        throw $exception;
    }

    return [
        'processed' => $processed,
        'inserted' => $inserted,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'table_columns' => ['nomatrik', 'acc', 'pass'],
        'columns' => [
            'nomatrik' => 'nomatrik',
            'username' => 'acc',
            'password' => 'pass',
        ],
    ];
}

function ms365_output_isims_password_csv(array $rows): void {
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Tidak dapat membina CSV i-SIMS.');
    }
    fwrite($stream, "\xEF\xBB\xBF");
    // Struktur sebenar table i-SIMS m365: nomatrik | acc | pass.
    $headers = ['nomatrik', 'acc', 'pass'];
    fputcsv($stream, $headers, ',', '"', '\\', "\r\n");
    foreach ($rows as $row) {
        fputcsv($stream, [
            $row['nomatrik'] ?? '',
            $row['username'] ?? '',
            $row['password'] ?? '',
        ], ',', '"', '\\', "\r\n");
    }
    rewind($stream);
    $content = stream_get_contents($stream);
    fclose($stream);
    if ($content === false) {
        throw new RuntimeException('CSV i-SIMS tidak dapat dibaca.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $filename = 'M365_TEMP_PASSWORD_ISIMS_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $content;
    exit;
}

$input = ms365_inputs($_POST ?: $_GET);
$config = ms365_config();
$isimsConfig = ms365_isims_config();
$action = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? (string)($_POST['action'] ?? '') : '';
if ($action !== '') { zurie_security_require_valid_csrf(); }
$error = '';
$success = '';
$previewRows = [];
$previewCount = null;
$previewBatchCount = null;
$passwordCsvRows = [];
$passwordCsvIssues = [];
$passwordCsvDuplicates = 0;
$passwordCsvDelimiter = '';
$passwordImportResult = null;
$isimsTestResult = null;
$syncedAccountRows = [];

if ($action === 'test_isims') {
    try {
        $isimsPdo = ms365_isims_connect($isimsConfig);
        $isimsTestResult = ms365_check_isims_table($isimsPdo, $isimsConfig);
        $grantUser = (string)($isimsTestResult['identity']['grant_user'] ?? $isimsConfig['user']);
        $success = 'i-SIMS OK. Table m365 boleh dibaca. Jumlah rekod: '
            . (int)$isimsTestResult['count'] . '. Akaun DB: ' . $grantUser . '.';
    } catch (Throwable $exception) {
        if ($exception instanceof PDOException && (int)($exception->errorInfo[1] ?? 0) === 1142 && isset($isimsPdo)) {
            $error = ms365_isims_permission_message($isimsPdo, $isimsConfig, $exception);
        } else {
            $error = $exception->getMessage();
        }
    }
}

if (in_array($action, ['preview_password_csv', 'convert_password_csv', 'import_password_csv'], true)) {
    try {
        $uploadedPath = ms365_validate_uploaded_password_csv($_FILES['password_csv'] ?? []);
        $parsed = ms365_read_password_csv($uploadedPath);
        $passwordCsvRows = $parsed['rows'];
        $passwordCsvIssues = $parsed['issues'];
        $passwordCsvDuplicates = (int)$parsed['duplicates'];
        $passwordCsvDelimiter = (string)$parsed['delimiter'];

        if ($action === 'convert_password_csv') {
            ms365_output_isims_password_csv($passwordCsvRows);
        }

        if ($action === 'import_password_csv') {
            $isimsPdo = ms365_isims_connect($isimsConfig);
            $passwordImportResult = ms365_import_password_rows($isimsPdo, $isimsConfig, $passwordCsvRows);
            $syncedAccountRows = ms365_fetch_synced_accounts($isimsPdo, $isimsConfig, $passwordCsvRows, 100);
            $verified = count(array_filter($syncedAccountRows, static fn(array $row): bool => ($row['status'] ?? '') === 'OK'));
            $success = 'Sync ke i-SIMS selesai. Diproses: ' . (int)$passwordImportResult['processed']
                . ', baharu: ' . (int)$passwordImportResult['inserted']
                . ', dikemas kini: ' . (int)$passwordImportResult['updated']
                . ', tiada perubahan: ' . (int)$passwordImportResult['unchanged']
                . '. Disahkan: ' . $verified . '/' . count($syncedAccountRows) . ' akaun.';
        } else {
            $success = 'CSV berjaya dibaca. ' . count($passwordCsvRows) . ' rekod sah ditemui.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if (in_array($action, ['export', 'export_batch', 'export_zip'], true)) {
    try {
        if (!ms365_config_ready($config)) {
            throw new RuntimeException('Konfigurasi PostgreSQL belum lengkap. Isi fail /zurie/config/ilmu_pg_config.php dahulu.');
        }

        $pdo = ms365_connect($config);
        $rows = ms365_fetch_rows($pdo, $input);
        if (!$rows) {
            throw new RuntimeException('Tiada rekod untuk dieksport.');
        }

        $batchSize = $input['batch_size'];
        $batches = array_chunk($rows, $batchSize);
        $batchCount = count($batches);

        if ($action === 'export_batch') {
            $batchNo = $input['batch_no'];
            if ($batchNo > $batchCount) {
                throw new RuntimeException('Nombor batch melebihi jumlah batch. Jumlah tersedia: ' . $batchCount . '.');
            }
            ms365_output_csv(
                $batches[$batchNo - 1],
                $input,
                ms365_batch_filename($input, $batchNo, $batchCount)
            );
        }

        if ($action === 'export_zip') {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('PHP ZipArchive belum aktif. Gunakan butang Download Batch Dipilih atau aktifkan extension=zip.');
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'ms365_');
            if ($tempPath === false) {
                throw new RuntimeException('Tidak dapat mencipta fail ZIP sementara.');
            }

            $zip = new ZipArchive();
            if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                @unlink($tempPath);
                throw new RuntimeException('Tidak dapat membuka fail ZIP sementara.');
            }

            foreach ($batches as $index => $batchRows) {
                $batchNo = $index + 1;
                $zip->addFromString(
                    ms365_batch_filename($input, $batchNo, $batchCount),
                    ms365_csv_content($batchRows, $input)
                );
            }
            $zip->close();

            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . ms365_zip_filename($input, $batchCount) . '"');
            header('Content-Length: ' . filesize($tempPath));
            header('Pragma: no-cache');
            header('Expires: 0');
            readfile($tempPath);
            @unlink($tempPath);
            exit;
        }

        // Pilihan lama: satu CSV penuh untuk simpanan, bukan untuk import batch 249.
        ms365_output_csv($rows, $input, ms365_filename($input));
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($action === 'preview' || $action === 'test') {
    try {
        if (!ms365_config_ready($config)) {
            throw new RuntimeException('Konfigurasi PostgreSQL belum lengkap. Isi fail /zurie/config/ilmu_pg_config.php dahulu.');
        }

        $pdo = ms365_connect($config);

        if ($action === 'test') {
            $pdo->query('SELECT 1');
            $success = 'Sambungan PostgreSQL berjaya.';
        } else {
            $countSql = 'SELECT COUNT(*) FROM (' . ms365_sql(false) . ') AS ms365_export_count';
            $countStmt = $pdo->prepare($countSql);
            ms365_bind($countStmt, $input, false);
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();
            $previewCount = min($total, $input['limit']);
            $previewBatchCount = (int)ceil($previewCount / $input['batch_size']);

            $previewSql = ms365_sql(false) . "\nLIMIT 20";
            $previewStmt = $pdo->prepare($previewSql);
            ms365_bind($previewStmt, $input, false);
            $previewStmt->execute();
            $previewRows = $previewStmt->fetchAll(PDO::FETCH_ASSOC);
            $success = 'Query berjaya. ' . $previewCount . ' rekod akan dipecahkan kepada ' . $previewBatchCount . ' batch (' . $input['batch_size'] . ' rekod setiap fail).';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$pgsqlDriverAvailable = class_exists('PDO') && in_array('pgsql', PDO::getAvailableDrivers(), true);
$mysqlDriverAvailable = class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true);
$configExists = is_file($config['config_path']);
$isimsConfigExists = is_file($isimsConfig['config_path']);
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Microsoft 365 Pelajar</title>
<link rel="icon" href="/zurie/image/zuriex.jpg">
<style>
:root{--bg:#07111f;--card:#0d1c2e;--line:rgba(130,170,210,.18);--text:#eaf4ff;--muted:#86a0b8;--cyan:#55d9ff;--green:#51e3a4;--red:#ff7183}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top left,#123456 0,#07111f 36%,#040b14 100%);color:var(--text);font-family:Segoe UI,Arial,sans-serif;font-size:13px}.wrap{max-width:1400px;margin:0 auto;padding:18px}.top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px}.top-nav{display:flex;gap:12px;flex-wrap:wrap}.top a{color:#9ddfff;text-decoration:none}.title h1{margin:0;font-size:22px}.title p{margin:4px 0 0;color:var(--muted)}.card{background:linear-gradient(145deg,rgba(13,28,46,.96),rgba(8,18,31,.96));border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.25);padding:16px;margin-bottom:14px}.status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.status{padding:10px;border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.02)}.status span{display:block;color:var(--muted);font-size:10px}.status b{display:block;margin-top:3px}.ok{color:var(--green)}.bad{color:var(--red)}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.field label{display:block;color:var(--muted);font-size:10px;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em}.field input,.field select{width:100%;border:1px solid rgba(130,170,210,.25);background:#081523;color:var(--text);border-radius:10px;padding:10px 11px;outline:none}.field input:focus,.field select:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(85,217,255,.09)}.section-label{grid-column:1/-1;margin:4px 0 -2px;color:#9de7ff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}.btn{position:relative;border:1px solid rgba(85,217,255,.32);background:rgba(85,217,255,.10);color:#bff0ff;border-radius:10px;padding:10px 13px;text-decoration:none;cursor:pointer;font-weight:700}.btn[data-tip]::before{content:attr(data-tip);position:absolute;left:50%;bottom:calc(100% + 10px);transform:translateX(-50%) translateY(4px);min-width:210px;max-width:290px;padding:8px 10px;border:1px solid rgba(157,231,255,.28);border-radius:8px;background:#020914;color:#eaf4ff;font-size:11px;font-weight:500;line-height:1.35;text-align:center;white-space:normal;box-shadow:0 10px 24px rgba(0,0,0,.38);opacity:0;visibility:hidden;pointer-events:none;z-index:20;transition:.15s ease}.btn[data-tip]::after{content:"";position:absolute;left:50%;bottom:calc(100% + 4px);transform:translateX(-50%);border:6px solid transparent;border-top-color:#020914;opacity:0;visibility:hidden;pointer-events:none;z-index:21;transition:.15s ease}.btn[data-tip]:hover::before,.btn[data-tip]:focus-visible::before,.btn[data-tip]:hover::after,.btn[data-tip]:focus-visible::after{opacity:1;visibility:visible;transform:translateX(-50%) translateY(0)}.btn.primary{background:linear-gradient(135deg,rgba(85,217,255,.22),rgba(81,227,164,.14));border-color:rgba(85,217,255,.55)}.btn.export{background:linear-gradient(135deg,rgba(81,227,164,.22),rgba(85,217,255,.12));border-color:rgba(81,227,164,.5);color:#aaffd9}.alert{padding:11px 13px;border-radius:11px;margin-bottom:13px}.alert.error{border:1px solid rgba(255,113,131,.3);background:rgba(255,113,131,.08);color:#ffc1c9}.alert.success{border:1px solid rgba(81,227,164,.28);background:rgba(81,227,164,.08);color:#aaffd9}.setup{color:var(--muted);font-size:12px;line-height:1.6}.setup code{color:#c9efff;background:#06111d;padding:2px 5px;border-radius:5px}.preview-wrap{overflow:auto;max-height:520px;border:1px solid var(--line);border-radius:12px}.preview{width:100%;border-collapse:collapse;font-size:11px;white-space:nowrap}.preview th,.preview td{padding:7px 9px;border-bottom:1px solid rgba(130,170,210,.1);border-right:1px solid rgba(130,170,210,.07);text-align:left}.preview th{position:sticky;top:0;background:#10263d;color:#9de7ff;z-index:1}.preview td{color:#c4d5e4}.check{display:flex;align-items:center;gap:7px;color:var(--muted);font-size:12px}.check input{accent-color:#51e3a4}.sample{margin-top:12px;padding:10px;border:1px dashed rgba(85,217,255,.22);border-radius:10px;color:#9db4c8;font-size:11px;line-height:1.6}.sample strong{color:#dff7ff}@media(max-width:920px){.grid{grid-template-columns:repeat(2,1fr)}.status-grid{grid-template-columns:1fr}.top{display:block}.top-nav{margin-top:10px}.wrap{padding:12px}}@media(max-width:520px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="title"><h1>Microsoft 365 Pelajar</h1><p>Jana fail akaun pelajar dan sync kata laluan sementara ke i-SIMS.</p></div>
    <div class="top-nav"><a href="ilmu_export.php">← ILMU GL14</a><a href="delima_sync.php">DELIMa</a><a href="../index.php">Dashboard</a></div>
  </div>

  <?php if ($error !== ''): ?><div class="alert error"><?= ms365_e($error) ?></div><?php endif; ?>
  <?php if ($success !== ''): ?><div class="alert success"><?= ms365_e($success) ?></div><?php endif; ?>

  <section class="card">
    <div class="status-grid">
      <div class="status"><span>PHP PDO PostgreSQL</span><b class="<?= $pgsqlDriverAvailable ? 'ok' : 'bad' ?>"><?= $pgsqlDriverAvailable ? 'AKTIF' : 'TIDAK AKTIF' ?></b></div>
      <div class="status"><span>Fail konfigurasi</span><b class="<?= $configExists ? 'ok' : 'bad' ?>"><?= $configExists ? 'DIJUMPAI' : 'BELUM ADA' ?></b></div>
      <div class="status"><span>Database sasaran</span><b><?= ms365_e($config['host'] !== '' ? $config['host'] . ' / ' . $config['dbname'] : 'Belum dikonfigurasi') ?></b></div>
    </div>
    <div class="sample"><strong>Format:</strong> Data pelajar aktif diambil daripada <code>pelajar.stud_semester</code>, <code>pelajar.stud_status</code> dan intake. Isi tahun semasa sahaja; sistem auto jana prefix matrik MA/MS dan prefix IC sebelum-semasa-selepas.</div>
  </section>

  <form class="card" method="post" action="ms365_export.php">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(zurie_security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <div class="grid">
      <div class="section-label">Tapisan pelajar</div>
      <div class="field"><label>Tahun Semasa</label><input name="year" value="<?= (int)$input['year'] ?>" type="number" min="2000" max="2099"><small style="display:block;margin-top:5px;color:var(--muted)">Contoh 2027 → auto MA27, MS27 dan IC <?= ms365_e($input['nokp1']) ?>/<?= ms365_e($input['nokp2']) ?>/<?= ms365_e($input['nokp3']) ?></small></div>
      <div class="field"><label>Semester</label><input name="semester" value="<?= ms365_e($input['semester']) ?>" maxlength="4"><small style="display:block;margin-top:5px;color:var(--muted)">Default KMP: 49</small></div>
      <div class="field"><label>Status Aktif</label><input name="status" value="<?= ms365_e($input['status']) ?>" maxlength="4"><small style="display:block;margin-top:5px;color:var(--muted)">Default aktif: 01</small></div>
      <div class="field"><label>Intake</label><select name="intake">
        <option value="ALL" <?= $input['intake'] === 'ALL' ? 'selected' : '' ?>>Semua Intake Aktif</option>
        <option value="1" <?= $input['intake'] === '1' ? 'selected' : '' ?>>Intake 1</option>
        <option value="2" <?= $input['intake'] === '2' ? 'selected' : '' ?>>Intake 2</option>
        <option value="3" <?= $input['intake'] === '3' ? 'selected' : '' ?>>Intake 3</option>
        <option value="4" <?= $input['intake'] === '4' ? 'selected' : '' ?>>Intake 4</option>
      </select></div>
      <div class="field"><label>Had Rekod</label><input name="limit" value="<?= (int)$input['limit'] ?>" type="number" min="1" max="10000"></div>
      <div class="field"><label>Auto Matrik</label><input value="<?= ms365_e($input['matrik1'] . ' / ' . $input['matrik2']) ?>" readonly><small style="display:block;margin-top:5px;color:var(--muted)">Dijana daripada Tahun Semasa</small></div>
      <div class="field"><label>Auto IC</label><input value="<?= ms365_e($input['nokp1'] . ' / ' . $input['nokp2'] . ' / ' . $input['nokp3']) ?>" readonly><small style="display:block;margin-top:5px;color:var(--muted)">Sebelum / semasa / selepas</small></div>
      <div class="field"><label>Saiz Batch</label><input name="batch_size" value="<?= (int)$input['batch_size'] ?>" type="number" min="1" max="249"><small style="display:block;margin-top:5px;color:var(--muted)">Maksimum 249 pengguna setiap fail.</small></div>
      <div class="field"><label>Batch Dipilih</label><input name="batch_no" value="<?= (int)$input['batch_no'] ?>" type="number" min="1"><small style="display:block;margin-top:5px;color:var(--muted)">Untuk download satu batch sahaja.</small></div>

      <div class="section-label">Kod jabatan</div>
      <div class="field"><label>Department MA</label><input name="department1" value="<?= ms365_e($input['department1']) ?>"></div>
      <div class="field"><label>Department MS</label><input name="department2" value="<?= ms365_e($input['department2']) ?>"></div>

      <div class="section-label">Maklumat Microsoft 365</div>
      <div class="field"><label>First name</label><input name="first_name" value="<?= ms365_e($input['first_name']) ?>"></div>
      <div class="field"><label>Job title</label><input name="job_title" value="<?= ms365_e($input['job_title']) ?>"></div>
      <div class="field"><label>Username domain</label><input name="username_domain" value="<?= ms365_e($input['username_domain']) ?>"></div>
      <div class="field"><label>Alternate email domain</label><input name="alternate_domain" value="<?= ms365_e($input['alternate_domain']) ?>"></div>

      <div class="section-label">Alamat tetap</div>
      <div class="field"><label>Address</label><input name="address" value="<?= ms365_e($input['address']) ?>"></div>
      <div class="field"><label>City</label><input name="city" value="<?= ms365_e($input['city']) ?>"></div>
      <div class="field"><label>State or province</label><input name="state" value="<?= ms365_e($input['state']) ?>"></div>
      <div class="field"><label>ZIP or postal code</label><input name="postcode" value="<?= ms365_e($input['postcode']) ?>"></div>
      <div class="field"><label>Country or region</label><input name="country" value="<?= ms365_e($input['country']) ?>"></div>
    </div>

    <div class="actions">
      <button class="btn" type="submit" name="action" value="test" title="Uji sambungan ke pangkalan data sumber pelajar" data-tip="Uji sambungan ke pangkalan data sumber pelajar sebelum menjana fail.">Uji Sambungan</button>
      <button class="btn primary" type="submit" name="action" value="preview" title="Semak senarai pelajar sebelum muat turun" data-tip="Paparkan 20 rekod pertama dan jumlah batch yang akan dijana.">Semak Data</button>
      <button class="btn export" type="submit" name="action" value="export_zip" title="Muat turun semua batch dalam satu fail ZIP" data-tip="Jana semua fail CSV mengikut saiz batch dan muat turun sebagai satu ZIP.">Muat Turun Semua Batch</button>
      <button class="btn" type="submit" name="action" value="export_batch" title="Muat turun satu batch yang dipilih" data-tip="Muat turun hanya nombor batch yang diisi pada ruangan Batch Dipilih.">Muat Turun Batch Dipilih</button>
      <a class="btn primary" href="https://admin.cloud.microsoft/?#/homepage" target="_blank" rel="noopener noreferrer" title="Buka Microsoft 365 Admin Center" data-tip="Buka Microsoft 365 Admin Center dalam tab baharu untuk import pengguna.">Microsoft 365 Admin</a>
      <label class="check"><input type="checkbox" name="bom" value="1" <?= $input['bom'] ? 'checked' : '' ?>> UTF-8 BOM untuk Excel</label>
    </div>
  </form>

  <section class="card">
    <div class="top" style="margin-bottom:12px">
      <div class="title">
        <h1 style="font-size:18px">Sync Kata Laluan 365 ke i-SIMS</h1>
        <p>Upload fail CSV daripada Microsoft 365, semak rekod, kemudian klik <b>Sync ke i-SIMS</b>.</p>
      </div>
    </div>

    <div class="status-grid">
      <div class="status"><span>PHP PDO MySQL</span><b class="<?= $mysqlDriverAvailable ? 'ok' : 'bad' ?>"><?= $mysqlDriverAvailable ? 'AKTIF' : 'TIDAK AKTIF' ?></b></div>
      <div class="status"><span>Konfigurasi i-SIMS</span><b class="<?= $isimsConfigExists && ms365_isims_config_ready($isimsConfig) ? 'ok' : 'bad' ?>"><?= $isimsConfigExists && ms365_isims_config_ready($isimsConfig) ? 'SEDIA' : 'BELUM LENGKAP' ?></b></div>
      <div class="status"><span>Table sasaran</span><b><?= ms365_e(($isimsConfig['dbname'] ?: 'db_pelajarkmp') . '.m365') ?></b></div>
    </div>

    <div class="sample">
      <strong>Data yang disimpan:</strong> <code>nomatrik</code> daripada Username, <code>acc</code> untuk akaun penuh dan <code>pass</code> untuk kata laluan sementara.
      Sambungan menggunakan config i-SIMS sedia ada dan memerlukan akses SELECT, INSERT serta UPDATE pada table m365.
    </div>

    <form method="post" action="ms365_export.php" enctype="multipart/form-data" style="margin-top:14px">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(zurie_security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <div class="grid">
        <div class="field" style="grid-column:1/-1">
          <label>Fail CSV Kata Laluan Sementara</label>
          <input type="file" name="password_csv" accept=".csv,.txt,.tsv,text/csv,text/tab-separated-values">
          <small style="display:block;margin-top:6px;color:var(--muted)">Format koma, semicolon atau TAB dikesan secara automatik. Had maksimum 10 MB / 10,000 rekod.</small>
        </div>
      </div>
      <div class="actions">
        <button class="btn" type="submit" name="action" value="test_isims" formnovalidate title="Uji sambungan dan akses table m365" data-tip="Semak sambungan, akses SELECT, struktur nomatrik/acc/pass dan jumlah rekod dalam table m365.">Uji i-SIMS</button>
        <button class="btn primary" type="submit" name="action" value="preview_password_csv" title="Semak kandungan fail sebelum sync" data-tip="Baca fail dan paparkan rekod sah tanpa menyimpan ke i-SIMS.">Semak Fail</button>
        <button class="btn" type="submit" name="action" value="convert_password_csv" title="Muat turun fail dengan kolum nomatrik, acc dan pass" data-tip="Tukar CSV Microsoft 365 kepada format i-SIMS tanpa menyimpan ke database.">Muat Turun CSV i-SIMS</button>
        <button class="btn export" type="submit" name="action" value="import_password_csv" title="Simpan atau kemas kini akaun 365 dalam i-SIMS" data-tip="Masukkan rekod baharu dan kemas kini acc serta pass bagi nombor matrik yang sudah ada." onclick="return confirm('Sync akaun dan kata laluan sementara ke i-SIMS?')">Sync ke i-SIMS</button>
      </div>
    </form>

    <?php if ($isimsTestResult): ?>
      <div class="sample">
        <strong>Semakan i-SIMS:</strong>
        server <?= ms365_e($isimsConfig['host']) ?>,
        database <?= ms365_e((string)($isimsTestResult['identity']['database_name'] ?? $isimsConfig['dbname'])) ?>,
        akaun <?= ms365_e((string)($isimsTestResult['identity']['grant_user'] ?? $isimsConfig['user'])) ?>,
        <?= (int)$isimsTestResult['count'] ?> rekod dalam <code>m365</code>.
      </div>
    <?php endif; ?>

    <?php if ($syncedAccountRows): ?>
      <div class="sample"><strong>Semakan selepas sync:</strong> Akaun dibaca semula daripada i-SIMS. Kata laluan tidak dipaparkan.</div>
      <div class="preview-wrap" style="margin-top:12px;max-height:320px"><table class="preview">
        <thead><tr><th>No. Matrik</th><th>Akaun i-SIMS</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($syncedAccountRows as $row): ?>
          <tr>
            <td><?= ms365_e($row['nomatrik']) ?></td>
            <td><?= ms365_e($row['acc']) ?></td>
            <td class="<?= ($row['status'] ?? '') === 'OK' ? 'ok' : 'bad' ?>"><?= ms365_e($row['status']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if (count($passwordCsvRows) > count($syncedAccountRows)): ?><p class="setup">Semakan dipaparkan untuk 100 rekod pertama sahaja.</p><?php endif; ?>
    <?php endif; ?>

    <?php if ($passwordCsvRows): ?>
      <div class="sample">
        <strong>Hasil bacaan:</strong> <?= count($passwordCsvRows) ?> rekod sah
        · delimiter <?= ms365_e($passwordCsvDelimiter) ?>
        · <?= (int)$passwordCsvDuplicates ?> rekod pendua dalam fail
        · <?= count($passwordCsvIssues) ?> baris tidak sah dilangkau.
        Kata laluan dipaparkan secara terlindung dan hanya disimpan penuh semasa sync atau muat turun CSV.
      </div>
      <div class="preview-wrap" style="margin-top:12px;max-height:360px"><table class="preview">
        <thead><tr><th>No. Matrik</th><th>Nama</th><th>Username</th><th>Temp Password</th><th>Licenses</th></tr></thead>
        <tbody>
          <?php foreach (array_slice($passwordCsvRows, 0, 25) as $row): ?>
          <tr>
            <td><?= ms365_e($row['nomatrik']) ?></td>
            <td><?= ms365_e($row['nama']) ?></td>
            <td><?= ms365_e($row['username']) ?></td>
            <td><?= ms365_e(ms365_mask_password($row['password'])) ?></td>
            <td><?= ms365_e($row['licenses']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if (count($passwordCsvRows) > 25): ?><p class="setup">Preview memaparkan 25 rekod pertama sahaja.</p><?php endif; ?>
      <?php if ($passwordCsvIssues): ?>
        <div class="alert error" style="margin-top:12px">
          <strong>Baris dilangkau:</strong><br>
          <?= ms365_e(implode(' | ', array_slice($passwordCsvIssues, 0, 8))) ?>
          <?= count($passwordCsvIssues) > 8 ? ' ...' : '' ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <?php if ($previewCount !== null): ?>
  <section class="card">
    <h2 style="margin-top:0;font-size:15px">Preview 20 rekod pertama — <?= (int)$previewCount ?> rekod, <?= (int)$previewBatchCount ?> batch</h2>
    <div class="sample"><strong>Pecahan automatik:</strong> setiap CSV mengandungi maksimum <?= (int)$input['batch_size'] ?> pengguna, tidak termasuk baris tajuk. Import fail mengikut turutan BATCH_001, BATCH_002 dan seterusnya.</div>
    <?php if ($previewRows): ?>
    <div class="preview-wrap"><table class="preview">
      <thead><tr><?php foreach (ms365_headers() as $column): ?><th><?= ms365_e($column) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($previewRows as $row): ?><tr><?php foreach (ms365_headers() as $column): ?><td><?= ms365_e($row[$column] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?><p class="setup">Tiada rekod sepadan dengan tapisan.</p><?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php zurie_pg_runtime_widget('ms365_export'); ?>
</body>
</html>

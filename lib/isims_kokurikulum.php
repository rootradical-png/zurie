<?php
/**
 * Shared helpers for i-SIMS legacy Kokurikulum certificate lookup.
 * Uses the existing secure i-SIMS MySQL credential file and never stores
 * database passwords inside the web root/repository.
 */
declare(strict_types=1);

function ik_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ik_secure_config_path(): string
{
    return 'C:/xampp_baru/secure/isims_mysql_config.php';
}

function ik_feature_config_path(): string
{
    return dirname(__DIR__) . '/config/isims_kokurikulum_config.php';
}

function ik_config(): array
{
    $basePath = ik_secure_config_path();
    $base = is_file($basePath) ? require $basePath : [];
    $base = is_array($base) ? $base : [];

    $featurePath = ik_feature_config_path();
    $feature = is_file($featurePath) ? require $featurePath : [];
    $feature = is_array($feature) ? $feature : [];

    $assetDefaults = [
        // Guna URL penuh supaya tidak terbentuk /image/image/... apabila
        // asset_base_urls sudah berakhir dengan /esasi/image/.
        'logo_kpm' => 'http://i-sims.kmp.matrik.edu.my/esasi/image/logokpm.png',
        'logo_kmp' => 'http://i-sims.kmp.matrik.edu.my/esasi/image/logokmp.jpg',
        'stamp_kmp' => 'http://i-sims.kmp.matrik.edu.my/esasi/image/coplogokmp.png',
        'director_signature' => 'http://i-sims.kmp.matrik.edu.my/esasi/image/signpeng.png',
    ];

    return [
        'enabled' => (bool)($base['enabled'] ?? false),
        'host' => trim((string)($base['host'] ?? '')),
        'port' => (int)($base['port'] ?? 3306),
        'user' => trim((string)($base['user'] ?? $base['username'] ?? '')),
        'password' => (string)($base['password'] ?? ''),
        'charset' => trim((string)($base['charset'] ?? 'utf8')) ?: 'utf8',
        'timeout' => max(2, min(30, (int)($base['timeout'] ?? 8))),
        'database_exclude' => array_values(array_unique(array_merge(
            ['information_schema', 'mysql', 'performance_schema', 'sys'],
            array_map('strval', (array)($feature['database_exclude'] ?? []))
        ))),
        // Dropdown pelajar ditapis secara dinamik. Tahun baharu akan muncul
        // automatik apabila akaun MySQL menerima SELECT untuk database itu.
        'student_database_regex' => trim((string)($feature['student_database_regex'] ?? '~^(?:db_pelajarkmp|_pelajarkmp(?:20[0-9]{2})?)$~i')),
        // Backward compatibility untuk patch terdahulu.
        'database_include_regex' => trim((string)($feature['database_include_regex'] ?? '')),
        // Senarai database legacy yang tetap dipaparkan walaupun SHOW DATABASES
        // menyembunyikannya kerana privilege user belum lengkap.
        'student_database_candidates' => array_values(array_unique(array_filter(array_map(
            static fn($v): string => trim((string)$v),
            (array)($feature['student_database_candidates'] ?? array_merge(
                ['db_pelajarkmp', '_pelajarkmp'],
                array_map(static fn(int $year): string => '_pelajarkmp' . $year, range(2013, (int)date('Y') + 1))
            ))
        )))),
        // Paparkan semua database bukan sistem yang akaun aplikasi boleh capai.
        // Tetapan false mengekalkan penapisan nama lama jika diperlukan.
        'show_all_accessible_databases' => (bool)($feature['show_all_accessible_databases'] ?? true),
        'student_photo_base_urls' => array_values(array_filter(array_map(
            static fn($v): string => rtrim(trim((string)$v), '/') . '/',
            (array)($feature['student_photo_base_urls'] ?? [
                'http://i-sims.kmp.matrik.edu.my/esasi/image/',
            ])
        ))),
        'activity_database_candidates' => array_values(array_filter(array_map(
            static fn($v): string => trim((string)$v),
            (array)($feature['activity_database_candidates'] ?? ['db'])
        ))),
        'asset_base_urls' => array_values(array_filter(array_map(
            static fn($v): string => rtrim(trim((string)$v), '/') . '/',
            (array)($feature['asset_base_urls'] ?? [
                'http://i-sims.kmp.matrik.edu.my/esasi/image/',
                'http://i-sims.kmp.matrik.edu.my/',
                'http://www.kmp.matrik.edu.my/isims/',
            ])
        ))),
        'assets' => array_replace($assetDefaults, (array)($feature['assets'] ?? [])),
        'director_name' => trim((string)($feature['director_name'] ?? 'KHAIRINA BINTI SUBARI')),
        'college_name' => trim((string)($feature['college_name'] ?? 'KOLEJ MATRIKULASI PERLIS')),
        'ministry_name' => trim((string)($feature['ministry_name'] ?? 'KEMENTERIAN PENDIDIKAN')),
        'college_address' => trim((string)($feature['college_address'] ?? '02600 ARAU, PERLIS')),
        'default_session' => trim((string)($feature['default_session'] ?? '')),
        'config_path' => $basePath,
        'feature_config_path' => $featurePath,
    ];
}

function ik_config_ready(array $config): bool
{
    return !empty($config['enabled'])
        && trim((string)$config['host']) !== ''
        && trim((string)$config['user']) !== '';
}

function ik_connect(array $config): PDO
{
    if (!ik_config_ready($config)) {
        throw new RuntimeException('Konfigurasi i-SIMS belum lengkap. Semak C:\\xampp_baru\\secure\\isims_mysql_config.php.');
    }
    if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO MySQL belum aktif dalam PHP.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        $config['host'],
        (int)$config['port'],
        $config['charset']
    );

    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => (int)$config['timeout'],
    ]);
}

function ik_quote_identifier(string $value): string
{
    if ($value === '' || str_contains($value, "\0")) {
        throw new RuntimeException('Nama database/table tidak sah.');
    }
    return '`' . str_replace('`', '``', $value) . '`';
}

/** @return string[] */
function ik_list_accessible_databases(PDO $pdo, array $config): array
{
    $exclude = array_map('strtolower', (array)$config['database_exclude']);
    $result = [];

    // SHOW DATABASES tanpa global SHOW DATABASES masih memulangkan database
    // yang akaun ini mempunyai privilege. Jadi tidak perlu buka akses global.
    foreach ($pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN) as $db) {
        $db = trim((string)$db);
        if ($db === '' || in_array(strtolower($db), $exclude, true)) {
            continue;
        }
        $result[] = $db;
    }

    $result = array_values(array_unique($result));
    natcasesort($result);
    return array_values($result);
}

function ik_is_student_database(string $database, array $config): bool
{
    $regex = trim((string)($config['student_database_regex'] ?? ''));
    if ($regex === '') {
        $regex = trim((string)($config['database_include_regex'] ?? ''));
    }
    if ($regex === '') {
        $regex = '~^(?:db_pelajarkmp|_pelajarkmp(?:20[0-9]{2})?)$~i';
    }
    $valid = @preg_match($regex, '');
    return $valid !== false && preg_match($regex, $database) === 1;
}

function ik_database_year(string $database): int
{
    return preg_match('/(20[0-9]{2})$/', $database, $m) ? (int)$m[1] : 9999;
}

/** @return string[] */
function ik_list_databases(PDO $pdo, array $config): array
{
    $accessible = ik_list_accessible_databases($pdo, $config);
    $candidates = array_values(array_filter(array_map(
        static fn($v): string => trim((string)$v),
        (array)($config['student_database_candidates'] ?? [])
    )));

    // Gabungkan database yang benar-benar kelihatan dengan semua nama database
    // legacy yang dijangka. Ini memastikan dropdown tidak tinggal dua pilihan sahaja.
    $result = !empty($config['show_all_accessible_databases'])
        ? array_values(array_unique(array_merge($candidates, $accessible)))
        : array_values(array_unique(array_merge(
            $candidates,
            array_values(array_filter(
                $accessible,
                static fn(string $db): bool => ik_is_student_database($db, $config)
            ))
        )));

    usort($result, static function (string $a, string $b): int {
        $aCurrent = strcasecmp($a, 'db_pelajarkmp') === 0 ? 0 : (strcasecmp($a, '_pelajarkmp') === 0 ? 1 : 2);
        $bCurrent = strcasecmp($b, 'db_pelajarkmp') === 0 ? 0 : (strcasecmp($b, '_pelajarkmp') === 0 ? 1 : 2);
        if ($aCurrent !== $bCurrent) return $aCurrent <=> $bCurrent;
        $yearCompare = ik_database_year($b) <=> ik_database_year($a);
        return $yearCompare !== 0 ? $yearCompare : strnatcasecmp($a, $b);
    });
    return $result;
}

/** @return string[] */
function ik_activity_database_order(PDO $pdo, array $config, string $selectedDatabase): array
{
    $accessible = ik_list_accessible_databases($pdo, $config);
    $preferred = array_values(array_filter(array_map('strval', (array)($config['activity_database_candidates'] ?? []))));
    return array_values(array_unique(array_merge([$selectedDatabase], $preferred, $accessible)));
}

function ik_current_account(PDO $pdo): string
{
    try {
        return trim((string)$pdo->query('SELECT CURRENT_USER()')->fetchColumn());
    } catch (Throwable) {
        return '';
    }
}

function ik_database_label(string $database): string
{
    if (strcasecmp($database, 'db_pelajarkmp') === 0) return $database . ' — Data semasa';
    if (strcasecmp($database, '_pelajarkmp') === 0) return $database . ' — Data legacy semasa';
    if (preg_match('/(20[0-9]{2})$/', $database, $m)) return $database . ' — Tahun ' . $m[1];
    return $database;
}

/** @return array<string,string> lower-case => actual */
function ik_tables(PDO $pdo, string $database): array
{
    $stmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $stmt->execute([$database]);
    $tables = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $name = (string)$name;
        $tables[strtolower($name)] = $name;
    }
    return $tables;
}

/** @return array<string,string> lower-case => actual */
function ik_columns(PDO $pdo, string $database, string $table): array
{
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $stmt->execute([$database, $table]);
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $name = (string)$name;
        $columns[strtolower($name)] = $name;
    }
    return $columns;
}

function ik_pick_column(array $columns, array $aliases, bool $required = false, string $label = ''): ?string
{
    foreach ($aliases as $alias) {
        $key = strtolower((string)$alias);
        if (isset($columns[$key])) {
            return (string)$columns[$key];
        }
    }
    if ($required) {
        throw new RuntimeException('Column ' . ($label !== '' ? $label : implode('/', $aliases)) . ' tidak ditemui.');
    }
    return null;
}

function ik_clean_query(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function ik_normalize_utf8(mixed $value): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_check_encoding') && mb_check_encoding($text, 'UTF-8')) {
        return $text;
    }
    if (function_exists('iconv')) {
        foreach (['Windows-1252', 'ISO-8859-1'] as $source) {
            $converted = @iconv($source, 'UTF-8//IGNORE', $text);
            if ($converted !== false && $converted !== '') {
                return $converted;
            }
        }
    }
    return $text;
}

/**
 * @return array{table:string,columns:array<string,?string>}
 */
function ik_student_schema(PDO $pdo, string $database): array
{
    $tables = ik_tables($pdo, $database);
    if (!isset($tables['senarai'])) {
        throw new RuntimeException('Database ' . $database . ' tidak mempunyai table senarai.');
    }
    $table = $tables['senarai'];
    $columns = ik_columns($pdo, $database, $table);

    return [
        'table' => $table,
        'columns' => [
            'matrik' => ik_pick_column($columns, ['matrik', 'nomatrik', 'no_matrik'], true, 'matrik'),
            'nokp' => ik_pick_column($columns, ['nokp', 'no_kp', 'noic', 'ic', 'kadpengenalan'], true, 'no. KP'),
            'nama' => ik_pick_column($columns, ['nama', 'name', 'namapelajar'], true, 'nama'),
            'jurusan' => ik_pick_column($columns, ['jurusan', 'kursus', 'course']),
            'kuliah' => ik_pick_column($columns, ['kuliah', 'kelas', 'lecture']),
            'program' => ik_pick_column($columns, ['program', 'sistem', 'program_pengajian']),
        ],
    ];
}

/** @return array<int,array<string,string>> */
function ik_search_students(PDO $pdo, string $database, string $query, int $limit = 50): array
{
    $schema = ik_student_schema($pdo, $database);
    $c = $schema['columns'];
    $q = ik_clean_query($query);
    if ($q === '') {
        return [];
    }

    $db = ik_quote_identifier($database);
    $table = ik_quote_identifier($schema['table']);
    $select = [
        ik_quote_identifier((string)$c['matrik']) . ' AS matrik',
        ik_quote_identifier((string)$c['nokp']) . ' AS nokp',
        ik_quote_identifier((string)$c['nama']) . ' AS nama',
        $c['jurusan'] ? ik_quote_identifier((string)$c['jurusan']) . ' AS jurusan' : "'' AS jurusan",
        $c['kuliah'] ? ik_quote_identifier((string)$c['kuliah']) . ' AS kuliah' : "'' AS kuliah",
        $c['program'] ? ik_quote_identifier((string)$c['program']) . ' AS program' : "'' AS program",
    ];

    $matrikCol = ik_quote_identifier((string)$c['matrik']);
    $nokpCol = ik_quote_identifier((string)$c['nokp']);
    $digits = preg_replace('/\D+/', '', $q) ?? '';
    $sql = 'SELECT ' . implode(', ', $select) . " FROM {$db}.{$table} WHERE "
        . "UPPER(TRIM({$matrikCol})) = UPPER(:exact) OR REPLACE(REPLACE(TRIM({$nokpCol}),'-',''),' ','') = :digits "
        . "OR UPPER(TRIM({$matrikCol})) LIKE UPPER(:prefix) OR REPLACE(REPLACE(TRIM({$nokpCol}),'-',''),' ','') LIKE :digitprefix "
        . "ORDER BY CASE WHEN UPPER(TRIM({$matrikCol})) = UPPER(:exact2) THEN 0 WHEN REPLACE(REPLACE(TRIM({$nokpCol}),'-',''),' ','') = :digits2 THEN 1 ELSE 2 END, {$matrikCol} LIMIT " . max(1, min(100, $limit));

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':exact' => $q,
        ':digits' => $digits,
        ':prefix' => $q . '%',
        ':digitprefix' => $digits . '%',
        ':exact2' => $q,
        ':digits2' => $digits,
    ]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'matrik' => strtoupper(ik_normalize_utf8($row['matrik'] ?? '')),
            'nokp' => ik_normalize_utf8($row['nokp'] ?? ''),
            'nama' => ik_normalize_utf8($row['nama'] ?? ''),
            'jurusan' => ik_normalize_utf8($row['jurusan'] ?? ''),
            'kuliah' => ik_normalize_utf8($row['kuliah'] ?? ''),
            'program' => ik_normalize_utf8($row['program'] ?? ''),
        ];
    }
    return $rows;
}

function ik_get_student(PDO $pdo, string $database, string $matrik): array
{
    $schema = ik_student_schema($pdo, $database);
    $c = $schema['columns'];
    $db = ik_quote_identifier($database);
    $table = ik_quote_identifier($schema['table']);
    $matrikCol = ik_quote_identifier((string)$c['matrik']);
    $select = [
        $matrikCol . ' AS matrik',
        ik_quote_identifier((string)$c['nokp']) . ' AS nokp',
        ik_quote_identifier((string)$c['nama']) . ' AS nama',
        $c['jurusan'] ? ik_quote_identifier((string)$c['jurusan']) . ' AS jurusan' : "'' AS jurusan",
        $c['kuliah'] ? ik_quote_identifier((string)$c['kuliah']) . ' AS kuliah' : "'' AS kuliah",
        $c['program'] ? ik_quote_identifier((string)$c['program']) . ' AS program' : "'' AS program",
    ];
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . " FROM {$db}.{$table} WHERE UPPER(TRIM({$matrikCol})) = UPPER(?) LIMIT 1");
    $stmt->execute([$matrik]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Pelajar tidak ditemui dalam database ' . $database . '.');
    }
    return [
        'matrik' => strtoupper(ik_normalize_utf8($row['matrik'] ?? '')),
        'nokp' => ik_normalize_utf8($row['nokp'] ?? ''),
        'nama' => ik_normalize_utf8($row['nama'] ?? ''),
        'jurusan' => ik_normalize_utf8($row['jurusan'] ?? ''),
        'kuliah' => ik_normalize_utf8($row['kuliah'] ?? ''),
        'program' => ik_normalize_utf8($row['program'] ?? ''),
    ];
}

function ik_activity_schema(PDO $pdo, string $database): ?array
{
    $tables = ik_tables($pdo, $database);
    if (!isset($tables['aktiviti'], $tables['sennamaakt'])) {
        return null;
    }
    $activityTable = $tables['aktiviti'];
    $nameTable = $tables['sennamaakt'];
    $a = ik_columns($pdo, $database, $activityTable);
    $s = ik_columns($pdo, $database, $nameTable);

    try {
        return [
            'database' => $database,
            'activity_table' => $activityTable,
            'name_table' => $nameTable,
            'a' => [
                'id' => ik_pick_column($a, ['codeid', 'id', 'kodaktiviti'], true, 'aktiviti.codeID'),
                'name' => ik_pick_column($a, ['namaakt', 'nama_aktiviti', 'nama'], true, 'aktiviti.namaAkt'),
                'type' => ik_pick_column($a, ['jenis', 'kategori'], true, 'aktiviti.jenis'),
                'level' => ik_pick_column($a, ['peringkat', 'level'], true, 'aktiviti.peringkat'),
                'date' => ik_pick_column($a, ['tarm', 'tarikh', 'tarikh_mula']),
                'section' => ik_pick_column($a, ['section', 'seksyen'], true, 'aktiviti.section'),
                'level_code' => ik_pick_column($a, ['kod_peringkat', 'kodperingkat']),
            ],
            's' => [
                'activity_id' => ik_pick_column($s, ['codeidakt', 'aktiviti_id', 'codeid'], true, 'sennamaakt.codeIDAkt'),
                'matrik' => ik_pick_column($s, ['nomatrik', 'matrik', 'no_matrik'], true, 'sennamaakt.noMatrik'),
                'position' => ik_pick_column($s, ['jawatan', 'position']),
                'achievement' => ik_pick_column($s, ['pencapaian', 'achievement']),
            ],
        ];
    } catch (Throwable) {
        return null;
    }
}

function ik_activity_database(PDO $pdo, array $databases, string $selectedDatabase, string $matrik): ?array
{
    $ordered = array_values(array_unique(array_merge([$selectedDatabase], $databases)));
    $selectedFallback = null;
    foreach ($ordered as $database) {
        $schema = ik_activity_schema($pdo, $database);
        if (!$schema) {
            continue;
        }
        if ($database === $selectedDatabase) {
            $selectedFallback = $schema;
        }
        $db = ik_quote_identifier($database);
        $table = ik_quote_identifier((string)$schema['name_table']);
        $matrikCol = ik_quote_identifier((string)$schema['s']['matrik']);
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM {$db}.{$table} WHERE UPPER(TRIM({$matrikCol})) = UPPER(?) LIMIT 1");
            $stmt->execute([$matrik]);
            if ($stmt->fetchColumn() !== false) {
                return $schema;
            }
        } catch (Throwable) {
            continue;
        }
    }
    return $selectedFallback;
}

/** @return array{section1:array,section2:array,section3:array,activity_database:string} */
function ik_get_activities(PDO $pdo, array $databases, string $selectedDatabase, string $matrik): array
{
    $schema = ik_activity_database($pdo, $databases, $selectedDatabase, $matrik);
    if (!$schema) {
        return ['section1' => [], 'section2' => [], 'section3' => [], 'activity_database' => ''];
    }

    $db = ik_quote_identifier((string)$schema['database']);
    $aTable = ik_quote_identifier((string)$schema['activity_table']);
    $sTable = ik_quote_identifier((string)$schema['name_table']);
    $a = $schema['a'];
    $s = $schema['s'];

    $field = static fn(string $alias, ?string $column, string $fallback = "''"): string => $column ? ($alias . '.' . ik_quote_identifier($column)) : $fallback;
    $order = [];
    if ($a['level_code']) $order[] = $field('a', $a['level_code']);
    if ($s['position']) $order[] = $field('s', $s['position']) . ' DESC';
    if ($s['achievement']) $order[] = $field('s', $s['achievement']);

    $sql = 'SELECT '
        . $field('a', $a['name']) . ' AS namaAkt, '
        . $field('a', $a['type']) . ' AS jenis, '
        . $field('a', $a['level']) . ' AS peringkat, '
        . $field('a', $a['date']) . ' AS tarM, '
        . $field('a', $a['section'], '0') . ' AS section, '
        . $field('s', $s['position']) . ' AS jawatan, '
        . $field('s', $s['achievement']) . ' AS pencapaian '
        . "FROM {$db}.{$aTable} a INNER JOIN {$db}.{$sTable} s ON "
        . $field('a', $a['id']) . ' = ' . $field('s', $s['activity_id'])
        . ' WHERE UPPER(TRIM(' . $field('s', $s['matrik']) . ')) = UPPER(?)'
        . ($order ? (' ORDER BY ' . implode(', ', $order)) : '');

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$matrik]);

    $section1 = [];
    $section2 = [];
    $section3 = [];
    foreach ($stmt->fetchAll() as $row) {
        $section = (int)($row['section'] ?? 0);
        $jawatan = ik_normalize_utf8($row['jawatan'] ?? '');
        $pencapaian = ik_normalize_utf8($row['pencapaian'] ?? '');
        if ($jawatan === '1') $jawatan = '-';
        if ($pencapaian === '1') $pencapaian = '-';
        $date = ik_normalize_utf8($row['tarM'] ?? '');
        $year = preg_match('/^(\d{4})/', $date, $m) ? $m[1] : '';

        if ($section === 1 && count($section1) < 12) {
            $section1[] = [
                ik_normalize_utf8($row['namaAkt'] ?? ''),
                ik_normalize_utf8($row['jenis'] ?? ''),
                ik_normalize_utf8($row['peringkat'] ?? ''),
                $jawatan !== '' ? $jawatan : '-',
                $pencapaian !== '' ? $pencapaian : '-',
            ];
        } elseif ($section === 2 && count($section2) < 8) {
            $section2[] = [
                trim(ik_normalize_utf8($row['jenis'] ?? '') . ' ' . ik_normalize_utf8($row['namaAkt'] ?? '')),
                ik_normalize_utf8($row['peringkat'] ?? ''),
                $year,
            ];
        } elseif ($section === 3 && count($section3) < 2) {
            $section3[] = [
                ik_normalize_utf8($row['namaAkt'] ?? ''),
                ik_normalize_utf8($row['peringkat'] ?? ''),
                $year,
            ];
        }
    }

    return [
        'section1' => $section1,
        'section2' => $section2,
        'section3' => $section3,
        'activity_database' => (string)$schema['database'],
    ];
}

function ik_infer_session(string $database, string $default = ''): string
{
    if (preg_match('/(20\d{2})[^0-9]?(20\d{2})/', $database, $m)) {
        return $m[1] . '/' . $m[2];
    }
    if (preg_match('/(20\d{2})/', $database, $m)) {
        $year = (int)$m[1];
        return $year . '/' . ($year + 1);
    }
    return $default !== '' ? $default : date('Y') . '/' . ((int)date('Y') + 1);
}

/** @return string[] */
function ik_session_options(string $selectedDatabase = '', string $selectedSession = ''): array
{
    $currentYear = (int)date('Y');
    $startYear = 2013;
    if (preg_match('/(20\d{2})/', $selectedDatabase, $m)) {
        $dbYear = (int)$m[1];
        $startYear = min($startYear, $dbYear - 1);
    }

    $sessions = [];
    for ($year = $currentYear + 1; $year >= $startYear; $year--) {
        $sessions[] = $year . '/' . ($year + 1);
    }
    if ($selectedSession !== '' && !in_array($selectedSession, $sessions, true)) {
        array_unshift($sessions, $selectedSession);
    }
    return array_values(array_unique($sessions));
}

function ik_program_label(string $program): string
{
    $program = strtoupper(trim($program));
    if ($program === 'PDT' || str_contains($program, 'EMPAT')) {
        return 'SISTEM EMPAT SEMESTER (SES)';
    }
    if ($program === 'PST' || $program === '' || str_contains($program, 'DUA')) {
        return 'SISTEM DUA SEMESTER (SDS)';
    }
    return $program;
}

function ik_serial_number(array $student): string
{
    $matrik = preg_replace('/\s+/', '', (string)($student['matrik'] ?? '')) ?? '';
    $ic = preg_replace('/\D+/', '', (string)($student['nokp'] ?? '')) ?? '';
    $kuliah = trim((string)($student['kuliah'] ?? ''));
    return $kuliah
        . (strlen($matrik) >= 4 ? substr($matrik, 2, 2) : '')
        . (strlen($matrik) >= 12 ? substr($matrik, 8, 4) : substr($matrik, -4))
        . (strlen($ic) >= 12 ? substr($ic, 8, 4) : substr($ic, -4));
}


function ik_student_photo_cache_dir(): string
{
    return dirname(__DIR__) . '/data/kokurikulum_student_photos';
}

/**
 * Cari gambar pelajar pada direktori eSASI menggunakan beberapa variasi nama fail.
 * Gambar disimpan dalam cache tempatan supaya preview dan PDF lebih stabil.
 */
function ik_student_photo_file(array $config, array $student): ?string
{
    $matrik = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($student['matrik'] ?? '')) ?? '';
    $nokp = preg_replace('/\D+/', '', (string)($student['nokp'] ?? '')) ?? '';
    if ($matrik === '' && $nokp === '') return null;

    $cacheDir = ik_student_photo_cache_dir();
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) return null;

    $cacheKey = strtolower($matrik !== '' ? $matrik : $nokp);
    foreach (['jpg', 'jpeg', 'png'] as $ext) {
        $cached = $cacheDir . '/' . $cacheKey . '.' . $ext;
        if (is_file($cached) && filesize($cached) > 100) return $cached;
    }

    $names = [];
    foreach (array_filter([$matrik, strtoupper($matrik), strtolower($matrik), $nokp]) as $name) {
        foreach (['jpg', 'JPG', 'jpeg', 'JPEG', 'png', 'PNG'] as $ext) {
            $names[] = $name . '.' . $ext;
        }
    }
    $names = array_values(array_unique($names));

    foreach ((array)($config['student_photo_base_urls'] ?? []) as $base) {
        foreach ($names as $name) {
            $url = rtrim((string)$base, '/') . '/' . rawurlencode($name);
            $data = false;
            $contentType = '';
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_USERAGENT => 'Zurie-Kokurikulum/2.0',
                ]);
                $data = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                curl_close($ch);
                if ($code < 200 || $code >= 400) $data = false;
            } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
                $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'Zurie-Kokurikulum/2.0']]);
                $data = @file_get_contents($url, false, $ctx);
            }
            if (!is_string($data) || strlen($data) < 100) continue;

            $tmp = $cacheDir . '/tmp-' . bin2hex(random_bytes(5));
            if (@file_put_contents($tmp, $data, LOCK_EX) === false) continue;
            $info = @getimagesize($tmp);
            if (!$info || !in_array((int)$info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                @unlink($tmp);
                continue;
            }
            $ext = (int)$info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
            $target = $cacheDir . '/' . $cacheKey . '.' . $ext;
            @rename($tmp, $target);
            @chmod($target, 0644);
            return is_file($target) ? $target : null;
        }
    }
    return null;
}

function ik_asset_cache_dir(): string
{
    return dirname(__DIR__) . '/data/kokurikulum_assets';
}

function ik_fetch_remote_image(string $url): string|false
{
    $attempts = [[$url, null]];
    $parts = @parse_url($url);
    if (is_array($parts) && !empty($parts['host']) && strcasecmp((string)$parts['host'], 'i-sims.kmp.matrik.edu.my') === 0) {
        $ipUrl = 'http://10.14.48.80' . ((string)($parts['path'] ?? '/'));
        if (!empty($parts['query'])) $ipUrl .= '?' . $parts['query'];
        $attempts[] = [$ipUrl, 'i-sims.kmp.matrik.edu.my'];
    }

    foreach ($attempts as [$fetchUrl, $hostHeader]) {
        $data = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($fetchUrl);
            $headers = [];
            if ($hostHeader) $headers[] = 'Host: ' . $hostHeader;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'Zurie-Kokurikulum/5.0',
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $data = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code < 200 || $code >= 400) $data = false;
        } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $headers = "User-Agent: Zurie-Kokurikulum/5.0\r\n";
            if ($hostHeader) $headers .= 'Host: ' . $hostHeader . "\r\n";
            $context = stream_context_create(['http' => [
                'timeout' => 20,
                'follow_location' => 1,
                'header' => $headers,
            ]]);
            $data = @file_get_contents($fetchUrl, false, $context);
        }
        if (is_string($data) && strlen($data) > 100) return $data;
    }
    return false;
}

function ik_asset_url(array $config, string $key): string
{
    $value = trim((string)($config['assets'][$key] ?? ''));
    if ($value === '') return '';
    if (preg_match('#^https?://#i', $value)) return $value;
    $base = rtrim((string)($config['asset_base_urls'][0] ?? ''), '/');
    $path = ltrim($value, '/');
    if (preg_match('#/image$#i', $base) && str_starts_with(strtolower($path), 'image/')) $path = substr($path, 6);
    return $base !== '' ? $base . '/' . $path : '';
}

function ik_asset_file(array $config, string $key): ?string
{
    $url = ik_asset_url($config, $key);
    if ($url === '') return null;

    $cacheDir = ik_asset_cache_dir();
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) return null;

    // Terima apa-apa extension URL, tetapi simpan berdasarkan format imej sebenar.
    foreach (['png', 'jpg', 'jpeg'] as $ext) {
        $existing = $cacheDir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.' . $ext;
        if (is_file($existing) && filesize($existing) > 100) return $existing;
    }

    $data = ik_fetch_remote_image($url);
    if (!is_string($data)) return null;

    $tmp = $cacheDir . '/tmp-' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $data, LOCK_EX) === false) return null;
    $info = @getimagesize($tmp);
    if (!$info || !in_array((int)$info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        @unlink($tmp);
        return null;
    }
    $ext = (int)$info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
    $cache = $cacheDir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.' . $ext;
    @unlink($cache);
    if (!@rename($tmp, $cache)) {
        @unlink($tmp);
        return null;
    }
    @chmod($cache, 0644);
    return $cache;
}

function ik_asset_preview_uri(array $config, string $key, ?string $localFile): string
{
    $local = ik_asset_data_uri($localFile);
    if ($local !== '') return $local;
    // Preview browser boleh terus mengambil imej intranet walaupun PHP server
    // gagal resolve DNS atau allow_url_fopen/cURL tidak tersedia.
    return ik_asset_url($config, $key);
}

function ik_asset_data_uri(?string $file): string
{
    if (!$file || !is_file($file)) return '';
    $mime = (string)(@mime_content_type($file) ?: 'image/jpeg');
    $data = @file_get_contents($file);
    return is_string($data) ? ('data:' . $mime . ';base64,' . base64_encode($data)) : '';
}

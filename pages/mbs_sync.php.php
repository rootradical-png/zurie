<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/portal_auth.php';
zurie_portal_require_extract_access();
require_once dirname(__DIR__) . '/lib/pg_runtime_auth.php';
zurie_pg_runtime_gate('mbs_sync', 'MBS — Sync Jadual PostgreSQL ke MRBS');

const MBS_SYNC_CREATE_BY = 'zurie_mbs_sync';
const MBS_SYNC_MAX_ROWS = 25000;

function mbs_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mbs_data_path(string $file): string
{
    return dirname(__DIR__) . '/data/' . $file;
}

function mbs_current_csv_path(): string
{
    return mbs_data_path('mbs_timetable_current.csv');
}

function mbs_alias_path(): string
{
    return mbs_data_path('mbs_room_aliases.json');
}

function mbs_mysql_config(): array
{
    // MBS dan i-SIMS berada pada server berasingan.
    // Modul ini hanya membaca konfigurasi khas MBS dan tidak akan fallback ke config i-SIMS.
    $paths = [
        'C:/xampp_baru/secure/mbs_mysql_config.php',
        dirname(__DIR__) . '/config/mbs_mysql_config.php',
    ];

    $loaded = [];
    $usedPath = $paths[1];

    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        $value = require $path;
        if (is_array($value)) {
            $loaded = $value;
            $usedPath = $path;
            break;
        }
    }

    return [
        'config_path' => $usedPath,
        'config_source' => 'MBS berasingan',
        'enabled' => (bool)($loaded['enabled'] ?? true),
        'host' => trim((string)($loaded['host'] ?? '')),
        'port' => max(1, (int)($loaded['port'] ?? 3306)),
        'dbname' => trim((string)($loaded['dbname'] ?? 'kewpa9')) ?: 'kewpa9',
        'user' => trim((string)($loaded['user'] ?? '')),
        'password' => (string)($loaded['password'] ?? ''),
        'charset' => trim((string)($loaded['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
        'entry_table' => trim((string)($loaded['entry_table'] ?? 'mrbs_entry')) ?: 'mrbs_entry',
        'room_table' => trim((string)($loaded['room_table'] ?? 'mrbs_room')) ?: 'mrbs_room',
        'timezone' => trim((string)($loaded['timezone'] ?? 'Asia/Kuala_Lumpur')) ?: 'Asia/Kuala_Lumpur',
    ];
}

function mbs_pg_query_config(): array
{
    $paths = [
        'C:/xampp_baru/secure/mbs_pg_query.php',
        dirname(__DIR__) . '/config/mbs_pg_query.php',
    ];

    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        $value = require $path;
        if (is_string($value)) {
            return [
                'ready' => trim($value) !== '',
                'path' => $path,
                'sql' => $value,
                'params' => [],
                'default_semester' => 49,
                'semester_dynamic' => preg_match('/\:semester\b/', $value) === 1,
            ];
        }
        if (is_array($value)) {
            $sql = (string)($value['sql'] ?? '');
            $params = is_array($value['params'] ?? null) ? $value['params'] : [];
            $defaultSemester = (int)($value['default_semester'] ?? $params['semester'] ?? $params[':semester'] ?? 49);
            if ($defaultSemester < 1 || $defaultSemester > 9999) {
                $defaultSemester = 49;
            }
            return [
                'ready' => trim($sql) !== '',
                'path' => $path,
                'sql' => $sql,
                'params' => $params,
                'default_semester' => $defaultSemester,
                'semester_dynamic' => preg_match('/\:semester\b/', $sql) === 1,
            ];
        }
    }

    return [
        'ready' => false,
        'path' => $paths[0],
        'sql' => '',
        'params' => [],
        'default_semester' => 49,
        'semester_dynamic' => false,
    ];
}


function mbs_semester_label(int $semester): string
{
    $known = [
        49 => 'Sesi 2026/2027',
    ];

    return $known[$semester] ?? 'ID semester ' . $semester;
}

function mbs_identifier(string $value, string $label): string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
        throw new RuntimeException($label . ' tidak sah.');
    }
    return '`' . $value . '`';
}

function mbs_mysql_connect(array $config): PDO
{
    if (!$config['enabled']) {
        throw new RuntimeException('Sambungan MRBS dinyahaktifkan dalam konfigurasi.');
    }
    if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO MySQL belum aktif dalam PHP.');
    }
    if ($config['host'] === '' || $config['dbname'] === '' || $config['user'] === '') {
        throw new RuntimeException('Konfigurasi MySQL MRBS belum lengkap.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['dbname'],
        $config['charset']
    );

    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
    return $pdo;
}

function mbs_validate_schema(PDO $mysql, array $config): void
{
    $entry = mbs_identifier($config['entry_table'], 'Table entry');
    $room = mbs_identifier($config['room_table'], 'Table room');

    $requiredEntry = [
        'id', 'start_time', 'end_time', 'entry_type', 'repeat_id', 'room_id',
        'create_by', 'modified_by', 'name', 'type', 'description', 'status',
        'ical_uid', 'ical_sequence', 'allow_registration', 'registrant_limit',
        'registrant_limit_enabled', 'registration_opens',
        'registration_opens_enabled', 'registration_closes',
        'registration_closes_enabled',
    ];
    $requiredRoom = ['id', 'room_name'];

    $entryColumns = $mysql->query('SHOW COLUMNS FROM ' . $entry)->fetchAll(PDO::FETCH_COLUMN);
    $roomColumns = $mysql->query('SHOW COLUMNS FROM ' . $room)->fetchAll(PDO::FETCH_COLUMN);

    $missingEntry = array_values(array_diff($requiredEntry, $entryColumns));
    $missingRoom = array_values(array_diff($requiredRoom, $roomColumns));
    if ($missingEntry || $missingRoom) {
        throw new RuntimeException(
            'Struktur MRBS tidak lengkap. mrbs_entry: ' . implode(', ', $missingEntry)
            . ' | mrbs_room: ' . implode(', ', $missingRoom)
        );
    }
}

function mbs_default_aliases(): array
{
    return [
        '221' => '33',
        'BS1' => '54',
        'BS2' => '55',
        'AST1' => '56',
    ];
}

function mbs_load_aliases(): array
{
    $aliases = mbs_default_aliases();
    $path = mbs_alias_path();
    if (!is_file($path)) {
        return $aliases;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $aliases;
    }
    foreach ($decoded as $source => $target) {
        $source = strtoupper(trim((string)$source));
        $target = trim((string)$target);
        if ($source !== '' && $target !== '') {
            $aliases[$source] = $target;
        }
    }
    ksort($aliases);
    return $aliases;
}

function mbs_aliases_to_text(array $aliases): string
{
    $lines = [];
    foreach ($aliases as $source => $target) {
        $lines[] = $source . '=' . $target;
    }
    return implode("\n", $lines);
}

function mbs_parse_alias_text(string $text): array
{
    $aliases = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $lineNo => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            throw new RuntimeException('Format alias baris ' . ($lineNo + 1) . ' tidak sah. Gunakan SUMBER=room_id atau SUMBER=nama bilik.');
        }
        [$source, $target] = array_map('trim', explode('=', $line, 2));
        $source = strtoupper($source);
        if ($source === '' || $target === '') {
            throw new RuntimeException('Alias baris ' . ($lineNo + 1) . ' tidak lengkap.');
        }
        $aliases[$source] = $target;
    }
    ksort($aliases);
    return $aliases;
}

function mbs_save_aliases(array $aliases): void
{
    $path = mbs_alias_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Folder data tidak dapat diwujudkan.');
    }
    $json = json_encode($aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Alias bilik tidak dapat disimpan.');
    }
}

function mbs_normalize_room(string $value): string
{
    $value = strtoupper(trim($value));
    return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
}

function mbs_load_rooms(PDO $mysql, array $config): array
{
    $roomTable = mbs_identifier($config['room_table'], 'Table room');
    return $mysql->query('SELECT id, room_name FROM ' . $roomTable . ' ORDER BY room_name')->fetchAll();
}

function mbs_build_room_mapping(array $sourceRooms, array $rooms, array $aliases): array
{
    $byId = [];
    $byName = [];
    $byNormalized = [];
    foreach ($rooms as $room) {
        $id = (int)$room['id'];
        $name = trim((string)$room['room_name']);
        $byId[$id] = ['id' => $id, 'room_name' => $name];
        $byName[strtoupper($name)] = ['id' => $id, 'room_name' => $name];
        $norm = mbs_normalize_room($name);
        if ($norm !== '' && !isset($byNormalized[$norm])) {
            $byNormalized[$norm] = ['id' => $id, 'room_name' => $name];
        }
    }

    $mapping = [];
    foreach ($sourceRooms as $source) {
        $source = trim((string)$source);
        $sourceKey = strtoupper($source);
        $matched = null;
        $method = 'TIADA PADANAN';

        if (isset($aliases[$sourceKey])) {
            $target = trim((string)$aliases[$sourceKey]);
            if (ctype_digit($target) && isset($byId[(int)$target])) {
                $matched = $byId[(int)$target];
                $method = 'ALIAS ID';
            } elseif (isset($byName[strtoupper($target)])) {
                $matched = $byName[strtoupper($target)];
                $method = 'ALIAS NAMA';
            } else {
                $normTarget = mbs_normalize_room($target);
                if ($normTarget !== '' && isset($byNormalized[$normTarget])) {
                    $matched = $byNormalized[$normTarget];
                    $method = 'ALIAS NORMAL';
                }
            }
        }

        if ($matched === null) {
            $norm = mbs_normalize_room($source);
            if ($norm !== '' && isset($byNormalized[$norm])) {
                $matched = $byNormalized[$norm];
                $method = 'AUTO';
            }
        }

        $mapping[$sourceKey] = [
            'source' => $source,
            'room_id' => $matched['id'] ?? null,
            'room_name' => $matched['room_name'] ?? '',
            'method' => $method,
        ];
    }
    ksort($mapping);
    return $mapping;
}

function mbs_csv_headers(): array
{
    return ['name', 'jw_hari', 'jw_masa', 'room_name', 'description'];
}

function mbs_read_csv(string $path, ?int $limit = null): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Fail extract belum ada. Upload CSV atau jalankan Extract PostgreSQL.');
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Fail extract tidak dapat dibuka.');
    }

    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);
        throw new RuntimeException('Header CSV tidak dijumpai.');
    }
    $header = array_map(static function ($value): string {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', (string)$value) ?? (string)$value;
        return strtolower(trim($value));
    }, $header);

    $expected = mbs_csv_headers();
    $missing = array_values(array_diff($expected, $header));
    if ($missing) {
        fclose($handle);
        throw new RuntimeException('Kolum CSV tidak lengkap: ' . implode(', ', $missing));
    }
    $positions = array_flip($header);

    $rows = [];
    $line = 1;
    while (($data = fgetcsv($handle)) !== false) {
        $line++;
        if ($data === [null] || $data === []) {
            continue;
        }
        $row = [];
        foreach ($expected as $column) {
            $row[$column] = trim((string)($data[$positions[$column]] ?? ''));
        }
        if ($row['name'] === '' && $row['room_name'] === '') {
            continue;
        }
        if ($row['name'] === '' || $row['jw_hari'] === '' || $row['jw_masa'] === '' || $row['room_name'] === '') {
            fclose($handle);
            throw new RuntimeException('Data tidak lengkap pada baris CSV ' . $line . '.');
        }
        $rows[] = $row;
        if (count($rows) > MBS_SYNC_MAX_ROWS) {
            fclose($handle);
            throw new RuntimeException('CSV melebihi had ' . MBS_SYNC_MAX_ROWS . ' rekod.');
        }
        if ($limit !== null && count($rows) >= $limit) {
            break;
        }
    }
    fclose($handle);
    return $rows;
}

function mbs_count_csv(string $path): int
{
    try {
        return count(mbs_read_csv($path));
    } catch (Throwable) {
        return 0;
    }
}

function mbs_save_uploaded_csv(array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Pilih fail CSV extract terlebih dahulu.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('Saiz CSV mesti antara 1 bait hingga 10 MB.');
    }
    $name = (string)($file['name'] ?? '');
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
        throw new RuntimeException('Hanya fail .csv dibenarkan.');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Upload CSV tidak sah.');
    }

    // Validate before replacing current extract.
    mbs_read_csv($tmp, 5);
    $target = mbs_current_csv_path();
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('CSV tidak dapat disimpan ke folder data.');
    }
    @chmod($target, 0600);
}

function mbs_pg_connect(): PDO
{
    $config = zurie_pg_runtime_config('mbs_sync');
    if ($config['host'] === '' || $config['dbname'] === '' || $config['user'] === '') {
        throw new RuntimeException('Konfigurasi PostgreSQL belum lengkap.');
    }
    return zurie_pg_runtime_connect_with($config, $config['user'], $config['password']);
}


function mbs_preview_from_pg(array $queryConfig, int $semester, int $limit = 50): array
{
    if (!$queryConfig['ready']) {
        throw new RuntimeException('Query extract MBS belum disediakan. Pastikan C:\\xampp_baru\\secure\\mbs_pg_query.php wujud.');
    }

    if ($semester < 1 || $semester > 9999) {
        throw new RuntimeException('Semester ID tidak sah.');
    }

    $sql = (string)$queryConfig['sql'];
    if (preg_match('/\:semester\b/', $sql) !== 1) {
        throw new RuntimeException('Query extract belum dinamik. Tukar nilai semester tetap kepada :semester.');
    }

    $params = (array)$queryConfig['params'];
    unset($params['semester'], $params[':semester']);
    $params['semester'] = $semester;

    $pgsql = mbs_pg_connect();
    $stmt = $pgsql->prepare($sql);
    foreach ($params as $key => $value) {
        $param = str_starts_with((string)$key, ':') ? (string)$key : ':' . $key;
        if ($param === ':semester') {
            $stmt->bindValue($param, (int)$value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($param, $value);
        }
    }
    $stmt->execute();

    $rows = [];
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false && count($rows) < $limit) {
        $ordered = [];
        foreach (mbs_csv_headers() as $column) {
            if (!array_key_exists($column, $row)) {
                throw new RuntimeException(
                    'Query PostgreSQL mesti pulangkan kolum: ' . implode(', ', mbs_csv_headers())
                );
            }
            $ordered[$column] = trim((string)$row[$column]);
        }
        $rows[] = $ordered;
    }

    return $rows;
}

function mbs_extract_from_pg(array $queryConfig, int $semester): int
{
    if (!$queryConfig['ready']) {
        throw new RuntimeException('Query extract MBS belum disediakan. Salin config/mbs_pg_query.php.example ke C:\\xampp_baru\\secure\\mbs_pg_query.php dan masukkan query extract yang sedia ada.');
    }

    if ($semester < 1 || $semester > 9999) {
        throw new RuntimeException('Semester ID tidak sah.');
    }

    $sql = (string)$queryConfig['sql'];
    if (preg_match('/\:semester\b/', $sql) !== 1) {
        throw new RuntimeException('Query extract belum dinamik. Dalam C:\\xampp_baru\\secure\\mbs_pg_query.php, tukar nilai semester tetap seperti 49 kepada :semester.');
    }

    $params = (array)$queryConfig['params'];
    unset($params['semester'], $params[':semester']);
    $params['semester'] = $semester;

    $pgsql = mbs_pg_connect();
    $stmt = $pgsql->prepare($sql);
    foreach ($params as $key => $value) {
        $param = str_starts_with((string)$key, ':') ? (string)$key : ':' . $key;
        if ($param === ':semester') {
            $stmt->bindValue($param, (int)$value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($param, $value);
        }
    }
    $stmt->execute();

    $path = mbs_current_csv_path();
    $out = fopen($path, 'wb');
    if ($out === false) {
        throw new RuntimeException('Fail extract semasa tidak dapat ditulis.');
    }
    fputcsv($out, mbs_csv_headers());

    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ordered = [];
        foreach (mbs_csv_headers() as $column) {
            if (!array_key_exists($column, $row)) {
                fclose($out);
                @unlink($path);
                throw new RuntimeException('Query PostgreSQL mesti pulangkan kolum: ' . implode(', ', mbs_csv_headers()));
            }
            $ordered[] = (string)$row[$column];
        }
        fputcsv($out, zurie_security_csv_row($ordered));
        $count++;
        if ($count > MBS_SYNC_MAX_ROWS) {
            fclose($out);
            @unlink($path);
            throw new RuntimeException('Hasil extract melebihi had ' . MBS_SYNC_MAX_ROWS . ' rekod.');
        }
    }
    fclose($out);
    @chmod($path, 0600);
    return $count;
}

function mbs_input(array $source, array $queryConfig = []): array
{
    $defaultSemester = (int)($queryConfig['default_semester'] ?? 49);
    if ($defaultSemester < 1 || $defaultSemester > 9999) {
        $defaultSemester = 49;
    }
    $semester = (int)($source['semester_id'] ?? $defaultSemester);
    if ($semester < 1 || $semester > 9999) {
        $semester = $defaultSemester;
    }

    $start = trim((string)($source['date_start'] ?? '2026-06-15'));
    $end = trim((string)($source['date_end'] ?? '2026-10-28'));
    $mode = (string)($source['date_mode'] ?? 'existing_dates');
    if (!in_array($mode, ['existing_dates', 'weekdays'], true)) {
        $mode = 'existing_dates';
    }
    $type = strtoupper(substr(trim((string)($source['type_code'] ?? 'P')), 0, 1));
    if (preg_match('/^[A-Z0-9]$/', $type) !== 1) {
        $type = 'P';
    }

    return [
        'semester_id' => $semester,
        'date_start' => $start,
        'date_end' => $end,
        'date_mode' => $mode,
        'type_code' => $type,
        'update_existing_type' => isset($source['update_existing_type']) && (string)$source['update_existing_type'] === '1',
        'alias_text' => (string)($source['alias_text'] ?? mbs_aliases_to_text(mbs_load_aliases())),
    ];
}

function mbs_validate_dates(string $start, string $end): void
{
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    if (!$a || !$b || $a->format('Y-m-d') !== $start || $b->format('Y-m-d') !== $end) {
        throw new RuntimeException('Tarikh mula atau tarikh tamat tidak sah.');
    }
    if ($a > $b) {
        throw new RuntimeException('Tarikh mula mesti sebelum tarikh tamat.');
    }
    if ($a->diff($b)->days > 370) {
        throw new RuntimeException('Julat tarikh maksimum ialah 370 hari.');
    }
}

function mbs_day_name(int $isoDay): string
{
    return [1 => 'ISNIN', 2 => 'SELASA', 3 => 'RABU', 4 => 'KHAMIS', 5 => 'JUMAAT', 6 => 'SABTU', 7 => 'AHAD'][$isoDay] ?? '';
}

function mbs_active_dates(PDO $mysql, array $config, array $input): array
{
    mbs_validate_dates($input['date_start'], $input['date_end']);
    $dates = [];

    if ($input['date_mode'] === 'existing_dates') {
        $entryTable = mbs_identifier($config['entry_table'], 'Table entry');
        $stmt = $mysql->prepare(
            'SELECT DISTINCT DATE(FROM_UNIXTIME(start_time)) AS active_date '
            . 'FROM ' . $entryTable . ' '
            . 'WHERE start_time >= UNIX_TIMESTAMP(CONCAT(:start_date, " 00:00:00")) '
            . 'AND start_time < UNIX_TIMESTAMP(DATE_ADD(:end_date, INTERVAL 1 DAY)) '
            . 'ORDER BY active_date'
        );
        $stmt->execute([':start_date' => $input['date_start'], ':end_date' => $input['date_end']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $dateText) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$dateText);
            if ($date) {
                $dates[] = ['date' => $date->format('Y-m-d'), 'day' => mbs_day_name((int)$date->format('N'))];
            }
        }
        return $dates;
    }

    $start = new DateTimeImmutable($input['date_start']);
    $end = new DateTimeImmutable($input['date_end']);
    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
        $iso = (int)$date->format('N');
        if ($iso <= 5) {
            $dates[] = ['date' => $date->format('Y-m-d'), 'day' => mbs_day_name($iso)];
        }
    }
    return $dates;
}

function mbs_parse_time_range(string $range): array
{
    $range = trim(str_replace([':', ' '], '', $range));
    if (preg_match('/^(\d{3,4})-(\d{3,4})$/', $range, $m) !== 1) {
        throw new RuntimeException('Format masa tidak sah: ' . $range);
    }
    $normalize = static function (string $part): string {
        $part = str_pad($part, 4, '0', STR_PAD_LEFT);
        $hour = (int)substr($part, 0, 2);
        $minute = (int)substr($part, 2, 2);
        if ($hour > 23 || $minute > 59) {
            throw new RuntimeException('Nilai masa tidak sah: ' . $part);
        }
        return sprintf('%02d:%02d:00', $hour, $minute);
    };
    return [$normalize($m[1]), $normalize($m[2])];
}

function mbs_text_substr(string $value, int $start, int $length): string
{
    return function_exists('mb_substr')
        ? mb_substr($value, $start, $length, 'UTF-8')
        : substr($value, $start, $length);
}

function mbs_text_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function mbs_timestamp(string $date, string $time, string $timezone): int
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, new DateTimeZone($timezone));
    if (!$dt) {
        throw new RuntimeException('Tarikh atau masa tidak dapat ditukar: ' . $date . ' ' . $time);
    }
    return $dt->getTimestamp();
}

function mbs_group_dates_by_day(array $dates): array
{
    $grouped = [];
    foreach ($dates as $row) {
        $grouped[$row['day']][] = $row['date'];
    }
    return $grouped;
}

function mbs_source_rooms(array $rows): array
{
    $rooms = [];
    foreach ($rows as $row) {
        $rooms[strtoupper(trim((string)$row['room_name']))] = trim((string)$row['room_name']);
    }
    natcasesort($rooms);
    return array_values($rooms);
}

function mbs_type_options(PDO $mysql, array $config): array
{
    $entryTable = mbs_identifier($config['entry_table'], 'Table entry');
    $rows = $mysql->query(
        'SELECT type, COUNT(*) AS total FROM ' . $entryTable
        . " WHERE type IS NOT NULL AND type <> '' GROUP BY type ORDER BY type"
    )->fetchAll();
    $options = [];
    foreach ($rows as $row) {
        $code = strtoupper(substr((string)$row['type'], 0, 1));
        if ($code !== '') {
            $options[$code] = (int)$row['total'];
        }
    }
    if (!isset($options['P'])) {
        $options['P'] = 0;
    }
    ksort($options);
    return $options;
}

function mbs_preview_summary(array $rows, array $mapping, array $dates): array
{
    $groupedDates = mbs_group_dates_by_day($dates);
    $expected = 0;
    $unmappedRows = 0;
    $byRoom = [];

    foreach ($rows as $row) {
        $sourceKey = strtoupper(trim((string)$row['room_name']));
        $day = strtoupper(trim((string)$row['jw_hari']));
        $occurrences = count($groupedDates[$day] ?? []);
        $mapped = isset($mapping[$sourceKey]) && $mapping[$sourceKey]['room_id'] !== null;
        if (!$mapped) {
            $unmappedRows++;
            continue;
        }
        $expected += $occurrences;
        $target = $mapping[$sourceKey]['room_name'];
        $byRoom[$target] = ($byRoom[$target] ?? 0) + $occurrences;
    }
    arsort($byRoom);

    return [
        'source_rows' => count($rows),
        'active_dates' => count($dates),
        'expected_entries' => $expected,
        'unmapped_source_rows' => $unmappedRows,
        'by_room' => $byRoom,
    ];
}

function mbs_sync(PDO $mysql, array $config, array $input, array $rows, array $mapping, array $dates, bool $typeOnly = false): array
{
    $entryTable = mbs_identifier($config['entry_table'], 'Table entry');
    $datesByDay = mbs_group_dates_by_day($dates);
    $timezone = $config['timezone'];

    $find = $mysql->prepare(
        'SELECT id, type FROM ' . $entryTable
        . ' WHERE room_id = :room_id AND start_time = :start_time AND end_time = :end_time AND name = :name LIMIT 1'
    );
    $insert = $mysql->prepare(
        'INSERT INTO ' . $entryTable . ' ('
        . 'start_time,end_time,entry_type,repeat_id,room_id,create_by,modified_by,name,type,description,status,'
        . 'reminded,info_time,info_user,info_text,ical_uid,ical_sequence,ical_recur_id,allow_registration,'
        . 'registrant_limit,registrant_limit_enabled,registration_opens,registration_opens_enabled,'
        . 'registration_closes,registration_closes_enabled'
        . ') VALUES ('
        . ':start_time,:end_time,0,NULL,:room_id,:create_by,:modified_by,:name,:type,:description,0,'
        . 'NULL,NULL,NULL,NULL,:ical_uid,0,NULL,0,0,1,1209600,0,0,0)'
    );
    $updateType = $mysql->prepare(
        'UPDATE ' . $entryTable . ' SET type = :type, modified_by = :modified_by WHERE id = :id'
    );

    $result = [
        'inserted' => 0,
        'skipped' => 0,
        'type_updated' => 0,
        'unmapped_rows' => 0,
        'unmapped_rooms' => [],
        'by_room' => [],
    ];

    $mysql->beginTransaction();
    try {
        foreach ($rows as $row) {
            $sourceKey = strtoupper(trim((string)$row['room_name']));
            $map = $mapping[$sourceKey] ?? null;
            if (!$map || $map['room_id'] === null) {
                $result['unmapped_rows']++;
                $result['unmapped_rooms'][$sourceKey] = true;
                continue;
            }

            $day = strtoupper(trim((string)$row['jw_hari']));
            $dayDates = $datesByDay[$day] ?? [];
            if (!$dayDates) {
                continue;
            }
            [$startClock, $endClock] = mbs_parse_time_range((string)$row['jw_masa']);
            $fullName = trim((string)$row['name']);
            $shortName = mbs_text_substr($fullName, 0, 80);
            $description = trim((string)$row['description']);
            if (mbs_text_length($fullName) > 80) {
                $description .= ($description !== '' ? "\n" : '') . 'Nama penuh: ' . $fullName;
            }

            foreach ($dayDates as $date) {
                $startTime = mbs_timestamp($date, $startClock, $timezone);
                $endTime = mbs_timestamp($date, $endClock, $timezone);
                $find->execute([
                    ':room_id' => $map['room_id'],
                    ':start_time' => $startTime,
                    ':end_time' => $endTime,
                    ':name' => $shortName,
                ]);
                $existing = $find->fetch();

                if ($existing) {
                    if (($typeOnly || $input['update_existing_type']) && strtoupper((string)$existing['type']) !== $input['type_code']) {
                        $updateType->execute([
                            ':type' => $input['type_code'],
                            ':modified_by' => MBS_SYNC_CREATE_BY,
                            ':id' => (int)$existing['id'],
                        ]);
                        $result['type_updated']++;
                    } else {
                        $result['skipped']++;
                    }
                    continue;
                }

                if ($typeOnly) {
                    $result['skipped']++;
                    continue;
                }

                $uid = 'zurie-mbs-' . sha1($map['room_id'] . '|' . $date . '|' . $row['jw_masa'] . '|' . $fullName) . '@noc';
                $insert->execute([
                    ':start_time' => $startTime,
                    ':end_time' => $endTime,
                    ':room_id' => $map['room_id'],
                    ':create_by' => MBS_SYNC_CREATE_BY,
                    ':modified_by' => MBS_SYNC_CREATE_BY,
                    ':name' => $shortName,
                    ':type' => $input['type_code'],
                    ':description' => $description,
                    ':ical_uid' => $uid,
                ]);
                $result['inserted']++;
                $targetName = (string)$map['room_name'];
                $result['by_room'][$targetName] = ($result['by_room'][$targetName] ?? 0) + 1;
            }
        }
        $mysql->commit();
    } catch (Throwable $e) {
        if ($mysql->inTransaction()) {
            $mysql->rollBack();
        }
        throw $e;
    }

    $result['unmapped_rooms'] = array_keys($result['unmapped_rooms']);
    natcasesort($result['unmapped_rooms']);
    arsort($result['by_room']);
    return $result;
}

$mysqlConfig = mbs_mysql_config();
$pgQueryConfig = mbs_pg_query_config();
$input = mbs_input($_POST, $pgQueryConfig);
$error = '';
$success = '';
$previewRows = [];
$pgPreviewRows = [];
$mappingRows = [];
$summary = null;
$syncResult = null;
$typeOptions = ['P' => 0];
$mysqlReady = false;
$mysqlSchemaReady = false;
$currentCsv = mbs_current_csv_path();

if (isset($_GET['download']) && $_GET['download'] === '1') {
    if (!is_file($currentCsv)) {
        http_response_code(404);
        exit('Fail extract belum ada.');
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="timetableMBS_current.csv"');
    header('Content-Length: ' . filesize($currentCsv));
    readfile($currentCsv);
    exit;
}

$action = (string)($_POST['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action !== '') {
    zurie_security_require_valid_csrf();

    try {
        if ($action === 'upload_csv') {
            mbs_save_uploaded_csv($_FILES['extract_csv'] ?? []);
            $success = 'Fail CSV pilihan berjaya dimuat naik. ' . mbs_count_csv($currentCsv) . ' slot tersedia untuk preview atau sync.';
        } elseif ($action === 'preview_pg') {
            $pgPreviewRows = mbs_preview_from_pg($pgQueryConfig, (int)$input['semester_id'], 50);
            $semesterId = (int)$input['semester_id'];
            $semesterTitle = $semesterId === 49 ? 'Sesi 2026/2027 (ID 49)' : 'ID semester ' . $semesterId;
            $success = 'Preview PostgreSQL untuk ' . $semesterTitle . ' berjaya. '
                . count($pgPreviewRows) . ' rekod pertama dipaparkan. Tiada snapshot atau data MRBS diubah.';
        } elseif ($action === 'extract_pg') {
            $count = mbs_extract_from_pg($pgQueryConfig, (int)$input['semester_id']);
            $semesterId = (int)$input['semester_id'];
            $semesterTitle = $semesterId === 49 ? 'Sesi 2026/2027 (ID 49)' : 'ID semester ' . $semesterId;
            $success = 'Extract penuh PostgreSQL untuk ' . $semesterTitle . ' berjaya. ' . $count
                . ' slot disimpan sebagai snapshot. Seterusnya klik Preview MRBS sebelum sync.';
        } elseif ($action === 'test_pg') {
            $pg = mbs_pg_connect();
            $pg->query('SELECT 1')->fetchColumn();
            $success = 'Sambungan PostgreSQL berjaya.';
        } elseif ($action === 'save_aliases') {
            $aliases = mbs_parse_alias_text($input['alias_text']);
            mbs_save_aliases($aliases);
            $success = 'Alias bilik berjaya disimpan.';
        }

        if (in_array($action, ['test_mysql', 'preview', 'sync', 'update_type'], true)) {
            $mysql = mbs_mysql_connect($mysqlConfig);
            $mysqlReady = true;
            mbs_validate_schema($mysql, $mysqlConfig);
            $mysqlSchemaReady = true;
            $typeOptions = mbs_type_options($mysql, $mysqlConfig);

            if ($action === 'test_mysql') {
                $success = 'Sambungan MySQL MRBS berjaya: ' . $mysqlConfig['dbname'] . '.' . $mysqlConfig['entry_table'];
            } else {
                $rows = mbs_read_csv($currentCsv);
                $aliases = mbs_parse_alias_text($input['alias_text']);
                $rooms = mbs_load_rooms($mysql, $mysqlConfig);
                $mapping = mbs_build_room_mapping(mbs_source_rooms($rows), $rooms, $aliases);
                $dates = mbs_active_dates($mysql, $mysqlConfig, $input);
                if (!$dates) {
                    throw new RuntimeException('Tiada tarikh aktif dijumpai dalam julat dipilih. Tukar Kaedah Tarikh kepada Semua Isnin–Jumaat jika jadual MRBS masih kosong.');
                }

                $previewRows = array_slice($rows, 0, 30);
                $mappingRows = array_values($mapping);
                $summary = mbs_preview_summary($rows, $mapping, $dates);

                if ($action === 'preview') {
                    if ($success === '') {
                        $success = 'Preview siap. Semak padanan bilik dan anggaran rekod sebelum sync.';
                    }
                } elseif ($action === 'sync') {
                    $syncResult = mbs_sync($mysql, $mysqlConfig, $input, $rows, $mapping, $dates, false);
                    $success = 'Sync selesai. Rekod baharu: ' . $syncResult['inserted']
                        . ', type dikemas kini: ' . $syncResult['type_updated']
                        . ', rekod sedia ada: ' . $syncResult['skipped'] . '.';
                } else {
                    $syncResult = mbs_sync($mysql, $mysqlConfig, $input, $rows, $mapping, $dates, true);
                    $success = 'Kemaskini type selesai. ' . $syncResult['type_updated'] . ' rekod ditukar kepada type ' . $input['type_code'] . '.';
                }
            }
        }
    } catch (Throwable $e) {
        zurie_security_log_exception('MBS_SYNC', $e);
        $error = $e->getMessage();
    }
}

try {
    if (!$mysqlReady) {
        $mysql = mbs_mysql_connect($mysqlConfig);
        $mysqlReady = true;
        mbs_validate_schema($mysql, $mysqlConfig);
        $mysqlSchemaReady = true;
        $typeOptions = mbs_type_options($mysql, $mysqlConfig);
    }
} catch (Throwable) {
    // Status card sahaja. Ralat penuh dipaparkan melalui butang Test MRBS.
}

$csvCount = mbs_count_csv($currentCsv);
$csvModified = is_file($currentCsv) ? date('d-m-Y H:i', (int)filemtime($currentCsv)) : '-';
$csrf = zurie_security_csrf_token();
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MBS Sync Jadual</title>
<link rel="icon" href="/zurie/image/zuriex.jpg">
<style>
:root{--bg:#07111f;--card:#0d1c2e;--card2:#091725;--line:rgba(130,170,210,.18);--text:#eaf4ff;--muted:#86a0b8;--cyan:#55d9ff;--green:#51e3a4;--red:#ff7183;--amber:#ffc86b;--blue:#409cff}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top left,#123456 0,#07111f 36%,#040b14 100%);color:var(--text);font-family:Segoe UI,Arial,sans-serif;font-size:13px}.wrap{max-width:1480px;margin:0 auto;padding:18px}.top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px}.top a{color:#9ddfff;text-decoration:none}.title h1{margin:0;font-size:23px}.title p{margin:5px 0 0;color:var(--muted)}.card{background:linear-gradient(145deg,rgba(13,28,46,.97),rgba(8,18,31,.97));border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.25);padding:16px;margin-bottom:14px}.card h2{font-size:15px;margin:0 0 12px}.status-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.status{padding:11px;border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.02)}.status span{display:block;color:var(--muted);font-size:10px;text-transform:uppercase}.status b{display:block;margin-top:4px}.ok{color:var(--green)}.bad{color:var(--red)}.warn{color:var(--amber)}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}.field label{display:block;color:var(--muted);font-size:11px;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em}.field input,.field select,.field textarea{width:100%;border:1px solid rgba(130,170,210,.25);background:#081523;color:var(--text);border-radius:10px;padding:10px 11px;outline:none}.field textarea{min-height:128px;font-family:Consolas,monospace;line-height:1.5;resize:vertical}.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(85,217,255,.09)}.actions{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-top:14px}.btn{border:1px solid rgba(85,217,255,.32);background:rgba(85,217,255,.10);color:#bff0ff;border-radius:10px;padding:10px 13px;text-decoration:none;cursor:pointer;font-weight:700}.btn.primary{background:linear-gradient(135deg,rgba(85,217,255,.22),rgba(81,227,164,.14));border-color:rgba(85,217,255,.55)}.btn.green{background:linear-gradient(135deg,rgba(81,227,164,.22),rgba(85,217,255,.12));border-color:rgba(81,227,164,.5);color:#aaffd9}.btn.amber{background:rgba(255,200,107,.11);border-color:rgba(255,200,107,.35);color:#ffe0a4}.alert{padding:11px 13px;border-radius:11px;margin-bottom:13px}.alert.error{border:1px solid rgba(255,113,131,.3);background:rgba(255,113,131,.08);color:#ffc1c9}.alert.success{border:1px solid rgba(81,227,164,.28);background:rgba(81,227,164,.08);color:#aaffd9}.note{color:var(--muted);font-size:12px;line-height:1.6}.note code{color:#c9efff;background:#06111d;padding:2px 5px;border-radius:5px}.check{display:flex;align-items:center;gap:7px;color:var(--muted);font-size:12px}.check input{accent-color:#51e3a4}.metric-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.metric{padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--card2)}.metric b{display:block;font-size:23px;color:#bdeeff}.metric span{color:var(--muted);font-size:11px}.table-wrap{overflow:auto;max-height:560px;border:1px solid var(--line);border-radius:12px}.table{width:100%;border-collapse:collapse;font-size:11px;white-space:nowrap}.table th,.table td{padding:8px 9px;border-bottom:1px solid rgba(130,170,210,.1);border-right:1px solid rgba(130,170,210,.07);text-align:left}.table th{position:sticky;top:0;background:#10263d;color:#9de7ff;z-index:1}.table td{color:#c4d5e4}.badge{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:10px;font-weight:800}.badge.ok{background:rgba(81,227,164,.12);border:1px solid rgba(81,227,164,.25)}.badge.bad{background:rgba(255,113,131,.1);border:1px solid rgba(255,113,131,.23)}.split{display:grid;grid-template-columns:1.1fr .9fr;gap:14px}.fileline{display:flex;gap:10px;align-items:end}.fileline .field{flex:1}.semester-help{margin-top:8px;padding:10px 11px;border:1px solid rgba(85,217,255,.2);border-radius:10px;background:rgba(85,217,255,.06)}.semester-help b,.semester-help span{display:block}.semester-help b{color:#bff0ff;font-size:13px}.semester-help span{margin-top:4px;color:var(--muted);font-size:11px;line-height:1.5}.quick-flow{display:grid;gap:5px;padding:12px 14px;border:1px solid rgba(81,227,164,.2);border-radius:11px;background:rgba(81,227,164,.05);color:var(--muted)}.quick-flow>b{color:#aaffd9}.quick-flow span{font-size:12px}.advanced-box{margin-top:14px;border-top:1px solid var(--line);padding-top:12px}.advanced-box summary{cursor:pointer;color:#9ddfff;font-weight:700}.advanced-content{margin-top:12px}@media(max-width:980px){.status-grid,.metric-grid{grid-template-columns:repeat(2,1fr)}.grid,.grid.two,.split{grid-template-columns:1fr 1fr}}@media(max-width:650px){.status-grid,.metric-grid,.grid,.grid.two,.split{grid-template-columns:1fr}.top{display:block}.wrap{padding:12px}.fileline{display:block}.fileline .btn{margin-top:9px;width:100%}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="title">
      <h1>MBS — Sync Jadual ke MRBS <span class="badge ok">v11G-PREVIEW50</span></h1>
      <p>Pilih semester, extract terus daripada PostgreSQL, semak padanan bilik dan sync ke <b>mrbs_entry</b>. CSV hanya pilihan tambahan.</p>
    </div>
    <a href="../index.php">← Dashboard</a>
  </div>

  <?php if ($error !== ''): ?><div class="alert error"><?= mbs_e($error) ?></div><?php endif; ?>
  <?php if ($success !== ''): ?><div class="alert success"><?= mbs_e($success) ?></div><?php endif; ?>

  <section class="card">
    <div class="status-grid">
      <div class="status"><span>Snapshot jadual semasa</span><b class="<?= $csvCount > 0 ? 'ok' : 'bad' ?>"><?= number_format($csvCount) ?> slot</b></div>
      <div class="status"><span>Snapshot dikemas kini</span><b><?= mbs_e($csvModified) ?></b></div>
      <div class="status"><span>MySQL MRBS</span><b class="<?= $mysqlSchemaReady ? 'ok' : 'bad' ?>"><?= $mysqlSchemaReady ? 'SEDIA' : 'BELUM DISAHKAN' ?></b></div>
      <div class="status"><span>Extract PostgreSQL</span><b class="<?= $pgQueryConfig['ready'] ? 'ok' : 'warn' ?>"><?= $pgQueryConfig['ready'] ? 'SEDIA' : 'QUERY BELUM DISEDIAKAN' ?></b></div>
    </div>
    <p class="note">Config MySQL: <code><?= mbs_e($mysqlConfig['config_path']) ?></code> (<?= mbs_e($mysqlConfig['config_source']) ?>). Destinasi: <code><?= mbs_e($mysqlConfig['dbname'] . '.' . $mysqlConfig['entry_table']) ?></code>. Sync hanya tambah rekod yang belum ada. Tiada DELETE atau TRUNCATE.</p>
  </section>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= mbs_e($csrf) ?>">

    <section class="card">
      <h2>1. Extract Terus dari PostgreSQL</h2>
      <div class="grid two">
        <div class="field">
          <label>Semester / sesi jadual</label>
          <input type="number" name="semester_id" min="1" max="9999" list="mbs-semester-list" value="<?= (int)$input['semester_id'] ?>">
          <datalist id="mbs-semester-list"><option value="49">49 — Sesi 2026/2027</option></datalist>
          <div class="semester-help">
            <b><?= mbs_e((int)$input['semester_id'] === 49 ? '49 — Sesi 2026/2027' : mbs_semester_label((int)$input['semester_id'])) ?></b>
            <span>ID semester ialah nombor dalaman sistem, bukan nombor kekal. Untuk sesi baharu, masukkan ID semester baharu.</span>
          </div>
        </div>
        <div class="quick-flow">
          <b>Cara guna</b>
          <span>1. Pilih ID semester.</span>
          <span>2. Klik <b>Preview 50 Rekod</b>.</span>
          <span>3. Jika betul, klik <b>Extract Penuh</b>.</span>
          <span>4. Semak bilik, type dan tarikh melalui <b>Preview MRBS</b>.</span>
          <span>5. Klik <b>Sync ke MRBS</b>.</span>
        </div>
      </div>
      <div class="actions">
        <button class="btn" type="submit" name="action" value="test_pg">Test PostgreSQL</button>
        <button class="btn primary" type="submit" name="action" value="preview_pg">Preview 50 Rekod</button>
        <button class="btn green" type="submit" name="action" value="extract_pg"
                onclick="return confirm('Extract penuh akan menggantikan snapshot semasa untuk semester dipilih. Teruskan?')">Extract Penuh</button>
      </div>
      <p class="note">Query: <code><?= mbs_e($pgQueryConfig['path']) ?></code>. Status semester dinamik: <b class="<?= !empty($pgQueryConfig['semester_dynamic']) ? 'ok' : 'bad' ?>"><?= !empty($pgQueryConfig['semester_dynamic']) ? 'SEDIA (:semester)' : 'BELUM — tukar nilai tetap 49 kepada :semester' ?></b>.</p>

      <details class="advanced-box">
        <summary>Opsyen tambahan: guna CSV jika PostgreSQL tidak dapat dicapai</summary>
        <div class="fileline advanced-content">
          <div class="field"><label>Upload fail CSV</label><input type="file" name="extract_csv" accept=".csv,text/csv"></div>
          <button class="btn primary" type="submit" name="action" value="upload_csv">Guna CSV Ini</button>
          <?php if ($csvCount > 0): ?><a class="btn" href="?download=1">Download Snapshot</a><?php endif; ?>
        </div>
        <p class="note">CSV tidak wajib. Preview 50 membaca terus daripada PostgreSQL tanpa menukar snapshot. Snapshot hanya diganti apabila anda klik Extract Penuh. Format CSV: <code>name, jw_hari, jw_masa, room_name, description</code>.</p>
      </details>

      <?php if ($pgPreviewRows): ?>
      <div style="margin-top:14px">
        <h2 style="margin-bottom:8px">Preview PostgreSQL — 50 Rekod Pertama</h2>
        <p class="note">Paparan ini untuk semakan sahaja. Snapshot CSV dan data MRBS belum diubah.</p>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><?php foreach (mbs_csv_headers() as $header): ?><th><?= mbs_e($header) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
              <?php foreach ($pgPreviewRows as $row): ?>
              <tr><?php foreach (mbs_csv_headers() as $header): ?><td><?= mbs_e($row[$header] ?? '') ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>2. Pilihan Sync</h2>
      <div class="grid">
        <div class="field"><label>Tarikh mula</label><input type="date" name="date_start" value="<?= mbs_e($input['date_start']) ?>"></div>
        <div class="field"><label>Tarikh tamat</label><input type="date" name="date_end" value="<?= mbs_e($input['date_end']) ?>"></div>
        <div class="field"><label>Kaedah tarikh</label><select name="date_mode"><option value="existing_dates" <?= $input['date_mode'] === 'existing_dates' ? 'selected' : '' ?>>Ikut tarikh aktif dalam MRBS</option><option value="weekdays" <?= $input['date_mode'] === 'weekdays' ? 'selected' : '' ?>>Semua Isnin–Jumaat</option></select></div>
        <div class="field"><label>Type MRBS</label><select name="type_code"><?php foreach ($typeOptions as $code => $count): ?><option value="<?= mbs_e($code) ?>" <?= $input['type_code'] === $code ? 'selected' : '' ?>><?= mbs_e($code) ?><?= $code === 'P' ? ' — pDp / biru' : '' ?> (<?= number_format($count) ?> sedia ada)</option><?php endforeach; ?></select></div>
      </div>
      <div class="actions">
        <label class="check"><input type="checkbox" name="update_existing_type" value="1" <?= $input['update_existing_type'] ? 'checked' : '' ?>> Tukar type rekod sepadan yang sudah ada</label>
      </div>
      <p class="note">Warna sebenar ikut konfigurasi type dalam MRBS. Pilihan <b>P</b> disediakan sebagai pDp/biru.</p>
    </section>

    <section class="card">
      <h2>3. Padanan Nama Bilik</h2>
      <div class="grid two">
        <div class="field"><label>Alias bilik — satu baris satu padanan</label><textarea name="alias_text" spellcheck="false"><?= mbs_e($input['alias_text']) ?></textarea></div>
        <div class="note">
          <b>Contoh:</b><br><code>AST1=56</code> → BS3 / Astro<br><code>BS1=54</code> → Bilik Serbaguna 1<br><code>221=33</code> → 221 - B1<br><br>
          Nilai selepas <code>=</code> boleh guna <b>room_id</b> atau nama bilik penuh. Nama yang sama seperti DK1, IK22 dan TA11 dipadankan automatik.
          <div class="actions"><button class="btn" type="submit" name="action" value="save_aliases">Simpan Alias</button></div>
        </div>
      </div>
    </section>

    <section class="card">
      <h2>4. Semak dan Sync</h2>
      <div class="actions" style="margin-top:0">
        <button class="btn" type="submit" name="action" value="test_mysql">Test MRBS</button>
        <button class="btn primary" type="submit" name="action" value="preview">Preview MRBS</button>
        <button class="btn green" type="submit" name="action" value="sync" onclick="return confirm('Sync jadual ke mrbs_entry sekarang? Rekod sedia ada tidak dipadam.')">Sync ke MRBS</button>
        <button class="btn amber" type="submit" name="action" value="update_type" onclick="return confirm('Tukar type bagi rekod yang sepadan dengan extract semasa?')">Tukar Type Sahaja</button>
      </div>
    </section>
  </form>

  <?php if (is_array($summary)): ?>
  <section class="card">
    <h2>Ringkasan Preview</h2>
    <div class="metric-grid">
      <div class="metric"><b><?= number_format($summary['source_rows']) ?></b><span>Slot dalam snapshot</span></div>
      <div class="metric"><b><?= number_format($summary['active_dates']) ?></b><span>Tarikh aktif</span></div>
      <div class="metric"><b><?= number_format($summary['expected_entries']) ?></b><span>Anggaran rekod MRBS</span></div>
      <div class="metric"><b><?= number_format($summary['unmapped_source_rows']) ?></b><span>Slot bilik belum padan</span></div>
    </div>
  </section>

  <section class="card">
    <h2>Padanan Bilik</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Kod Bilik Sumber</th><th>room_id</th><th>Nama Bilik MRBS</th><th>Kaedah</th></tr></thead><tbody>
      <?php foreach ($mappingRows as $map): ?><tr><td><?= mbs_e($map['source']) ?></td><td><?= $map['room_id'] !== null ? (int)$map['room_id'] : '-' ?></td><td><?= mbs_e($map['room_name'] ?: '-') ?></td><td><span class="badge <?= $map['room_id'] !== null ? 'ok' : 'bad' ?>"><?= mbs_e($map['method']) ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>

  <section class="card">
    <h2>30 Slot Pertama</h2>
    <div class="table-wrap"><table class="table"><thead><tr><?php foreach (mbs_csv_headers() as $header): ?><th><?= mbs_e($header) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($previewRows as $row): ?><tr><?php foreach (mbs_csv_headers() as $header): ?><td><?= mbs_e($row[$header] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
  <?php endif; ?>

  <?php if (is_array($syncResult)): ?>
  <section class="card">
    <h2>Keputusan Sync</h2>
    <div class="metric-grid">
      <div class="metric"><b><?= number_format($syncResult['inserted']) ?></b><span>Rekod baharu</span></div>
      <div class="metric"><b><?= number_format($syncResult['type_updated']) ?></b><span>Type dikemas kini</span></div>
      <div class="metric"><b><?= number_format($syncResult['skipped']) ?></b><span>Rekod sedia ada</span></div>
      <div class="metric"><b><?= number_format($syncResult['unmapped_rows']) ?></b><span>Slot tidak dipadankan</span></div>
    </div>
    <?php if ($syncResult['unmapped_rooms']): ?><p class="note" style="color:#ffb2bd"><b>Bilik belum padan:</b> <?= mbs_e(implode(', ', $syncResult['unmapped_rooms'])) ?></p><?php endif; ?>
    <?php if ($syncResult['by_room']): ?><div class="table-wrap" style="margin-top:12px"><table class="table"><thead><tr><th>Bilik</th><th>Rekod baharu</th></tr></thead><tbody><?php foreach ($syncResult['by_room'] as $roomName => $count): ?><tr><td><?= mbs_e($roomName) ?></td><td><?= number_format($count) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php zurie_pg_runtime_widget('mbs_sync'); ?>
</body>
</html>

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/portal_auth.php';
zurie_portal_require_extract_access();

function delima_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function delima_config(): array
{
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
        'table' => 'delima',
    ];
}

function delima_config_ready(array $config): bool
{
    return $config['enabled']
        && $config['host'] !== ''
        && $config['dbname'] !== ''
        && $config['user'] !== '';
}

function delima_connect(array $config): PDO
{
    if (!delima_config_ready($config)) {
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

function delima_quote_identifier(string $value): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        throw new RuntimeException('Nama database atau table i-SIMS tidak sah.');
    }
    return '`' . $value . '`';
}

function delima_table_ref(array $config): string
{
    return delima_quote_identifier($config['dbname']) . '.' . delima_quote_identifier($config['table']);
}

function delima_identity(PDO $pdo): array
{
    $row = $pdo->query('SELECT USER() AS session_user, CURRENT_USER() AS grant_user, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function delima_permission_message(PDO $pdo, array $config, Throwable $exception): string
{
    $identity = [];
    try {
        $identity = delima_identity($pdo);
    } catch (Throwable $ignored) {
        $identity = [];
    }

    $grantUser = trim((string)($identity['grant_user'] ?? ''));
    $accountText = $grantUser !== '' ? $grantUser : (string)$config['user'];
    $grantSql = '';

    if (preg_match('/^([^@]+)@(.+)$/', $grantUser, $matches)) {
        $user = str_replace("'", "''", $matches[1]);
        $host = str_replace("'", "''", $matches[2]);
        $grantSql = " Jalankan sebagai root: GRANT SELECT, INSERT, UPDATE ON `{$config['dbname']}`.`{$config['table']}` TO '{$user}'@'{$host}';";
    }

    return 'Akses table ' . $config['dbname'] . '.' . $config['table']
        . ' belum lengkap untuk ' . $accountText . '.'
        . $grantSql
        . ' Ralat MySQL: ' . $exception->getMessage();
}

function delima_check_table(PDO $pdo, array $config): array
{
    $table = delima_table_ref($config);
    $pdo->query("SELECT `nomatrik`, `Dacc`, `Dpass` FROM {$table} LIMIT 0");
    $count = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

    return [
        'identity' => delima_identity($pdo),
        'count' => $count,
        'columns' => ['nomatrik', 'Dacc', 'Dpass'],
    ];
}

function delima_header_key($value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', (string)$value) ?? (string)$value;
    $value = trim($value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    return preg_replace('/[^a-z0-9]+/i', '', $value) ?? '';
}

function delima_detect_delimiter(string $path): string
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Fail teks tidak dapat dibuka.');
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
        throw new RuntimeException('Fail kosong.');
    }

    $bestDelimiter = ',';
    $bestCount = 0;
    foreach ([',', ';', "\t"] as $delimiter) {
        $columns = str_getcsv($line, $delimiter, '"', '\\');
        if (count($columns) > $bestCount) {
            $bestCount = count($columns);
            $bestDelimiter = $delimiter;
        }
    }

    if ($bestCount < 2) {
        throw new RuntimeException('Format fail tidak dikenal pasti. Pastikan data mempunyai beberapa kolum.');
    }
    return $bestDelimiter;
}

function delima_validate_upload(array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException($error === UPLOAD_ERR_NO_FILE
            ? 'Pilih fail raw DELIMa terlebih dahulu.'
            : 'Upload fail gagal. Kod ralat: ' . $error . '.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 20 * 1024 * 1024) {
        throw new RuntimeException('Saiz fail mesti antara 1 bait hingga 20 MB.');
    }

    $name = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'txt', 'tsv', 'xlsx', 'xls'], true)) {
        throw new RuntimeException('Hanya fail .xlsx, .xls, .csv, .txt atau .tsv dibenarkan.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Fail upload tidak sah.');
    }
    return ['path' => $tmp, 'extension' => $extension, 'name' => $name];
}

function delima_excel_column_number(string $reference): int
{
    if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
        return 0;
    }
    $letters = strtoupper($matches[1]);
    $number = 0;
    for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
        $number = ($number * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $number - 1);
}

function delima_xml_decode(string $value): string
{
    $value = preg_replace('/<[^>]+>/', '', $value) ?? $value;
    return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function delima_xml_attribute(string $attributes, string $name): string
{
    $quoted = preg_quote($name, '/');
    if (preg_match('/(?:^|\s)(?:[A-Za-z0-9_]+:)?' . $quoted . '\s*=\s*"([^"]*)"/i', $attributes, $matches)) {
        return delima_xml_decode($matches[1]);
    }
    if (preg_match("/(?:^|\\s)(?:[A-Za-z0-9_]+:)?{$quoted}\\s*=\\s*'([^']*)'/i", $attributes, $matches)) {
        return delima_xml_decode($matches[1]);
    }
    return '';
}

function delima_xlsx_shared_strings(callable $getEntry): array
{
    $xml = $getEntry('xl/sharedStrings.xml');
    if (!is_string($xml) || $xml === '') {
        return [];
    }

    $strings = [];
    if (preg_match_all('/<(?:[A-Za-z0-9_]+:)?si\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?si>/is', $xml, $items)) {
        foreach ($items[1] as $item) {
            $parts = [];
            if (preg_match_all('/<(?:[A-Za-z0-9_]+:)?t\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?t>/is', $item, $texts)) {
                foreach ($texts[1] as $text) {
                    $parts[] = delima_xml_decode($text);
                }
            }
            $strings[] = implode('', $parts);
        }
    }
    return $strings;
}

function delima_xlsx_first_sheet_path(callable $getEntry): string
{
    $workbookXml = $getEntry('xl/workbook.xml');
    $relsXml = $getEntry('xl/_rels/workbook.xml.rels');
    if (!is_string($workbookXml) || !is_string($relsXml)) {
        if (is_string($getEntry('xl/worksheets/sheet1.xml'))) {
            return 'xl/worksheets/sheet1.xml';
        }
        throw new RuntimeException('Worksheet pertama tidak dijumpai dalam fail Excel.');
    }

    $relationshipId = '';
    if (preg_match('/<(?:[A-Za-z0-9_]+:)?sheet\b([^>]*)\/?\s*>/i', $workbookXml, $sheetMatch)) {
        $relationshipId = delima_xml_attribute($sheetMatch[1], 'id');
    }
    if ($relationshipId === '') {
        throw new RuntimeException('Fail Excel tidak dapat mengenal pasti worksheet pertama.');
    }

    if (preg_match_all('/<(?:[A-Za-z0-9_]+:)?Relationship\b([^>]*)\/?\s*>/i', $relsXml, $relationships)) {
        foreach ($relationships[1] as $attributes) {
            if (delima_xml_attribute($attributes, 'Id') !== $relationshipId) {
                continue;
            }
            $target = str_replace('\\', '/', delima_xml_attribute($attributes, 'Target'));
            $target = ltrim($target, '/');
            if (str_starts_with($target, 'xl/')) {
                return $target;
            }
            while (str_starts_with($target, '../')) {
                $target = substr($target, 3);
            }
            return 'xl/' . $target;
        }
    }

    if (is_string($getEntry('xl/worksheets/sheet1.xml'))) {
        return 'xl/worksheets/sheet1.xml';
    }
    throw new RuntimeException('Fail Excel tidak dapat menentukan worksheet pertama.');
}

function delima_read_xlsx_rows(string $path, int $maxRows = 10050): array
{
    $zip = null;
    $phar = null;
    $getEntry = null;

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $getEntry = static function (string $name) use ($zip): ?string {
                $value = $zip->getFromName($name);
                return is_string($value) ? $value : null;
            };
        } else {
            $zip = null;
        }
    }

    if ($getEntry === null && class_exists('PharData')) {
        try {
            $phar = new PharData($path);
            $getEntry = static function (string $name) use ($phar): ?string {
                try {
                    if (!isset($phar[$name])) {
                        return null;
                    }
                    return $phar[$name]->getContent();
                } catch (Throwable $ignored) {
                    return null;
                }
            };
        } catch (Throwable $ignored) {
            $phar = null;
        }
    }

    if ($getEntry === null) {
        throw new RuntimeException('Fail .xlsx tidak dapat dibuka. Aktifkan PHP ZipArchive atau Phar.');
    }

    try {
        $sharedStrings = delima_xlsx_shared_strings($getEntry);
        $sheetPath = delima_xlsx_first_sheet_path($getEntry);
        $sheetXml = $getEntry($sheetPath);
        if (!is_string($sheetXml) || $sheetXml === '') {
            throw new RuntimeException('Data worksheet pertama tidak dapat dibaca.');
        }

        $rows = [];
        if (preg_match_all('/<(?:[A-Za-z0-9_]+:)?row\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?row>/is', $sheetXml, $rowMatches)) {
            foreach ($rowMatches[1] as $rowXml) {
                $row = [];
                $cellPattern = '/<(?:[A-Za-z0-9_]+:)?c\b([^>]*)>(.*?)<\/(?:[A-Za-z0-9_]+:)?c>|<(?:[A-Za-z0-9_]+:)?c\b([^>]*)\/>/is';
                if (preg_match_all($cellPattern, $rowXml, $cells, PREG_SET_ORDER)) {
                    foreach ($cells as $cell) {
                        $attributes = (string)(($cell[1] ?? '') !== '' ? $cell[1] : ($cell[3] ?? ''));
                        $body = (string)($cell[2] ?? '');
                        $reference = delima_xml_attribute($attributes, 'r');
                        $columnIndex = delima_excel_column_number($reference);
                        $type = delima_xml_attribute($attributes, 't');
                        $value = '';

                        if ($type === 'inlineStr') {
                            $parts = [];
                            if (preg_match_all('/<(?:[A-Za-z0-9_]+:)?t\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?t>/is', $body, $texts)) {
                                foreach ($texts[1] as $text) {
                                    $parts[] = delima_xml_decode($text);
                                }
                            }
                            $value = implode('', $parts);
                        } else {
                            $raw = '';
                            if (preg_match('/<(?:[A-Za-z0-9_]+:)?v\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?v>/is', $body, $valueMatch)) {
                                $raw = delima_xml_decode($valueMatch[1]);
                            }
                            if ($type === 's') {
                                $value = $sharedStrings[(int)$raw] ?? '';
                            } elseif ($type === 'b') {
                                $value = $raw === '1' ? 'TRUE' : 'FALSE';
                            } else {
                                $value = $raw;
                            }
                        }
                        $row[$columnIndex] = $value;
                    }
                }
                if ($row) {
                    $maxColumn = max(array_keys($row));
                    $normalized = [];
                    for ($i = 0; $i <= $maxColumn; $i++) {
                        $normalized[] = (string)($row[$i] ?? '');
                    }
                    $rows[] = $normalized;
                    if (count($rows) > $maxRows) {
                        throw new RuntimeException('Excel melebihi had ' . ($maxRows - 50) . ' rekod.');
                    }
                }
            }
        }
        if (!$rows) {
            throw new RuntimeException('Worksheet Excel tidak mengandungi data yang boleh dibaca.');
        }
        return $rows;
    } finally {
        if ($zip instanceof ZipArchive) {
            $zip->close();
        }
    }
}

function delima_read_excel_xml_rows(string $content, int $maxRows = 10050): array
{
    $rows = [];
    if (preg_match_all('/<(?:[A-Za-z0-9_]+:)?Row\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?Row>/is', $content, $rowMatches)) {
        foreach ($rowMatches[1] as $rowXml) {
            $row = [];
            $column = 0;
            if (preg_match_all('/<(?:[A-Za-z0-9_]+:)?Cell\b([^>]*)>(.*?)<\/(?:[A-Za-z0-9_]+:)?Cell>/is', $rowXml, $cells, PREG_SET_ORDER)) {
                foreach ($cells as $cell) {
                    $index = delima_xml_attribute((string)$cell[1], 'Index');
                    if ($index !== '') {
                        $column = max(0, (int)$index - 1);
                    }
                    $value = '';
                    if (preg_match('/<(?:[A-Za-z0-9_]+:)?Data\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?Data>/is', (string)$cell[2], $dataMatch)) {
                        $value = trim(delima_xml_decode($dataMatch[1]));
                    }
                    $row[$column] = $value;
                    $column++;
                }
            }
            if ($row) {
                $maxColumn = max(array_keys($row));
                $normalized = [];
                for ($i = 0; $i <= $maxColumn; $i++) {
                    $normalized[] = (string)($row[$i] ?? '');
                }
                $rows[] = $normalized;
                if (count($rows) > $maxRows) {
                    throw new RuntimeException('Excel melebihi had ' . ($maxRows - 50) . ' rekod.');
                }
            }
        }
    }
    return $rows;
}

function delima_read_excel_html_rows(string $content, int $maxRows = 10050): array
{
    $rows = [];
    if (preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $content, $rowMatches)) {
        foreach ($rowMatches[1] as $rowHtml) {
            $row = [];
            if (preg_match_all('/<(?:th|td)\b[^>]*>(.*?)<\/(?:th|td)>/is', $rowHtml, $cells)) {
                foreach ($cells[1] as $cell) {
                    $row[] = trim(html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
            }
            if ($row) {
                $rows[] = $row;
                if (count($rows) > $maxRows) {
                    throw new RuntimeException('Excel melebihi had ' . ($maxRows - 50) . ' rekod.');
                }
            }
        }
    }
    return $rows;
}

function delima_read_xls_rows(string $path, int $maxRows = 10050): array
{
    $content = file_get_contents($path);
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Fail .xls tidak dapat dibaca.');
    }

    if (str_starts_with($content, "PK\x03\x04")) {
        return delima_read_xlsx_rows($path, $maxRows);
    }
    $trimmed = ltrim($content);
    if (str_starts_with($trimmed, '<?xml') || stripos($trimmed, '<Workbook') !== false) {
        $rows = delima_read_excel_xml_rows($content, $maxRows);
        if ($rows) {
            return $rows;
        }
    }
    if (stripos($content, '<table') !== false) {
        $rows = delima_read_excel_html_rows($content, $maxRows);
        if ($rows) {
            return $rows;
        }
    }

    throw new RuntimeException('Fail .xls ini menggunakan format binary Excel lama. Buka fail tersebut dan Save As .xlsx, kemudian upload semula.');
}

function delima_read_text_rows(string $path, int $maxRows = 10050): array
{
    $delimiter = delima_detect_delimiter($path);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Fail teks tidak dapat dibuka.');
    }
    $rows = [];
    while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $rows[] = array_map(static fn($value): string => (string)$value, $data);
        if (count($rows) > $maxRows) {
            fclose($handle);
            throw new RuntimeException('Fail melebihi had ' . ($maxRows - 50) . ' rekod.');
        }
    }
    fclose($handle);
    return ['rows' => $rows, 'format' => $delimiter === "\t" ? 'TAB' : $delimiter];
}

function delima_mask_password(string $password): string
{
    $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    if ($length <= 4) {
        return str_repeat('•', max(1, $length));
    }
    $first = function_exists('mb_substr') ? mb_substr($password, 0, 2, 'UTF-8') : substr($password, 0, 2);
    $last = function_exists('mb_substr') ? mb_substr($password, -2, null, 'UTF-8') : substr($password, -2);
    return $first . str_repeat('•', min(10, max(3, $length - 4))) . $last;
}

function delima_parse_table_rows(array $tableRows, string $masterPassword, string $sourceFormat, int $maxRows = 10000): array
{
    $header = null;
    $headerLineNo = 0;
    foreach ($tableRows as $index => $candidate) {
        $headerLineNo = $index + 1;
        if (count(array_filter((array)$candidate, static fn($v): bool => trim((string)$v) !== '')) > 0) {
            $header = array_values((array)$candidate);
            break;
        }
    }

    if (!is_array($header)) {
        throw new RuntimeException('Header fail tidak dijumpai.');
    }

    $positions = [];
    foreach ($header as $index => $column) {
        $positions[delima_header_key($column)] = $index;
    }

    $aliases = [
        'nomatrik' => [
            'nomatrik', 'nomatrikbaru', 'nomatrikpelajar', 'matrik', 'matric',
            'matricno', 'studentid', 'studentnumber', 'idpelajar'
        ],
        'account' => [
            'delimafinal', 'dacc', 'acc', 'account', 'akaun', 'username',
            'userprincipalname', 'upn', 'email', 'emailaddress'
        ],
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

    if (!isset($columnIndex['nomatrik']) || !isset($columnIndex['account'])) {
        throw new RuntimeException('Kolum No Matrik Baru atau DELIMa FINAL tidak dijumpai dalam fail raw.');
    }

    $masterPassword = trim($masterPassword);
    if ($masterPassword === '') {
        throw new RuntimeException('Masukkan Master Password DELIMa. Contoh: Delim5@');
    }
    $masterLength = function_exists('mb_strlen') ? mb_strlen($masterPassword, 'UTF-8') : strlen($masterPassword);
    if ($masterLength > 45) {
        throw new RuntimeException('Master Password terlalu panjang. Maksimum 45 aksara.');
    }

    $rowsByMatric = [];
    $issues = [];
    $duplicates = 0;

    foreach ($tableRows as $index => $data) {
        $lineNo = $index + 1;
        if ($lineNo <= $headerLineNo) {
            continue;
        }
        $data = array_values((array)$data);
        if ($data === [null] || $data === [] || count(array_filter($data, static fn($v): bool => trim((string)$v) !== '')) === 0) {
            continue;
        }

        $account = trim((string)($data[$columnIndex['account']] ?? ''));
        $nomatrik = strtoupper(trim((string)($data[$columnIndex['nomatrik']] ?? '')));

        if ($nomatrik === '' || !preg_match('/^[A-Z0-9._-]{4,30}$/', $nomatrik)) {
            $issues[] = 'Baris ' . $lineNo . ': No Matrik Baru tidak sah.';
            continue;
        }
        if ($account === '' || !filter_var($account, FILTER_VALIDATE_EMAIL)) {
            $issues[] = 'Baris ' . $lineNo . ': akaun dalam DELIMa FINAL kosong atau bukan alamat e-mel yang sah.';
            continue;
        }

        $matricCompact = preg_replace('/[^A-Z0-9]/', '', $nomatrik) ?? '';
        if (strlen($matricCompact) < 5) {
            $issues[] = 'Baris ' . $lineNo . ': nombor matrik tidak mempunyai sekurang-kurangnya 5 aksara.';
            continue;
        }
        $password = $masterPassword . substr($matricCompact, -5);
        $passwordLength = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        if ($passwordLength > 50) {
            $issues[] = 'Baris ' . $lineNo . ': Dpass melebihi had 50 aksara.';
            continue;
        }

        if (isset($rowsByMatric[$nomatrik])) {
            $duplicates++;
        }

        $rowsByMatric[$nomatrik] = [
            'nomatrik' => $nomatrik,
            'Dacc' => $account,
            'Dpass' => $password,
        ];

        if (count($rowsByMatric) > $maxRows) {
            throw new RuntimeException('Fail melebihi had ' . $maxRows . ' rekod.');
        }
    }

    if (!$rowsByMatric) {
        $detail = $issues ? ' ' . implode(' ', array_slice($issues, 0, 3)) : '';
        throw new RuntimeException('Tiada rekod DELIMa yang sah ditemui.' . $detail);
    }

    return [
        'rows' => array_values($rowsByMatric),
        'issues' => $issues,
        'duplicates' => $duplicates,
        'delimiter' => $sourceFormat,
    ];
}

function delima_read_upload(array $upload, string $masterPassword, int $maxRows = 10000): array
{
    $path = (string)($upload['path'] ?? '');
    $extension = strtolower((string)($upload['extension'] ?? ''));
    if ($path === '') {
        throw new RuntimeException('Fail upload tidak sah.');
    }

    if ($extension === 'xlsx') {
        $rows = delima_read_xlsx_rows($path, $maxRows + 50);
        return delima_parse_table_rows($rows, $masterPassword, 'EXCEL XLSX', $maxRows);
    }
    if ($extension === 'xls') {
        $rows = delima_read_xls_rows($path, $maxRows + 50);
        return delima_parse_table_rows($rows, $masterPassword, 'EXCEL XLS', $maxRows);
    }

    $text = delima_read_text_rows($path, $maxRows + 50);
    return delima_parse_table_rows($text['rows'], $masterPassword, (string)$text['format'], $maxRows);
}

function delima_output_csv(array $rows): void
{
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Tidak dapat membina CSV DELIMa.');
    }

    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, ['nomatrik', 'Dacc', 'Dpass'], ',', '"', '\\', "\r\n");
    foreach ($rows as $row) {
        fputcsv($stream, [
            $row['nomatrik'] ?? '',
            $row['Dacc'] ?? '',
            $row['Dpass'] ?? '',
        ], ',', '"', '\\', "\r\n");
    }

    rewind($stream);
    $content = stream_get_contents($stream);
    fclose($stream);
    if ($content === false) {
        throw new RuntimeException('CSV DELIMa tidak dapat dibaca.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = 'DELIMA_ISIMS_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $content;
    exit;
}

function delima_import_rows(PDO $pdo, array $config, array $rows): array
{
    $table = delima_table_ref($config);
    $stmt = $pdo->prepare(
        "INSERT INTO {$table} (`nomatrik`, `Dacc`, `Dpass`) VALUES (?, ?, ?) "
        . "ON DUPLICATE KEY UPDATE `Dacc` = VALUES(`Dacc`), `Dpass` = VALUES(`Dpass`)"
    );

    $inserted = 0;
    $updated = 0;
    $unchanged = 0;
    $processed = 0;

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $nomatrik = trim((string)($row['nomatrik'] ?? ''));
            $account = trim((string)($row['Dacc'] ?? ''));
            $password = (string)($row['Dpass'] ?? '');
            if ($nomatrik === '' || $account === '' || $password === '') {
                continue;
            }

            $stmt->execute([$nomatrik, $account, $password]);
            $affected = $stmt->rowCount();
            $processed++;
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
        if ($exception instanceof PDOException && (int)($exception->errorInfo[1] ?? 0) === 1142) {
            throw new RuntimeException(delima_permission_message($pdo, $config, $exception), 0, $exception);
        }
        throw $exception;
    }

    return [
        'processed' => $processed,
        'inserted' => $inserted,
        'updated' => $updated,
        'unchanged' => $unchanged,
    ];
}

function delima_fetch_synced_accounts(PDO $pdo, array $config, array $rows, int $limit = 100): array
{
    $matrics = [];
    $expected = [];
    foreach ($rows as $row) {
        $matric = strtoupper(trim((string)($row['nomatrik'] ?? '')));
        if ($matric === '' || isset($expected[$matric])) {
            continue;
        }
        $expected[$matric] = trim((string)($row['Dacc'] ?? ''));
        $matrics[] = $matric;
        if (count($matrics) >= $limit) {
            break;
        }
    }

    if (!$matrics) {
        return [];
    }

    $table = delima_table_ref($config);
    $placeholders = implode(',', array_fill(0, count($matrics), '?'));
    $stmt = $pdo->prepare("SELECT `nomatrik`, `Dacc` FROM {$table} WHERE `nomatrik` IN ({$placeholders})");
    $stmt->execute($matrics);

    $found = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtoupper(trim((string)($row['nomatrik'] ?? '')));
        if ($key !== '') {
            $found[$key] = trim((string)($row['Dacc'] ?? ''));
        }
    }

    $result = [];
    foreach ($matrics as $matric) {
        $actual = $found[$matric] ?? '';
        $wanted = $expected[$matric] ?? '';
        $result[] = [
            'nomatrik' => $matric,
            'Dacc' => $actual,
            'status' => $actual === '' ? 'TIDAK DIJUMPAI' : (strcasecmp($actual, $wanted) === 0 ? 'OK' : 'BERBEZA'),
        ];
    }
    return $result;
}

$config = delima_config();
$action = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? (string)($_POST['action'] ?? '') : '';
if ($action !== '') {
    zurie_security_require_valid_csrf();
}

$error = '';
$success = '';
$csvRows = [];
$csvIssues = [];
$csvDuplicates = 0;
$csvDelimiter = '';
$testResult = null;
$syncResult = null;
$syncedRows = [];
$masterPassword = trim((string)($_POST['master_password'] ?? 'Delim5@'));
if ($masterPassword === '') {
    $masterPassword = 'Delim5@';
}

if ($action === 'test_isims') {
    try {
        $pdo = delima_connect($config);
        $testResult = delima_check_table($pdo, $config);
        $grantUser = (string)($testResult['identity']['grant_user'] ?? $config['user']);
        $success = 'i-SIMS OK. Table delima boleh dibaca. Jumlah rekod: '
            . (int)$testResult['count'] . '. Akaun DB: ' . $grantUser . '.';
    } catch (Throwable $exception) {
        if ($exception instanceof PDOException && (int)($exception->errorInfo[1] ?? 0) === 1142 && isset($pdo)) {
            $error = delima_permission_message($pdo, $config, $exception);
        } else {
            $error = $exception->getMessage();
        }
    }
}

if (in_array($action, ['preview_csv', 'convert_csv', 'sync_csv'], true)) {
    try {
        $uploadedFile = delima_validate_upload($_FILES['delima_csv'] ?? []);
        $parsed = delima_read_upload($uploadedFile, $masterPassword);
        $csvRows = $parsed['rows'];
        $csvIssues = $parsed['issues'];
        $csvDuplicates = (int)$parsed['duplicates'];
        $csvDelimiter = (string)$parsed['delimiter'];

        if ($action === 'convert_csv') {
            delima_output_csv($csvRows);
        }

        if ($action === 'sync_csv') {
            $pdo = delima_connect($config);
            $syncResult = delima_import_rows($pdo, $config, $csvRows);
            $syncedRows = delima_fetch_synced_accounts($pdo, $config, $csvRows, 100);
            $verified = count(array_filter($syncedRows, static fn(array $row): bool => ($row['status'] ?? '') === 'OK'));
            $success = 'Sync DELIMa selesai. Diproses: ' . (int)$syncResult['processed']
                . ', baharu: ' . (int)$syncResult['inserted']
                . ', dikemas kini: ' . (int)$syncResult['updated']
                . ', tiada perubahan: ' . (int)$syncResult['unchanged']
                . '. Disahkan: ' . $verified . '/' . count($syncedRows) . ' akaun.';
        } else {
            $success = 'Fail berjaya dibaca. ' . count($csvRows) . ' rekod sah ditemui.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$mysqlDriverAvailable = class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true);
$configExists = is_file($config['config_path']);
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DELIMa ke i-SIMS</title>
<link rel="icon" href="/zurie/image/zuriex.jpg">
<style>
:root{--bg:#07111f;--card:#0d1c2e;--line:rgba(130,170,210,.18);--text:#eaf4ff;--muted:#86a0b8;--cyan:#55d9ff;--green:#51e3a4;--red:#ff7183}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top left,#123456 0,#07111f 36%,#040b14 100%);color:var(--text);font-family:Segoe UI,Arial,sans-serif;font-size:13px}.wrap{max-width:1200px;margin:0 auto;padding:18px}.top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px}.top-nav{display:flex;gap:12px;flex-wrap:wrap}.top a{color:#9ddfff;text-decoration:none}.title h1{margin:0;font-size:22px}.title p{margin:4px 0 0;color:var(--muted)}.card{background:linear-gradient(145deg,rgba(13,28,46,.96),rgba(8,18,31,.96));border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.25);padding:16px;margin-bottom:14px}.status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.status{padding:10px;border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.02)}.status span{display:block;color:var(--muted);font-size:10px}.status b{display:block;margin-top:3px}.ok{color:var(--green)}.bad{color:var(--red)}.field label{display:block;color:var(--muted);font-size:10px;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em}.field input{width:100%;border:1px solid rgba(130,170,210,.25);background:#081523;color:var(--text);border-radius:10px;padding:10px 11px;outline:none}.field input:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(85,217,255,.09)}.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}.btn{position:relative;border:1px solid rgba(85,217,255,.32);background:rgba(85,217,255,.10);color:#bff0ff;border-radius:10px;padding:10px 13px;text-decoration:none;cursor:pointer;font-weight:700}.btn[data-tip]::before{content:attr(data-tip);position:absolute;left:50%;bottom:calc(100% + 10px);transform:translateX(-50%) translateY(4px);min-width:220px;max-width:300px;padding:8px 10px;border:1px solid rgba(157,231,255,.28);border-radius:8px;background:#020914;color:#eaf4ff;font-size:11px;font-weight:500;line-height:1.35;text-align:center;white-space:normal;box-shadow:0 10px 24px rgba(0,0,0,.38);opacity:0;visibility:hidden;pointer-events:none;z-index:20;transition:.15s ease}.btn[data-tip]::after{content:"";position:absolute;left:50%;bottom:calc(100% + 4px);transform:translateX(-50%);border:6px solid transparent;border-top-color:#020914;opacity:0;visibility:hidden;pointer-events:none;z-index:21}.btn[data-tip]:hover::before,.btn[data-tip]:focus-visible::before,.btn[data-tip]:hover::after,.btn[data-tip]:focus-visible::after{opacity:1;visibility:visible;transform:translateX(-50%) translateY(0)}.btn.primary{background:linear-gradient(135deg,rgba(85,217,255,.22),rgba(81,227,164,.14));border-color:rgba(85,217,255,.55)}.btn.export{background:linear-gradient(135deg,rgba(81,227,164,.22),rgba(85,217,255,.12));border-color:rgba(81,227,164,.5);color:#aaffd9}.alert{padding:11px 13px;border-radius:11px;margin-bottom:13px;line-height:1.55}.alert.error{border:1px solid rgba(255,113,131,.3);background:rgba(255,113,131,.08);color:#ffc1c9}.alert.success{border:1px solid rgba(81,227,164,.28);background:rgba(81,227,164,.08);color:#aaffd9}.sample{margin-top:12px;padding:10px;border:1px dashed rgba(85,217,255,.22);border-radius:10px;color:#9db4c8;font-size:11px;line-height:1.65}.sample strong{color:#dff7ff}.sample code{color:#c9efff;background:#06111d;padding:2px 5px;border-radius:5px}.preview-wrap{overflow:auto;max-height:430px;border:1px solid var(--line);border-radius:12px;margin-top:12px}.preview{width:100%;border-collapse:collapse;font-size:11px;white-space:nowrap}.preview th,.preview td{padding:8px 9px;border-bottom:1px solid rgba(130,170,210,.1);border-right:1px solid rgba(130,170,210,.07);text-align:left}.preview th{position:sticky;top:0;background:#10263d;color:#9de7ff;z-index:1}.preview td{color:#c4d5e4}.setup{color:var(--muted);font-size:12px;line-height:1.6}@media(max-width:820px){.status-grid{grid-template-columns:1fr}.top{display:block}.top-nav{margin-top:10px}.wrap{padding:12px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="title"><h1>DELIMa</h1><p>Upload Excel atau CSV, jana kata laluan dan sync akaun DELIMa ke i-SIMS.</p></div>
    <div class="top-nav"><a href="ms365_export.php">← MS 365</a><a href="../index.php">Dashboard</a></div>
  </div>

  <?php if ($error !== ''): ?><div class="alert error"><?= delima_e($error) ?></div><?php endif; ?>
  <?php if ($success !== ''): ?><div class="alert success"><?= delima_e($success) ?></div><?php endif; ?>

  <section class="card">
    <div class="status-grid">
      <div class="status"><span>PHP PDO MySQL</span><b class="<?= $mysqlDriverAvailable ? 'ok' : 'bad' ?>"><?= $mysqlDriverAvailable ? 'AKTIF' : 'TIDAK AKTIF' ?></b></div>
      <div class="status"><span>Konfigurasi i-SIMS</span><b class="<?= $configExists && delima_config_ready($config) ? 'ok' : 'bad' ?>"><?= $configExists && delima_config_ready($config) ? 'SEDIA' : 'BELUM LENGKAP' ?></b></div>
      <div class="status"><span>Table sasaran</span><b><?= delima_e(($config['dbname'] ?: 'db_pelajarkmp') . '.delima') ?></b></div>
    </div>
    <div class="sample">
      <strong>Data raw digunakan:</strong> <code>No Matrik Baru</code> dan <code>DELIMa FINAL</code>.
      Sistem akan membina sendiri output <code>nomatrik</code> · <code>Dacc</code> · <code>Dpass</code>.
      Formula kata laluan: <code>Master Password + 5 digit terakhir No. Matrik</code>.
    </div>
  </section>

  <section class="card">
    <form method="post" action="delima_sync.php" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= delima_e(zurie_security_csrf_token()) ?>">
      <div class="actions" style="margin-top:0;margin-bottom:12px">
        <a class="btn export" href="../templates/template_delima.xlsx" download title="Muat turun template Excel kosong">⬇ Muat Turun Template Excel</a>
        <a class="btn" href="../templates/template_delima.csv" download title="Muat turun template CSV kosong">⬇ Muat Turun Template CSV</a>
      </div>
      <div class="field">
        <label>Fail Raw DELIMa</label>
        <input type="file" name="delima_csv" accept=".xlsx,.xls,.csv,.txt,.tsv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv,text/tab-separated-values">
        <small style="display:block;margin-top:6px;color:var(--muted)">Terima fail <b>Excel (.xlsx/.xls)</b>, CSV, TXT atau TSV. Fail mesti mempunyai kolum <b>No Matrik Baru</b> dan <b>DELIMa FINAL</b>. Kolum lain akan diabaikan.<br><span style="font-size:10px">.xlsx disokong terus. Untuk fail .xls binary lama, gunakan Save As .xlsx dahulu.</span></small>
      </div>
      <div class="field" style="margin-top:12px;max-width:420px">
        <label>Master Password DELIMa</label>
        <input id="masterPassword" type="password" name="master_password" value="<?= delima_e($masterPassword) ?>" maxlength="45" autocomplete="new-password" required>
        <small style="display:block;margin-top:6px;color:var(--muted)">Default: <b>Delim5@</b>. Contoh MA2614110409 akan menjadi <b>Delim5@10409</b>.</small>
        <label style="display:inline-flex;align-items:center;gap:7px;margin-top:8px;color:var(--muted);font-size:11px;text-transform:none;letter-spacing:0"><input type="checkbox" style="width:auto" onchange="document.getElementById('masterPassword').type=this.checked?'text':'password'"> Papar Master Password</label>
      </div>
      <div class="actions">
        <button class="btn" type="submit" name="action" value="test_isims" formnovalidate title="Uji akses table delima" data-tip="Semak sambungan, akses SELECT, struktur nomatrik/Dacc/Dpass dan jumlah rekod dalam table delima.">Uji i-SIMS</button>
        <button class="btn primary" type="submit" name="action" value="preview_csv" title="Semak kandungan fail" data-tip="Baca fail Excel atau CSV, ambil No Matrik Baru dan DELIMa FINAL, kemudian jana Dpass tanpa menyimpan ke i-SIMS.">Semak Fail</button>
        <button class="btn" type="submit" name="action" value="convert_csv" title="Tukar fail kepada format table DELIMa" data-tip="Baca fail Excel atau CSV, kemudian muat turun CSV baharu dengan tajuk nomatrik, Dacc dan Dpass.">Muat Turun CSV i-SIMS</button>
        <button class="btn export" type="submit" name="action" value="sync_csv" title="Sync akaun DELIMa ke i-SIMS" data-tip="Jana Dpass menggunakan Master Password dan 5 digit terakhir nombor matrik, kemudian tambah atau kemas kini rekod i-SIMS." onclick="return confirm('Sync akaun dan kata laluan DELIMa ke i-SIMS?')">Sync ke i-SIMS</button>
      </div>
    </form>

    <?php if ($testResult): ?>
      <div class="sample">
        <strong>Semakan i-SIMS:</strong>
        server <?= delima_e($config['host']) ?>,
        database <?= delima_e((string)($testResult['identity']['database_name'] ?? $config['dbname'])) ?>,
        akaun <?= delima_e((string)($testResult['identity']['grant_user'] ?? $config['user'])) ?>,
        <?= (int)$testResult['count'] ?> rekod dalam <code>delima</code>.
      </div>
    <?php endif; ?>

    <?php if ($syncedRows): ?>
      <div class="sample"><strong>Semakan selepas sync:</strong> Akaun dibaca semula daripada i-SIMS. Kata laluan tidak dipaparkan.</div>
      <div class="preview-wrap"><table class="preview">
        <thead><tr><th>No. Matrik</th><th>Akaun DELIMa</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($syncedRows as $row): ?>
          <tr>
            <td><?= delima_e($row['nomatrik']) ?></td>
            <td><?= delima_e($row['Dacc']) ?></td>
            <td class="<?= ($row['status'] ?? '') === 'OK' ? 'ok' : 'bad' ?>"><?= delima_e($row['status']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if (count($csvRows) > count($syncedRows)): ?><p class="setup">Semakan dipaparkan untuk 100 rekod pertama sahaja.</p><?php endif; ?>
    <?php endif; ?>

    <?php if ($csvRows): ?>
      <div class="sample">
        <strong>Hasil penukaran:</strong> <?= count($csvRows) ?> rekod sah
        · format <?= delima_e($csvDelimiter) ?>
        · <?= (int)$csvDuplicates ?> rekod pendua
        · <?= count($csvIssues) ?> baris tidak sah dilangkau.
        Kata laluan hanya dipaparkan secara terlindung.
      </div>
      <div class="preview-wrap"><table class="preview">
        <thead><tr><th>No. Matrik</th><th>Dacc</th><th>Dpass</th></tr></thead>
        <tbody>
          <?php foreach (array_slice($csvRows, 0, 30) as $row): ?>
          <tr>
            <td><?= delima_e($row['nomatrik']) ?></td>
            <td><?= delima_e($row['Dacc']) ?></td>
            <td><?= delima_e(delima_mask_password((string)$row['Dpass'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if (count($csvRows) > 30): ?><p class="setup">Preview memaparkan 30 rekod pertama sahaja.</p><?php endif; ?>
      <?php if ($csvIssues): ?>
        <div class="alert error" style="margin-top:12px"><strong>Baris dilangkau:</strong><br><?= delima_e(implode(' | ', array_slice($csvIssues, 0, 8))) ?><?= count($csvIssues) > 8 ? ' ...' : '' ?></div>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

function zurie_isims_sync_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function zurie_isims_sync_config_path(): string
{
    return dirname(__DIR__) . '/config/isims_mysql_config.php';
}

function zurie_isims_sync_config(): array
{
    $path = zurie_isims_sync_config_path();
    $loaded = is_file($path) ? require $path : [];
    $loaded = is_array($loaded) ? $loaded : [];
    return [
        'host' => trim((string)($loaded['host'] ?? '')),
        'port' => (int)($loaded['port'] ?? 3306),
        'database' => trim((string)($loaded['database'] ?? $loaded['dbname'] ?? '')),
        'username' => trim((string)($loaded['username'] ?? $loaded['user'] ?? '')),
        'password' => (string)($loaded['password'] ?? ''),
        'charset' => trim((string)($loaded['charset'] ?? 'utf8mb4')),
        'target_table' => zurie_isims_sync_clean_identifier((string)($loaded['target_table'] ?? 'senarai')),
        'source_table' => zurie_isims_sync_clean_identifier((string)($loaded['source_table'] ?? 'senarai_mis_lengkap')),
        'config_path' => $path,
    ];
}

function zurie_isims_sync_clean_identifier(string $value): string
{
    $value = trim($value);
    return preg_match('/^[A-Za-z0-9_]+$/', $value) ? $value : '';
}

function zurie_isims_sync_quote_identifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Nama table/column tidak sah: ' . $name);
    }
    return '`' . $name . '`';
}

function zurie_isims_sync_ready(array $config): bool
{
    return $config['host'] !== ''
        && $config['database'] !== ''
        && $config['username'] !== ''
        && $config['target_table'] !== ''
        && $config['source_table'] !== '';
}

function zurie_isims_sync_local_pdo(): PDO
{
    $configFile = dirname(__DIR__) . '/config/vault_config.php';
    $db = is_file($configFile) ? require $configFile : [];
    $db = is_array($db) ? $db : [];
    $dsn = (string)($db['dsn'] ?? 'mysql:host=localhost;dbname=zurie_noc;charset=utf8mb4');
    $username = (string)($db['username'] ?? 'root');
    $password = (string)($db['password'] ?? '');
    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
}

function zurie_isims_sync_remote_pdo(array $config): PDO
{
    if (!zurie_isims_sync_ready($config)) {
        throw new RuntimeException('Konfigurasi i-SIMS belum lengkap. Sila cipta /zurie/config/isims_mysql_config.php berdasarkan fail .example.');
    }
    $charset = $config['charset'] !== '' ? $config['charset'] : 'utf8mb4';
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $charset);
    return new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 15,
    ]);
}

function zurie_isims_sync_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    $stmt->execute([$table]);
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
        $col = (string)$col;
        if (preg_match('/^[A-Za-z0-9_]+$/', $col)) {
            $cols[] = $col;
        }
    }
    return $cols;
}

function zurie_isims_sync_pick_key(array $sourceCols, array $targetCols): string
{
    $candidates = ['nomatrik', 'matrik', 'no_matrik', 'NO_MATRIK', 'NOMATRIK'];
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $sourceCols, true) && in_array($candidate, $targetCols, true)) {
            return $candidate;
        }
    }
    $lowerSource = array_change_key_case(array_flip($sourceCols), CASE_LOWER);
    $lowerTarget = array_change_key_case(array_flip($targetCols), CASE_LOWER);
    foreach (['nomatrik', 'matrik', 'no_matrik'] as $candidate) {
        if (isset($lowerSource[$candidate], $lowerTarget[$candidate])) {
            return (string)$lowerSource[$candidate];
        }
    }
    throw new RuntimeException('Column kunci matrik/nomatrik tidak ditemui pada table sumber dan target.');
}

function zurie_isims_sync_common_columns(array $sourceCols, array $targetCols): array
{
    $targetSet = array_flip($targetCols);
    $skip = ['id', 'created_at', 'updated_at', 'synced_at'];
    $cols = [];
    foreach ($sourceCols as $col) {
        if (isset($targetSet[$col]) && !in_array(strtolower($col), $skip, true)) {
            $cols[] = $col;
        }
    }
    return $cols;
}

function zurie_isims_sync_row_hash(array $row, array $cols): string
{
    $data = [];
    foreach ($cols as $col) {
        $data[$col] = trim((string)($row[$col] ?? ''));
    }
    return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function zurie_isims_sync_fetch_rows(PDO $pdo, string $table, string $keyCol, array $cols, int $limit = 10000): array
{
    $selectCols = array_values(array_unique(array_merge([$keyCol], $cols)));
    $select = implode(',', array_map('zurie_isims_sync_quote_identifier', $selectCols));
    $tableSql = zurie_isims_sync_quote_identifier($table);
    $keySql = zurie_isims_sync_quote_identifier($keyCol);
    $limit = max(1, min(20000, $limit));
    $stmt = $pdo->query("SELECT {$select} FROM {$tableSql} WHERE {$keySql} IS NOT NULL AND {$keySql} <> '' LIMIT {$limit}");
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtoupper(trim((string)($row[$keyCol] ?? '')));
        if ($key !== '') {
            $rows[$key] = $row;
        }
    }
    return $rows;
}

function zurie_isims_sync_plan(int $sampleLimit = 30): array
{
    $config = zurie_isims_sync_config();
    if (!zurie_isims_sync_ready($config)) {
        return [
            'ok' => false,
            'configured' => false,
            'error' => 'Konfigurasi i-SIMS belum lengkap.',
            'config_path' => $config['config_path'],
            'example_path' => dirname(__DIR__) . '/config/isims_mysql_config.php.example',
        ];
    }

    $local = zurie_isims_sync_local_pdo();
    $remote = zurie_isims_sync_remote_pdo($config);
    $sourceTable = $config['source_table'];
    $targetTable = $config['target_table'];
    $sourceCols = zurie_isims_sync_columns($local, $sourceTable);
    $targetCols = zurie_isims_sync_columns($remote, $targetTable);
    if (!$sourceCols) {
        throw new RuntimeException('Table sumber tidak ditemui atau tiada column: ' . $sourceTable);
    }
    if (!$targetCols) {
        throw new RuntimeException('Table target tidak ditemui atau tiada column: ' . $targetTable);
    }
    $keyCol = zurie_isims_sync_pick_key($sourceCols, $targetCols);
    $cols = zurie_isims_sync_common_columns($sourceCols, $targetCols);
    if (!in_array($keyCol, $cols, true)) {
        array_unshift($cols, $keyCol);
    }
    if (count($cols) < 2) {
        throw new RuntimeException('Column sepadan terlalu sedikit untuk sync. Semak struktur table sumber/target.');
    }

    $sourceRows = zurie_isims_sync_fetch_rows($local, $sourceTable, $keyCol, $cols);
    $targetRows = zurie_isims_sync_fetch_rows($remote, $targetTable, $keyCol, $cols);

    $insert = [];
    $update = [];
    foreach ($sourceRows as $key => $row) {
        if (!isset($targetRows[$key])) {
            $insert[] = ['key' => $key, 'nama' => (string)($row['nama'] ?? $row['NAMA'] ?? ''), 'reason' => 'Rekod baharu'];
            continue;
        }
        if (zurie_isims_sync_row_hash($row, $cols) !== zurie_isims_sync_row_hash($targetRows[$key], $cols)) {
            $update[] = ['key' => $key, 'nama' => (string)($row['nama'] ?? $row['NAMA'] ?? ''), 'reason' => 'Data berubah'];
        }
    }

    return [
        'ok' => true,
        'configured' => true,
        'source_table' => $sourceTable,
        'target_table' => $targetTable,
        'key_column' => $keyCol,
        'columns' => $cols,
        'counts' => [
            'source' => count($sourceRows),
            'target' => count($targetRows),
            'insert' => count($insert),
            'update' => count($update),
            'total_changes' => count($insert) + count($update),
        ],
        'sample' => [
            'insert' => array_slice($insert, 0, $sampleLimit),
            'update' => array_slice($update, 0, $sampleLimit),
        ],
    ];
}

function zurie_isims_sync_execute(int $limit = 5000): array
{
    $plan = zurie_isims_sync_plan(10);
    if (empty($plan['ok'])) {
        return $plan;
    }
    $config = zurie_isims_sync_config();
    $local = zurie_isims_sync_local_pdo();
    $remote = zurie_isims_sync_remote_pdo($config);
    $sourceTable = $config['source_table'];
    $targetTable = $config['target_table'];
    $keyCol = (string)$plan['key_column'];
    $cols = (array)$plan['columns'];
    $sourceRows = zurie_isims_sync_fetch_rows($local, $sourceTable, $keyCol, $cols, $limit);
    $targetRows = zurie_isims_sync_fetch_rows($remote, $targetTable, $keyCol, $cols, $limit);

    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $tableSql = zurie_isims_sync_quote_identifier($targetTable);
    $columnSql = implode(',', array_map('zurie_isims_sync_quote_identifier', $cols));
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $updateParts = [];
    foreach ($cols as $col) {
        if ($col === $keyCol) continue;
        $q = zurie_isims_sync_quote_identifier($col);
        $updateParts[] = "{$q}=VALUES({$q})";
    }
    $sql = "INSERT INTO {$tableSql} ({$columnSql}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE " . implode(',', $updateParts);
    $stmt = $remote->prepare($sql);

    $remote->beginTransaction();
    try {
        foreach ($sourceRows as $key => $row) {
            $isInsert = !isset($targetRows[$key]);
            $changed = $isInsert || zurie_isims_sync_row_hash($row, $cols) !== zurie_isims_sync_row_hash($targetRows[$key], $cols);
            if (!$changed) {
                $skipped++;
                continue;
            }
            $values = [];
            foreach ($cols as $col) {
                $values[] = $row[$col] ?? null;
            }
            try {
                $stmt->execute($values);
                if ($isInsert) $inserted++; else $updated++;
            } catch (Throwable $e) {
                $errors[] = ['key' => $key, 'error' => $e->getMessage()];
                if (count($errors) >= 20) {
                    break;
                }
            }
        }
        if ($errors) {
            $remote->rollBack();
        } else {
            $remote->commit();
        }
    } catch (Throwable $e) {
        if ($remote->inTransaction()) $remote->rollBack();
        throw $e;
    }

    $result = [
        'ok' => !$errors,
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => $errors,
        'total_processed' => $inserted + $updated + $skipped,
        'time' => date('c'),
        'username' => (string)($_SESSION['portal_username'] ?? ''),
        'ip' => zurie_security_client_ip(),
    ];
    zurie_isims_sync_write_log($result);
    return $result;
}

function zurie_isims_sync_write_log(array $row): void
{
    $file = dirname(__DIR__) . '/data/isims_sync_log.jsonl';
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function zurie_isims_sync_recent_logs(int $limit = 8): array
{
    $file = dirname(__DIR__) . '/data/isims_sync_log.jsonl';
    if (!is_file($file)) return [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -max(1, $limit));
    $rows = [];
    foreach (array_reverse($lines) as $line) {
        $json = json_decode($line, true);
        if (is_array($json)) $rows[] = $json;
    }
    return $rows;
}

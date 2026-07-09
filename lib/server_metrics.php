<?php
declare(strict_types=1);

function sm_config(): array
{
    static $config;
    if (is_array($config)) return $config;
    $file = dirname(__DIR__) . '/config/server_metrics_config.php';
    if (!is_file($file)) throw new RuntimeException('config/server_metrics_config.php tidak ditemui.');
    $config = require $file;
    if (!is_array($config) || empty($config['dsn'])) throw new RuntimeException('Konfigurasi Server Metrics tidak lengkap.');
    date_default_timezone_set((string)($config['timezone'] ?? 'Asia/Kuala_Lumpur'));
    return $config;
}

function sm_pdo(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $c = sm_config();
    $pdo = new PDO((string)$c['dsn'], (string)($c['username'] ?? ''), (string)($c['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function sm_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS server_metric_agents (
        device_id VARCHAR(80) NOT NULL PRIMARY KEY,
        token_hash CHAR(64) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        note VARCHAR(255) DEFAULT NULL,
        last_seen_at DATETIME DEFAULT NULL,
        last_ip VARCHAR(64) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS server_metrics_current (
        device_id VARCHAR(80) NOT NULL PRIMARY KEY,
        hostname VARCHAR(190) DEFAULT NULL,
        os_name VARCHAR(255) DEFAULT NULL,
        agent_version VARCHAR(40) DEFAULT NULL,
        cpu_percent DECIMAL(6,2) DEFAULT NULL,
        memory_total_mb BIGINT UNSIGNED DEFAULT NULL,
        memory_used_mb BIGINT UNSIGNED DEFAULT NULL,
        memory_free_mb BIGINT UNSIGNED DEFAULT NULL,
        memory_percent DECIMAL(6,2) DEFAULT NULL,
        disk_max_percent DECIMAL(6,2) DEFAULT NULL,
        disks_json MEDIUMTEXT DEFAULT NULL,
        load_json TEXT DEFAULT NULL,
        services_json MEDIUMTEXT DEFAULT NULL,
        uptime_seconds BIGINT UNSIGNED DEFAULT NULL,
        collected_at DATETIME DEFAULT NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_server_metrics_received (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS server_metrics_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(80) NOT NULL,
        cpu_percent DECIMAL(6,2) DEFAULT NULL,
        memory_percent DECIMAL(6,2) DEFAULT NULL,
        disk_max_percent DECIMAL(6,2) DEFAULT NULL,
        collected_at DATETIME DEFAULT NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_metric_history_device_time (device_id, received_at),
        KEY idx_metric_history_received (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function sm_load_devices(): array
{
    $file = dirname(__DIR__) . '/data/noc_devices.json';
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data)) return [];
    $out = [];
    foreach ($data as $d) {
        if (($d['type'] ?? '') !== 'Server' || empty($d['id'])) continue;
        $out[(string)$d['id']] = $d;
    }
    return $out;
}

function sm_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sm_bearer_token(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) return trim($m[1]);
    return trim((string)($_SERVER['HTTP_X_NOC_TOKEN'] ?? ''));
}

function sm_number($value, ?float $min = null, ?float $max = null): ?float
{
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    $n = (float)$value;
    if ($min !== null) $n = max($min, $n);
    if ($max !== null) $n = min($max, $n);
    return round($n, 2);
}

function sm_int($value, int $min = 0): ?int
{
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    return max($min, (int)round((float)$value));
}

function sm_parse_datetime($value): string
{
    try {
        if (is_string($value) && trim($value) !== '') {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        }
    } catch (Throwable $ignored) {}
    return date('Y-m-d H:i:s');
}

function sm_state(?array $metric): array
{
    if (!$metric) return ['code' => 'NOT_INSTALLED', 'label' => 'Belum dipasang', 'class' => 'neutral'];
    $c = sm_config();
    $received = strtotime((string)($metric['received_at'] ?? '')) ?: 0;
    $age = max(0, time() - $received);
    if ($age > (int)($c['stale_seconds'] ?? 180)) {
        return ['code' => 'STALE', 'label' => 'Data stale', 'class' => 'stale', 'age' => $age];
    }
    $cpu = (float)($metric['cpu_percent'] ?? 0);
    $mem = (float)($metric['memory_percent'] ?? 0);
    $disk = (float)($metric['disk_max_percent'] ?? 0);
    $crit = $c['critical'] ?? [];
    $warn = $c['warning'] ?? [];
    if ($cpu >= (float)($crit['cpu'] ?? 90) || $mem >= (float)($crit['memory'] ?? 92) || $disk >= (float)($crit['disk'] ?? 90)) {
        return ['code' => 'CRITICAL', 'label' => 'Kritikal', 'class' => 'critical', 'age' => $age];
    }
    if ($cpu >= (float)($warn['cpu'] ?? 75) || $mem >= (float)($warn['memory'] ?? 80) || $disk >= (float)($warn['disk'] ?? 80)) {
        return ['code' => 'WARNING', 'label' => 'Amaran', 'class' => 'warning', 'age' => $age];
    }
    return ['code' => 'HEALTHY', 'label' => 'Sihat', 'class' => 'healthy', 'age' => $age];
}

function sm_decode_json(?string $json): array
{
    if (!$json) return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

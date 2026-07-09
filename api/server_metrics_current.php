<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/security.php';
zurie_security_protect_api();
require_once dirname(__DIR__) . '/lib/server_metrics.php';

function sm_api_is_guest(): bool { return function_exists('zurie_is_guest') && zurie_is_guest(); }
function sm_api_mask_ip(string $ip): string {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        if (count($parts) === 4) return $parts[0] . '.' . $parts[1] . '.x.x';
    }
    return $ip !== '' ? '[hidden]' : '';
}

try {
    $pdo = sm_pdo();
    sm_ensure_schema($pdo);
    $devices = sm_load_devices();
    $deviceId = trim((string)($_GET['device_id'] ?? ''));

    $rows = [];
    foreach ($pdo->query('SELECT * FROM server_metrics_current') as $row) $rows[(string)$row['device_id']] = $row;
    $agents = [];
    foreach ($pdo->query('SELECT device_id, enabled, last_seen_at, last_ip FROM server_metric_agents') as $row) $agents[(string)$row['device_id']] = $row;

    $servers = [];
    $guest = sm_api_is_guest();
    foreach ($devices as $id => $device) {
        $metric = $rows[$id] ?? null;
        $state = sm_state($metric);
        $servers[] = [
            'device_id' => $id,
            'name' => (string)($device['name'] ?? $id),
            'ip' => $guest ? sm_api_mask_ip((string)($device['ip'] ?? '')) : (string)($device['ip'] ?? ''),
            'model' => $guest ? '' : (string)($device['model'] ?? ''),
            'url' => (string)($device['url'] ?? ''),
            'agent' => $guest ? null : ($agents[$id] ?? null),
            'state' => $state,
            'metrics' => $metric ? [
                'hostname' => $guest ? 'hidden' : $metric['hostname'],
                'os_name' => $guest ? 'hidden' : $metric['os_name'],
                'agent_version' => $guest ? '' : $metric['agent_version'],
                'cpu_percent' => $metric['cpu_percent'] !== null ? (float)$metric['cpu_percent'] : null,
                'memory_total_mb' => $metric['memory_total_mb'] !== null ? (int)$metric['memory_total_mb'] : null,
                'memory_used_mb' => $metric['memory_used_mb'] !== null ? (int)$metric['memory_used_mb'] : null,
                'memory_free_mb' => $metric['memory_free_mb'] !== null ? (int)$metric['memory_free_mb'] : null,
                'memory_percent' => $metric['memory_percent'] !== null ? (float)$metric['memory_percent'] : null,
                'disk_max_percent' => $metric['disk_max_percent'] !== null ? (float)$metric['disk_max_percent'] : null,
                'disks' => $guest ? [] : sm_decode_json($metric['disks_json']),
                'load' => $guest ? [] : sm_decode_json($metric['load_json']),
                'services' => $guest ? [] : sm_decode_json($metric['services_json']),
                'uptime_seconds' => $metric['uptime_seconds'] !== null ? (int)$metric['uptime_seconds'] : null,
                'collected_at' => $metric['collected_at'],
                'received_at' => $metric['received_at'],
            ] : null,
        ];
    }

    $history = [];
    if ($deviceId !== '' && isset($devices[$deviceId])) {
        $stmt = $pdo->prepare('SELECT cpu_percent, memory_percent, disk_max_percent, received_at FROM server_metrics_history WHERE device_id=? AND received_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY received_at ASC LIMIT 1440');
        $stmt->execute([$deviceId]);
        foreach ($stmt->fetchAll() as $h) {
            $history[] = [
                'cpu' => $h['cpu_percent'] !== null ? (float)$h['cpu_percent'] : null,
                'memory' => $h['memory_percent'] !== null ? (float)$h['memory_percent'] : null,
                'disk' => $h['disk_max_percent'] !== null ? (float)$h['disk_max_percent'] : null,
                'time' => $h['received_at'],
            ];
        }
    }

    sm_json_response(['ok' => true, 'server_time' => date(DATE_ATOM), 'servers' => $servers, 'history' => $history]);
} catch (Throwable $e) {
    error_log('[SERVER METRICS CURRENT] ' . $e->getMessage());
    sm_json_response(['ok' => false, 'error' => 'Gagal membaca server metrics.'], 500);
}

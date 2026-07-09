<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/security.php';
zurie_security_protect_api();
require_once dirname(__DIR__) . '/lib/server_metrics.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sm_json_response(['ok' => false, 'error' => 'POST sahaja.'], 405);
$c = sm_config();
if (!empty($c['require_https_push']) && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
    sm_json_response(['ok' => false, 'error' => 'HTTPS diperlukan.'], 403);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 262144) sm_json_response(['ok' => false, 'error' => 'Payload terlalu besar.'], 413);

try {
    $pdo = sm_pdo();
    sm_ensure_schema($pdo);
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Payload JSON tidak sah.');

    $deviceId = trim((string)($data['device_id'] ?? ''));
    $token = sm_bearer_token();
    if ($deviceId === '' || $token === '') sm_json_response(['ok' => false, 'error' => 'device_id atau token tiada.'], 401);

    $devices = sm_load_devices();
    if (!isset($devices[$deviceId])) sm_json_response(['ok' => false, 'error' => 'Device ID bukan Server yang sah.'], 403);

    $stmt = $pdo->prepare('SELECT token_hash, enabled FROM server_metric_agents WHERE device_id = ? LIMIT 1');
    $stmt->execute([$deviceId]);
    $agent = $stmt->fetch();
    if (!$agent || !(int)$agent['enabled'] || !hash_equals((string)$agent['token_hash'], hash('sha256', $token))) {
        sm_json_response(['ok' => false, 'error' => 'Token agent tidak sah.'], 401);
    }

    $memory = is_array($data['memory'] ?? null) ? $data['memory'] : [];
    $disks = is_array($data['disks'] ?? null) ? array_slice($data['disks'], 0, 32) : [];
    $services = is_array($data['services'] ?? null) ? array_slice($data['services'], 0, 64) : [];
    $load = is_array($data['load'] ?? null) ? $data['load'] : [];

    $cleanDisks = [];
    $diskMax = 0.0;
    foreach ($disks as $disk) {
        if (!is_array($disk)) continue;
        $percent = sm_number($disk['percent'] ?? null, 0, 100);
        if ($percent !== null) $diskMax = max($diskMax, $percent);
        $cleanDisks[] = [
            'name' => substr(trim((string)($disk['name'] ?? $disk['mount'] ?? 'Disk')), 0, 100),
            'mount' => substr(trim((string)($disk['mount'] ?? '')), 0, 150),
            'filesystem' => substr(trim((string)($disk['filesystem'] ?? '')), 0, 80),
            'total_gb' => sm_number($disk['total_gb'] ?? null, 0),
            'used_gb' => sm_number($disk['used_gb'] ?? null, 0),
            'free_gb' => sm_number($disk['free_gb'] ?? null, 0),
            'percent' => $percent,
        ];
    }

    $cleanServices = [];
    foreach ($services as $svc) {
        if (!is_array($svc)) continue;
        $cleanServices[] = [
            'name' => substr(trim((string)($svc['name'] ?? 'Service')), 0, 120),
            'status' => strtoupper(substr(trim((string)($svc['status'] ?? 'UNKNOWN')), 0, 30)),
        ];
    }

    $cpu = sm_number($data['cpu_percent'] ?? null, 0, 100);
    $memPct = sm_number($memory['percent'] ?? null, 0, 100);
    $collected = sm_parse_datetime($data['collected_at'] ?? null);
    $now = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    $upsert = $pdo->prepare("INSERT INTO server_metrics_current
        (device_id, hostname, os_name, agent_version, cpu_percent, memory_total_mb, memory_used_mb, memory_free_mb, memory_percent, disk_max_percent, disks_json, load_json, services_json, uptime_seconds, collected_at, received_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE hostname=VALUES(hostname), os_name=VALUES(os_name), agent_version=VALUES(agent_version), cpu_percent=VALUES(cpu_percent), memory_total_mb=VALUES(memory_total_mb), memory_used_mb=VALUES(memory_used_mb), memory_free_mb=VALUES(memory_free_mb), memory_percent=VALUES(memory_percent), disk_max_percent=VALUES(disk_max_percent), disks_json=VALUES(disks_json), load_json=VALUES(load_json), services_json=VALUES(services_json), uptime_seconds=VALUES(uptime_seconds), collected_at=VALUES(collected_at), received_at=VALUES(received_at)");
    $upsert->execute([
        $deviceId,
        substr(trim((string)($data['hostname'] ?? '')), 0, 190),
        substr(trim((string)($data['os_name'] ?? '')), 0, 255),
        substr(trim((string)($data['agent_version'] ?? '1.0')), 0, 40),
        $cpu,
        sm_int($memory['total_mb'] ?? null),
        sm_int($memory['used_mb'] ?? null),
        sm_int($memory['free_mb'] ?? null),
        $memPct,
        $diskMax,
        json_encode($cleanDisks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($load, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($cleanServices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        sm_int($data['uptime_seconds'] ?? null),
        $collected,
        $now,
    ]);
    $hist = $pdo->prepare('INSERT INTO server_metrics_history (device_id, cpu_percent, memory_percent, disk_max_percent, collected_at, received_at) VALUES (?, ?, ?, ?, ?, ?)');
    $hist->execute([$deviceId, $cpu, $memPct, $diskMax, $collected, $now]);
    $agentUpdate = $pdo->prepare('UPDATE server_metric_agents SET last_seen_at=?, last_ip=? WHERE device_id=?');
    $agentUpdate->execute([$now, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), $deviceId]);
    $pdo->commit();

    if (random_int(1, 100) === 1) {
        $days = max(1, (int)($c['history_retention_days'] ?? 7));
        $pdo->exec('DELETE FROM server_metrics_history WHERE received_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)');
    }

    sm_json_response(['ok' => true, 'device_id' => $deviceId, 'received_at' => $now]);
} catch (JsonException $e) {
    sm_json_response(['ok' => false, 'error' => 'JSON tidak sah.'], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[SERVER METRICS PUSH] ' . $e->getMessage());
    sm_json_response(['ok' => false, 'error' => 'Server gagal menerima metrics. Semak PHP error log.'], 500);
}

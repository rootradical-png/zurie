<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

ignore_user_abort(true);
@set_time_limit(25);

$devicesFile = __DIR__ . '/../data/noc_devices.json';
$favoritesFile = __DIR__ . '/../data/live_ping_favorites.json';
$historyFile = __DIR__ . '/../data/live_ping_history.json';
$recentFile = __DIR__ . '/../data/live_ping_recent.json';
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$downOnly = $statusFilter === 'down';

function lp_json_file($file, $default) {
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return $default;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function lp_write_json($file, $payload) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function lp_device_id($device) {
    $raw = strtolower(trim(($device['type'] ?? '') . '|' . ($device['name'] ?? '') . '|' . ($device['ip'] ?? '')));
    return substr(sha1($raw), 0, 16);
}

function lp_safe_host($host) {
    $host = trim((string)$host);
    if (substr_count($host, ':') === 1) {
        $parts = explode(':', $host, 2);
        if (ctype_digit($parts[1])) $host = $parts[0];
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) return $host;
    if (preg_match('/^[a-zA-Z0-9.-]+$/', $host)) return $host;
    return '';
}

function lp_monitoring_paused($device) {
    return strtolower(trim((string)($device['monitoring_status'] ?? 'active'))) === 'paused';
}

function lp_mask_ip($ip) {
    $ip = trim((string)$ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        if (count($parts) === 4) return $parts[0] . '.' . $parts[1] . '.x.x';
    }
    return $ip !== '' ? '[hidden]' : '';
}
function lp_guest_redact_enabled() {
    return function_exists('zurie_is_guest') && zurie_is_guest();
}

function lp_device_url($device) {
    $url = trim((string)($device['url'] ?? ''));
    if ($url !== '' && preg_match('#^https?://#i', $url)) return $url;

    $ip = trim((string)($device['ip'] ?? ''));
    if ($ip === '') return '';
    $scheme = 'http';
    if (preg_match('/:(443|8443|9443)$/', $ip)) $scheme = 'https';
    return $scheme . '://' . $ip;
}

function lp_parse_ping_output($text, $exitCode, $packetCount) {
    $latencies = [];
    $lines = preg_split('/\R/', (string)$text);

    foreach ($lines as $line) {
        // Windows reply: Reply from ... time=2ms TTL=128 / time<1ms TTL=128.
        // TTL kekal sama walaupun bahasa Windows berbeza.
        if (stripos($line, 'TTL') !== false && preg_match('/([=<])\s*([0-9]+(?:[\.,][0-9]+)?)\s*ms/i', $line, $m)) {
            $value = (float)str_replace(',', '.', $m[2]);
            if ($m[1] === '<' && $value <= 1) $value = 0.5;
            $latencies[] = $value;
            continue;
        }

        // Linux/macOS reply.
        if (preg_match('/time\s*([=<])\s*([0-9]+(?:[\.,][0-9]+)?)\s*ms/i', $line, $m)) {
            $value = (float)str_replace(',', '.', $m[2]);
            if ($m[1] === '<' && $value <= 1) $value = 0.5;
            $latencies[] = $value;
        }
    }

    $received = count($latencies);
    $sent = max(1, (int)$packetCount);
    $loss = round((($sent - min($received, $sent)) / $sent) * 100, 1);

    if ($received < 1) {
        return [
            'status' => 'DOWN',
            'latency_ms' => null,
            'packet_loss_pct' => 100,
            'sent' => $sent,
            'received' => 0,
            'exit_code' => (int)$exitCode,
        ];
    }

    return [
        'status' => 'UP',
        'latency_ms' => round(array_sum($latencies) / $received, 1),
        'packet_loss_pct' => $loss,
        'sent' => $sent,
        'received' => $received,
        'exit_code' => (int)$exitCode,
    ];
}

function lp_function_enabled($name) {
    if (!function_exists($name)) return false;
    $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
    return !in_array($name, $disabled, true);
}

function lp_ping_binary($isWindows) {
    if (!$isWindows) return 'ping';
    $root = getenv('SystemRoot') ?: 'C:\\Windows';
    $full = rtrim($root, '\\/') . '\\System32\\PING.EXE';
    if (is_file($full)) return '"' . $full . '"';
    return 'ping.exe';
}

function lp_ping_batch($devices, $packetCount = 4) {
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $binary = lp_ping_binary($isWindows);
    $results = [];
    $jobs = [];
    $canProc = lp_function_enabled('proc_open');
    $canExec = lp_function_enabled('exec');

    if (!$canProc && !$canExec) {
        foreach ($devices as $device) {
            $results[$device['id']] = [
                'status'=>'UNKNOWN','latency_ms'=>null,'packet_loss_pct'=>null,
                'sent'=>0,'received'=>0,
                'error'=>'PHP tidak dibenarkan menjalankan proc_open atau exec.'
            ];
        }
        return [$results, ['mode'=>'disabled','binary'=>$binary,'error'=>'proc_open dan exec tidak tersedia']];
    }

    // Fallback paling serasi jika proc_open tiada.
    if (!$canProc) {
        foreach ($devices as $device) {
            $host = lp_safe_host($device['ip'] ?? '');
            if ($host === '') {
                $results[$device['id']] = ['status'=>'UNKNOWN','latency_ms'=>null,'packet_loss_pct'=>null,'sent'=>0,'received'=>0,'error'=>'IP tidak sah'];
                continue;
            }
            $cmd = $isWindows
                ? $binary . ' -n ' . (int)$packetCount . ' -w 1000 ' . $host . ' 2>&1'
                : $binary . ' -c ' . (int)$packetCount . ' -W 1 ' . $host . ' 2>&1';
            $output = [];
            $exitCode = 1;
            @exec($cmd, $output, $exitCode);
            $text = implode("\n", $output);
            $parsed = lp_parse_ping_output($text, $exitCode, $packetCount);
            if (trim($text) === '') $parsed['error'] = 'Arahan ping tidak menghasilkan output.';
            $results[$device['id']] = $parsed;
        }
        return [$results, ['mode'=>'exec','binary'=>$binary,'error'=>null]];
    }

    // Mulakan semua ping serentak supaya device DOWN tidak melambatkan satu demi satu.
    foreach ($devices as $device) {
        $host = lp_safe_host($device['ip'] ?? '');
        if ($host === '') {
            $results[$device['id']] = ['status'=>'UNKNOWN','latency_ms'=>null,'packet_loss_pct'=>null,'sent'=>0,'received'=>0,'error'=>'IP tidak sah'];
            continue;
        }
        $cmd = $isWindows
            ? $binary . ' -n ' . (int)$packetCount . ' -w 1000 ' . $host
            : $binary . ' -c ' . (int)$packetCount . ' -W 1 ' . $host;
        $descriptors = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $pipes = [];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            $results[$device['id']] = ['status'=>'UNKNOWN','latency_ms'=>null,'packet_loss_pct'=>null,'sent'=>0,'received'=>0,'error'=>'proc_open gagal memulakan PING.EXE'];
            continue;
        }
        @fclose($pipes[0]);
        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);
        $jobs[$device['id']] = ['process'=>$process,'pipes'=>$pipes,'stdout'=>'','stderr'=>'','packet_count'=>$packetCount];
    }

    $deadline = microtime(true) + max(8, $packetCount * 2);
    while ($jobs && microtime(true) < $deadline) {
        foreach (array_keys($jobs) as $id) {
            $job =& $jobs[$id];
            $job['stdout'] .= (string)@stream_get_contents($job['pipes'][1]);
            $job['stderr'] .= (string)@stream_get_contents($job['pipes'][2]);
            $status = @proc_get_status($job['process']);
            if (!$status || empty($status['running'])) {
                @fclose($job['pipes'][1]);
                @fclose($job['pipes'][2]);
                $exitCode = is_array($status) && isset($status['exitcode']) ? (int)$status['exitcode'] : -1;
                $closeCode = @proc_close($job['process']);
                if ($exitCode < 0 && is_int($closeCode)) $exitCode = $closeCode;
                $text = $job['stdout'] . "\n" . $job['stderr'];
                $parsed = lp_parse_ping_output($text, $exitCode, $job['packet_count']);
                if (trim($text) === '') $parsed['error'] = 'PING.EXE tidak menghasilkan output.';
                $results[$id] = $parsed;
                unset($jobs[$id]);
                unset($job);
            }
        }
        usleep(50000);
    }

    foreach ($jobs as $id => $job) {
        @proc_terminate($job['process']);
        @fclose($job['pipes'][1]);
        @fclose($job['pipes'][2]);
        @proc_close($job['process']);
        $results[$id] = [
            'status'=>'DOWN','latency_ms'=>null,'packet_loss_pct'=>100,
            'sent'=>$packetCount,'received'=>0,
            'error'=>'Proses ping melebihi timeout server.'
        ];
    }

    return [$results, ['mode'=>'proc_open','binary'=>$binary,'error'=>null]];
}

$devices = lp_json_file($devicesFile, []);
$deviceMap = [];
foreach ($devices as $device) {
    if (!is_array($device)) continue;
    $inventoryId = trim((string)($device['id'] ?? ''));
    $id = lp_device_id($device);
    $device['inventory_id'] = $inventoryId;
    $device['id'] = $id;
    $deviceMap[$id] = $device;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'mark_opened') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Tindakan tidak sah.']);
        exit;
    }

    $csrf = (string)($_POST['csrf'] ?? '');
    if (empty($_SESSION['lp_csrf']) || !hash_equals((string)$_SESSION['lp_csrf'], $csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Sesi tidak sah.']);
        exit;
    }

    $deviceId = (string)($_POST['device_id'] ?? '');
    if (!isset($deviceMap[$deviceId])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Device tidak ditemui.']);
        exit;
    }

    $recent = lp_json_file($recentFile, ['devices' => []]);
    if (!isset($recent['devices']) || !is_array($recent['devices'])) $recent['devices'] = [];
    $recent['devices'][$deviceId] = time();

    // Simpan maksimum 100 rekod paling baru supaya fail kekal kecil.
    arsort($recent['devices']);
    $recent['devices'] = array_slice($recent['devices'], 0, 100, true);
    $recent['updated_at'] = date('Y-m-d H:i:s');

    if (!lp_write_json($recentFile, $recent)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Tidak dapat simpan recent device. Pastikan folder data boleh ditulis.']);
        exit;
    }

    echo json_encode(['ok' => true, 'device_id' => $deviceId, 'last_opened' => $recent['devices'][$deviceId]]);
    exit;
}

$favorites = lp_json_file($favoritesFile, ['device_ids' => []]);
$maxDevices = isset($_GET['limit']) ? max(1, min(6, (int)$_GET['limit'])) : 6;
$favoriteIds = array_slice(array_values(array_unique(array_map('strval', $favorites['device_ids'] ?? []))), 0, $maxDevices);
$recentPayload = lp_json_file($recentFile, ['devices' => []]);
$recentMap = is_array($recentPayload['devices'] ?? null) ? $recentPayload['devices'] : [];

if ($downOnly) {
    $maxDevices = 500;
}

$selected = [];
$favoriteOrder = [];
foreach ($favoriteIds as $index => $id) {
    $favoriteOrder[$id] = $index;
    if (isset($deviceMap[$id])) $selected[] = $deviceMap[$id];
}

if ($downOnly) {
    $selected = array_values($deviceMap);
    $favoriteOrder = [];
}

if (!$selected) {
    $defaultNames = ['DistAdmin01', 'i-SIMS', 'MIS', 'Website KMP'];
    foreach ($defaultNames as $name) {
        foreach ($deviceMap as $device) {
            if (($device['name'] ?? '') === $name) {
                $selected[] = $device;
                break;
            }
        }
    }
}
if (!$downOnly) {
    $selected = array_slice($selected, 0, $maxDevices);
}

usort($selected, function($a, $b) use ($recentMap, $favoriteOrder) {
    $aId = (string)($a['id'] ?? '');
    $bId = (string)($b['id'] ?? '');
    $aRecent = isset($recentMap[$aId]) ? (int)$recentMap[$aId] : 0;
    $bRecent = isset($recentMap[$bId]) ? (int)$recentMap[$bId] : 0;
    if ($aRecent !== $bRecent) return $bRecent <=> $aRecent;
    return ($favoriteOrder[$aId] ?? 999) <=> ($favoriteOrder[$bId] ?? 999);
});

$history = lp_json_file($historyFile, []);
$now = time();
$rows = [];
$start = microtime(true);
$packetCount = 4;
$activeSelected = array_values(array_filter($selected, function($device) { return !lp_monitoring_paused($device); }));
[$batchResults, $pingDiagnostic] = lp_ping_batch($activeSelected, $packetCount);

foreach ($selected as $device) {
    if (lp_monitoring_paused($device)) {
        $result = [
            'status' => 'PAUSED',
            'latency_ms' => null,
            'packet_loss_pct' => null,
            'sent' => 0,
            'received' => 0,
            'error' => trim((string)($device['monitoring_note'] ?? '')) !== '' ? trim((string)$device['monitoring_note']) : 'Monitoring dipause oleh admin.'
        ];
    } else {
        $result = $batchResults[$device['id']] ?? ['status'=>'DOWN','latency_ms'=>null,'packet_loss_pct'=>100,'sent'=>$packetCount,'received'=>0];
    }
    $id = $device['id'];
    if (!isset($history[$id]) || !is_array($history[$id])) $history[$id] = [];
    $history[$id][] = [
        'ts' => $now,
        'status' => $result['status'],
        'latency_ms' => $result['latency_ms'],
        'packet_loss_pct' => $result['packet_loss_pct'] ?? null,
    ];
    $history[$id] = array_slice($history[$id], -60);

    $samples = $history[$id];
    $upCount = 0;
    foreach ($samples as $sample) {
        if (($sample['status'] ?? '') === 'UP' || ($sample['status'] ?? '') === 'PAUSED') $upCount++;
    }
    $uptime = count($samples) ? round(($upCount / count($samples)) * 100, 1) : 0;

    $rows[] = [
        'id' => $id,
        'inventory_id' => $device['inventory_id'] ?? '',
        'type' => $device['type'] ?? 'Other',
        'name' => $device['name'] ?? '',
        'ip' => lp_guest_redact_enabled() ? lp_mask_ip($device['ip'] ?? '') : ($device['ip'] ?? ''),
        'url' => lp_device_url($device),
        'status' => $result['status'],
        'latency_ms' => $result['latency_ms'],
        'packet_loss_pct' => $result['packet_loss_pct'] ?? null,
        'sent' => $result['sent'] ?? $packetCount,
        'received' => $result['received'] ?? 0,
        'uptime_pct' => $uptime,
        'history' => $samples,
        'last_opened' => isset($recentMap[$id]) ? (int)$recentMap[$id] : 0,
        'monitoring_status' => $device['monitoring_status'] ?? 'active',
        'monitoring_note' => $device['monitoring_note'] ?? '',
        'error' => $result['error'] ?? null,
    ];
}

$outputRows = $rows;
if ($downOnly) {
    $outputRows = array_values(array_filter($rows, function($row) {
        return strtoupper((string)($row['status'] ?? '')) === 'DOWN';
    }));
}

lp_write_json($historyFile, $history);

echo json_encode([
    'ok' => true,
    'checked_at' => date('Y-m-d H:i:s'),
    'elapsed_ms' => round((microtime(true) - $start) * 1000),
    'interval_ms' => 10000,
    'packet_count' => $packetCount,
    'paused_count' => count($selected) - count($activeSelected),
    'source' => gethostname() ?: ($_SERVER['SERVER_NAME'] ?? 'NOC Server'),
    'diagnostic' => $pingDiagnostic,
    'filter' => $downOnly ? 'down' : 'all',
    'total_checked' => count($rows),
    'devices' => $outputRows,
], JSON_UNESCAPED_SLASHES);

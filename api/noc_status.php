<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$devicesFile = __DIR__ . '/../data/noc_devices.php';
$devices = file_exists($devicesFile) ? include $devicesFile : [];
$typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
if ($typeFilter !== '') {
    $devices = array_values(array_filter($devices, function($d) use ($typeFilter) {
        return isset($d['type']) && $d['type'] === $typeFilter;
    }));
}

function ns_is_guest() { return function_exists('zurie_is_guest') && zurie_is_guest(); }
function ns_mask_ip($ip) {
    $ip = trim((string)$ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        if (count($parts) === 4) return $parts[0] . '.' . $parts[1] . '.x.x';
    }
    return $ip !== '' ? '[hidden]' : '';
}

function safe_host($host) {
    $host = trim((string)$host);
    if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
        $host = explode(':', $host, 2)[0];
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) return $host;
    if (preg_match('/^[a-zA-Z0-9.-]+$/', $host)) return $host;
    return '';
}

function tcp_check_url($url, $host) {
    $parts = parse_url($url);
    $scheme = $parts['scheme'] ?? 'http';
    $target = $parts['host'] ?? $host;
    $port = $parts['port'] ?? (($scheme === 'https') ? 443 : (($scheme === 'ftp') ? 21 : 80));
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($target, (int)$port, $errno, $errstr, 0.35);
    if ($fp) { fclose($fp); return true; }
    return false;
}

function ping_device($host, $url = '') {
    $host = safe_host($host);
    if ($host === '') return false;

    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $cmd = $isWindows
        ? 'ping -n 1 -w 800 ' . escapeshellarg($host) . ' 2>&1'
        : 'ping -c 1 -W 1 ' . escapeshellarg($host) . ' 2>&1';

    $output = [];
    $status = 1;
    @exec($cmd, $output, $status);
    if ($status === 0) return true;

    // Fallback: jika ICMP/ping disekat oleh hosting, cuba TCP port daripada URL.
    if ($url !== '') return tcp_check_url($url, $host);
    return false;
}

$summary = [
    'Switch' => ['total'=>0,'up'=>0,'down'=>0],
    'Server' => ['total'=>0,'up'=>0,'down'=>0],
    'Service' => ['total'=>0,'up'=>0,'down'=>0],
    'AP' => ['total'=>0,'up'=>0,'down'=>0],
];

$rows = [];
$start = microtime(true);
foreach ($devices as $d) {
    $type = $d['type'] ?? 'Other';
    if (!isset($summary[$type])) $summary[$type] = ['total'=>0,'up'=>0,'down'=>0];
    $summary[$type]['total']++;

    $paused = strtolower(trim((string)($d['monitoring_status'] ?? 'active'))) === 'paused';
    $up = $paused ? true : ping_device($d['ip'] ?? '', $d['url'] ?? '');
    $status = $paused ? 'PAUSED' : ($up ? 'UP' : 'DOWN');
    $summary[$type][$status === 'DOWN' ? 'down' : 'up']++;
    $rows[] = [
        'type' => $type,
        'name' => $d['name'] ?? '',
        'model' => ns_is_guest() ? '' : ($d['model'] ?? ''),
        'serial' => ns_is_guest() ? '' : ($d['serial'] ?? ''),
        'ip' => ns_is_guest() ? ns_mask_ip($d['ip'] ?? '') : ($d['ip'] ?? ''),
        'url' => ($d['url'] ?? ('http://' . ($d['ip'] ?? ''))),
        'status' => $status,
        'monitoring_status' => $d['monitoring_status'] ?? 'active',
        'monitoring_note' => $d['monitoring_note'] ?? '',
    ];
}

$downList = array_values(array_filter($rows, function($r) { return $r['status'] === 'DOWN'; }));

echo json_encode([
    'ok' => true,
    'checked_at' => date('Y-m-d H:i:s'),
    'elapsed_ms' => round((microtime(true) - $start) * 1000),
    'summary' => $summary,
    'devices' => $rows,
    'alerts' => array_slice($downList, 0, 8),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

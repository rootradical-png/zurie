#!/usr/local/bin/php
<?php
/*
 * Personal NOC Server Metrics Agent - FreeBSD Legacy
 * Compatible with PHP 5.2.x.
 * Version 1.1.1 adds two-decimal CPU precision using kern.cp_time sampling.
 */

define('AGENT_VERSION', '1.1.1-freebsd-php52-cpu001');

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Asia/Kuala_Lumpur');
}

$configFile = getenv('ZURIE_METRICS_CONFIG');
if (!$configFile) {
    $configFile = '/usr/local/etc/zurie-server-metrics.ini';
}

if (!is_file($configFile)) {
    fwrite(STDERR, "Config tidak ditemui: " . $configFile . "\n");
    exit(1);
}

$config = @parse_ini_file($configFile);
if (!is_array($config)) {
    fwrite(STDERR, "Config tidak dapat dibaca: " . $configFile . "\n");
    exit(1);
}

$required = array('device_id', 'token', 'push_url');
foreach ($required as $key) {
    if (!isset($config[$key]) || trim($config[$key]) === '') {
        fwrite(STDERR, "Config " . $key . " wajib diisi.\n");
        exit(1);
    }
}

if (!function_exists('json_encode')) {
    fwrite(STDERR, "Extension JSON tidak tersedia dalam PHP ini.\n");
    exit(1);
}

function run_cmd($command)
{
    $output = @shell_exec('PATH=/bin:/sbin:/usr/bin:/usr/sbin:/usr/local/bin ' . $command . ' 2>/dev/null');
    if ($output === null || $output === false) {
        return '';
    }
    return trim($output);
}

function numeric_value($value)
{
    $value = trim((string)$value);
    if ($value === '' || !is_numeric($value)) {
        return 0.0;
    }
    return (float)$value;
}

function sysctl_number($name)
{
    return numeric_value(run_cmd('/sbin/sysctl -n ' . escapeshellarg($name)));
}

/*
 * vmstat is much more stable on older FreeBSD releases than a 350 ms
 * kern.cp_time sample. The last three columns are normally us, sy and id.
 */
function cpu_percent_from_vmstat()
{
    $raw = run_cmd('/usr/bin/vmstat 1 2 | /usr/bin/tail -1');
    if ($raw === '') {
        return null;
    }

    $parts = preg_split('/\s+/', trim($raw));
    if (!is_array($parts) || count($parts) < 3) {
        return null;
    }

    $count = count($parts);
    $idle = $parts[$count - 1];
    $system = $parts[$count - 2];
    $user = $parts[$count - 3];

    if (!is_numeric($idle) || !is_numeric($system) || !is_numeric($user)) {
        return null;
    }

    $idle = (float)$idle;
    if ($idle < 0 || $idle > 100) {
        return null;
    }

    $busy = 100.0 - $idle;
    if ($busy < 0) $busy = 0;
    if ($busy > 100) $busy = 100;
    return round($busy, 2);
}

function cpu_percent_from_cptime()
{
    $firstRaw = run_cmd('/sbin/sysctl -n kern.cp_time');
    /* Two-second sample gives a steadier low-load reading on old FreeBSD. */
    sleep(2);
    $secondRaw = run_cmd('/sbin/sysctl -n kern.cp_time');

    $first = preg_split('/\s+/', trim($firstRaw));
    $second = preg_split('/\s+/', trim($secondRaw));

    if (!is_array($first) || !is_array($second) || count($first) < 5 || count($second) < 5) {
        return null;
    }

    $total = 0.0;
    $idleDelta = 0.0;
    $i = 0;
    for ($i = 0; $i < 5; $i++) {
        if (!is_numeric($first[$i]) || !is_numeric($second[$i])) {
            return null;
        }
        $delta = (float)$second[$i] - (float)$first[$i];
        if ($delta < 0) $delta = 0;
        $total += $delta;
        if ($i === 4) $idleDelta = $delta;
    }

    if ($total <= 0) {
        return null;
    }

    $busy = (1.0 - ($idleDelta / $total)) * 100.0;
    if ($busy < 0) $busy = 0;
    if ($busy > 100) $busy = 100;
    return round($busy, 2);
}

function cpu_percent()
{
    /* kern.cp_time keeps fractional precision; vmstat rounds to whole percent. */
    $value = cpu_percent_from_cptime();
    if ($value !== null) {
        return round($value, 2);
    }

    $value = cpu_percent_from_vmstat();
    if ($value !== null) {
        return round($value, 2);
    }

    return null;
}

/*
 * Keep all byte/page arithmetic as float. PHP 5.2 on 32-bit FreeBSD can
 * overflow when RAM exceeds 2 GB if values are cast to int too early.
 */
function memory_info()
{
    $totalBytes = sysctl_number('hw.physmem');
    if ($totalBytes <= 0) {
        $totalBytes = sysctl_number('hw.realmem');
    }

    $pageBytes = sysctl_number('hw.pagesize');
    if ($pageBytes <= 0) {
        $pageBytes = 4096.0;
    }

    $freePages = sysctl_number('vm.stats.vm.v_free_count');
    $inactivePages = sysctl_number('vm.stats.vm.v_inactive_count');
    $cachePages = sysctl_number('vm.stats.vm.v_cache_count');

    $totalMb = $totalBytes > 0 ? ($totalBytes / 1048576.0) : 0.0;
    $availableMb = (($freePages + $inactivePages + $cachePages) * $pageBytes) / 1048576.0;

    if ($availableMb < 0) $availableMb = 0;
    if ($totalMb > 0 && $availableMb > $totalMb) $availableMb = $totalMb;

    $usedMb = $totalMb - $availableMb;
    if ($usedMb < 0) $usedMb = 0;

    $percent = null;
    if ($totalMb > 0) {
        $percent = round(($usedMb / $totalMb) * 100.0, 2);
        if ($percent < 0) $percent = 0;
        if ($percent > 100) $percent = 100;
    }

    return array(
        'total_mb' => $totalMb > 0 ? round($totalMb) : null,
        'used_mb' => $totalMb > 0 ? round($usedMb) : null,
        'free_mb' => $totalMb > 0 ? round($availableMb) : null,
        'percent' => $percent
    );
}

function disk_info()
{
    $result = array();
    $raw = run_cmd('/bin/df -kP');
    if ($raw === '') {
        return $result;
    }

    $lines = preg_split("/\r\n|\n|\r/", $raw);
    if (!is_array($lines) || count($lines) < 2) {
        return $result;
    }

    $index = 0;
    for ($index = 1; $index < count($lines); $index++) {
        $line = trim($lines[$index]);
        if ($line === '') continue;

        $parts = preg_split('/\s+/', $line);
        if (!is_array($parts) || count($parts) < 6) continue;

        $filesystem = $parts[0];
        if (preg_match('#^(devfs|procfs|fdescfs|tmpfs|linprocfs|linsysfs)$#i', $filesystem)) continue;

        $blocks = numeric_value($parts[1]);
        $used = numeric_value($parts[2]);
        $available = numeric_value($parts[3]);
        $percent = numeric_value(str_replace('%', '', $parts[4]));
        $mount = implode(' ', array_slice($parts, 5));

        $result[] = array(
            'name' => $mount,
            'mount' => $mount,
            'filesystem' => $filesystem,
            'total_gb' => round($blocks / 1048576.0, 2),
            'used_gb' => round($used / 1048576.0, 2),
            'free_gb' => round($available / 1048576.0, 2),
            'percent' => $percent
        );
    }

    return $result;
}

function uptime_seconds()
{
    $raw = run_cmd('/sbin/sysctl -n kern.boottime');
    if (preg_match('/sec\s*=\s*([0-9]+)/', $raw, $matches)) {
        $seconds = time() - (float)$matches[1];
        return $seconds > 0 ? round($seconds) : 0;
    }
    return 0;
}

function load_info()
{
    $raw = run_cmd('/sbin/sysctl -n vm.loadavg');
    preg_match_all('/[0-9]+(?:\.[0-9]+)?/', $raw, $matches);
    $values = isset($matches[0]) ? $matches[0] : array();
    return array(
        '1m' => isset($values[0]) ? (float)$values[0] : 0,
        '5m' => isset($values[1]) ? (float)$values[1] : 0,
        '15m' => isset($values[2]) ? (float)$values[2] : 0
    );
}

function service_info($csv)
{
    $result = array();
    $csv = trim((string)$csv);
    if ($csv === '') return $result;

    $names = explode(',', $csv);
    foreach ($names as $name) {
        $name = trim($name);
        if ($name === '') continue;

        $output = array();
        $code = 1;
        @exec('/usr/sbin/service ' . escapeshellarg($name) . ' onestatus >/dev/null 2>&1', $output, $code);
        $result[] = array(
            'name' => $name,
            'status' => ($code === 0 ? 'RUNNING' : 'STOPPED')
        );
    }
    return $result;
}

function http_status_from_headers($headers)
{
    if (!is_array($headers)) return 0;
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/[0-9.]+\s+([0-9]{3})#i', $header, $matches)) {
            return (int)$matches[1];
        }
    }
    return 0;
}

function push_with_curl($url, $token, $json, $timeout)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json)
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return array($status, $response, $error);
}

function push_with_stream($url, $token, $json, $timeout)
{
    $headers = "Authorization: Bearer " . $token . "\r\n";
    $headers .= "Content-Type: application/json\r\n";
    $headers .= "Content-Length: " . strlen($json) . "\r\n";
    $headers .= "Connection: close\r\n";

    $options = array('http' => array(
        'method' => 'POST',
        'header' => $headers,
        'content' => $json,
        'timeout' => $timeout,
        'ignore_errors' => true
    ));

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = isset($http_response_header) ? $http_response_header : array();
    $status = http_status_from_headers($responseHeaders);

    if ($response === false) return array($status, '', 'file_get_contents gagal');
    return array($status, $response, '');
}

$services = isset($config['services']) ? $config['services'] : '';
$timeout = isset($config['timeout_seconds']) ? (int)$config['timeout_seconds'] : 20;
if ($timeout < 5) $timeout = 20;

$payload = array(
    'device_id' => (string)$config['device_id'],
    'hostname' => php_uname('n'),
    'os_name' => php_uname(),
    'agent_version' => AGENT_VERSION,
    'collected_at' => date('c'),
    'cpu_percent' => cpu_percent(),
    'memory' => memory_info(),
    'disks' => disk_info(),
    'uptime_seconds' => uptime_seconds(),
    'load' => load_info(),
    'services' => service_info($services)
);

$json = json_encode($payload);
if ($json === false || $json === null || $json === '') {
    fwrite(STDERR, "Gagal membina JSON payload.\n");
    exit(2);
}

/* Test collector without sending data. */
if (isset($argv[1]) && ($argv[1] === '--show' || $argv[1] === '--test')) {
    echo $json . "\n";
    exit(0);
}

if (function_exists('curl_init')) {
    $pushResult = push_with_curl($config['push_url'], $config['token'], $json, $timeout);
} else {
    $pushResult = push_with_stream($config['push_url'], $config['token'], $json, $timeout);
}

$status = isset($pushResult[0]) ? (int)$pushResult[0] : 0;
$response = isset($pushResult[1]) ? $pushResult[1] : '';
$error = isset($pushResult[2]) ? $pushResult[2] : '';

if ($status < 200 || $status >= 300) {
    fwrite(STDERR, "Push gagal HTTP " . $status . ": " . $error . " " . $response . "\n");
    exit(3);
}

echo "OK " . $response . "\n";
exit(0);
?>

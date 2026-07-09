<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$devices = json_decode((string)@file_get_contents(__DIR__ . '/../data/noc_devices.json'), true);
if (!is_array($devices)) $devices = [];
$requested = trim((string)($_GET['ip'] ?? ''));
$host = $requested;
if (substr_count($host, ':') === 1) {
    $parts = explode(':', $host, 2);
    if (ctype_digit($parts[1])) $host = $parts[0];
}
$allowed = false;
foreach ($devices as $device) {
    $ip = trim((string)($device['ip'] ?? ''));
    if (substr_count($ip, ':') === 1) {
        $parts = explode(':', $ip, 2);
        if (ctype_digit($parts[1])) $ip = $parts[0];
    }
    if ($ip === $host) { $allowed = true; break; }
}
if (!$allowed || !filter_var($host, FILTER_VALIDATE_IP)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>'IP tidak sah atau tiada dalam Device Manager.']);
    exit;
}
$disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
$canExec = function_exists('exec') && !in_array('exec', $disabled, true);
$canProc = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
$root = getenv('SystemRoot') ?: 'C:\\Windows';
$full = rtrim($root, '\\/') . '\\System32\\PING.EXE';
$binary = is_file($full) ? '"'.$full.'"' : 'ping.exe';
$output = [];
$exitCode = null;
$cmd = $binary . ' -n 4 -w 1000 ' . $host . ' 2>&1';
if ($canExec) @exec($cmd, $output, $exitCode);
echo json_encode([
    'ok'=>true,
    'host'=>$host,
    'server'=>gethostname(),
    'php_os'=>PHP_OS,
    'exec_enabled'=>$canExec,
    'proc_open_enabled'=>$canProc,
    'disable_functions'=>ini_get('disable_functions'),
    'ping_binary'=>$binary,
    'command'=>$cmd,
    'exit_code'=>$exitCode,
    'output'=>$output
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

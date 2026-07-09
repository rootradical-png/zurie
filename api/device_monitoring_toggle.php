<?php
// Admin sahaja: pause/aktif monitoring peranti tanpa edit maklumat lain.
header('Content-Type: application/json; charset=utf-8');

if (function_exists('zurie_is_guest') && zurie_is_guest()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Guest read-only.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$baseDir = dirname(__DIR__);
$dataFile = $baseDir . '/data/noc_devices.json';
$id = trim((string)($_POST['id'] ?? ''));
$status = strtolower(trim((string)($_POST['status'] ?? '')));
$note = trim(str_replace(["\r", "\n"], ' ', (string)($_POST['note'] ?? '')));
if ($status !== 'paused' && $status !== 'active') $status = 'active';
if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Device id kosong.']);
    exit;
}

$devices = [];
if (is_file($dataFile)) {
    $decoded = json_decode((string)file_get_contents($dataFile), true);
    if (is_array($decoded)) $devices = $decoded;
}
$found = false;
foreach ($devices as &$device) {
    if ((string)($device['id'] ?? '') === $id) {
        $device['monitoring_status'] = $status;
        $device['monitoring_note'] = $status === 'paused' ? ($note !== '' ? $note : 'Pause oleh admin') : '';
        $found = true;
        break;
    }
}
unset($device);

if (!$found) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Device tidak ditemui.']);
    exit;
}

$ok = @file_put_contents($dataFile, json_encode(array_values($devices), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Gagal simpan. Semak permission data/.']);
    exit;
}

echo json_encode(['ok' => true, 'status' => $status]);

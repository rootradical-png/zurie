<?php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';
$authFile = $config['auth_file'] ?? null;
if (is_string($authFile) && $authFile !== '' && is_file($authFile)) {
    require_once $authFile;
}
date_default_timezone_set('Asia/Kuala_Lumpur');
function read_json_file(string $path, array $fallback = []): array {
    if (!is_file($path)) return $fallback;
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') return $fallback;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $fallback;
}
function json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

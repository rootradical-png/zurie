<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
$data = read_json_file((string)$config['status_file'], ['generated_at' => null, 'devices' => []]);
$generatedAt = $data['generated_at'] ?? null;
$stale = true;
if (is_string($generatedAt) && $generatedAt !== '') {
    $ts = strtotime($generatedAt);
    $stale = $ts === false || (time() - $ts) > (int)$config['stale_after_seconds'];
}
if ($stale) {
    foreach ($data['devices'] as &$item) $item['status'] = 'unknown';
    unset($item);
}
json_response([
    'ok' => true,
    'stale' => $stale,
    'generated_at' => $generatedAt,
    'devices' => $data['devices'] ?? [],
]);

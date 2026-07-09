<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/topology.php';

try {
    $data = topology_load_live($config);
    $sync = $data['_sync'] ?? [];
    unset($data['_sync']);

    json_response([
        'ok' => true,
        'sync' => $sync,
        'data' => $data,
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}

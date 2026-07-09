<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/isims_smart_sync.php';
zurie_security_protect_api();
header('Content-Type: application/json; charset=utf-8');
if (function_exists('zurie_is_guest') && zurie_is_guest()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Guest read-only.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
try {
    zurie_security_require_valid_csrf();
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 5000;
    echo json_encode(zurie_isims_sync_execute($limit), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

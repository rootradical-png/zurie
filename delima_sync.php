<?php
// Compatibility redirect only. Actual protected page: /zurie/pages/delima_sync.php
require_once __DIR__ . '/auth_guard.php';
$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: /zurie/pages/delima_sync.php' . $qs, true, 302);
exit;

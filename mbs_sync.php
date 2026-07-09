<?php
require_once __DIR__ . '/auth_guard.php';
$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: /zurie/pages/mbs_sync.php' . $qs, true, 302);
exit;

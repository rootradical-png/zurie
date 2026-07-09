<?php
// Compatibility redirect only. Do not place export logic at root.
// Actual protected page: /zurie/pages/ilmu_export.php
require_once __DIR__ . '/auth_guard.php';
$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: /zurie/pages/ilmu_export.php' . $qs, true, 302);
exit;

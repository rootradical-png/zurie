<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('ZURIEPORTALSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/zurie/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/zurie/',
            'domain' => $params['domain'] ?: '',
            'secure' => !empty($params['secure']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}

session_destroy();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: /zurie/logged_out.php');
exit;

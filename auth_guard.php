<?php
/**
 * Global authentication gate for /zurie/.
 * Loaded automatically through .htaccess using auto_prepend_file.
 */

$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

// Public entry points. server_metrics_push.php uses its own agent token.
$publicBasenames = [
    'login.php',
    'logout.php',
    'logged_out.php',
    'setup_login.php',
];

$isPublicUpload = str_ends_with($scriptName, '/upload/index.php');

if (in_array(basename($scriptName), $publicBasenames, true)
    || str_ends_with($scriptName, '/api/server_metrics_push.php')
    || $isPublicUpload) {
    return;
}

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

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

$authFile = __DIR__ . '/config/portal_auth.php';
if (!is_file($authFile)) {
    header('Location: /zurie/setup_login.php');
    exit;
}

$config = require $authFile;
if (!is_array($config)) {
    http_response_code(500);
    exit('Konfigurasi login portal tidak sah.');
}

$now = time();
$idleTimeout = max(300, (int)($config['idle_timeout'] ?? $config['idle_timeout_seconds'] ?? 3600));
$absoluteTimeout = max($idleTimeout, (int)($config['absolute_timeout'] ?? $config['absolute_timeout_seconds'] ?? 43200));
$users = isset($config['users']) && is_array($config['users']) ? $config['users'] : [];
if (!$users) {
    $legacyUser = (string)($config['username'] ?? '');
    if ($legacyUser !== '') {
        $users[$legacyUser] = ['role' => 'admin', 'display_name' => (string)($config['display_name'] ?? $legacyUser)];
    }
}
$sessionUser = (string)($_SESSION['portal_username'] ?? '');
$sessionRole = (string)($_SESSION['portal_role'] ?? 'admin');
$expectedUser = array_key_exists($sessionUser, $users) ? $sessionUser : '';
$loginTime = (int)($_SESSION['portal_login_time'] ?? 0);
$lastActivity = (int)($_SESSION['portal_last_activity'] ?? 0);
$currentUaHash = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
$storedUaHash = (string)($_SESSION['portal_user_agent_hash'] ?? '');

$isAuthenticated = !empty($_SESSION['portal_authenticated'])
    && $expectedUser !== ''
    && $sessionUser !== ''
    && hash_equals($expectedUser, $sessionUser)
    && $loginTime > 0
    && $lastActivity > 0
    && ($now - $lastActivity) <= $idleTimeout
    && ($now - $loginTime) <= $absoluteTimeout
    && $storedUaHash !== ''
    && hash_equals($storedUaHash, $currentUaHash);

if (!$isAuthenticated) {
    unset(
        $_SESSION['portal_authenticated'],
        $_SESSION['portal_username'],
        $_SESSION['portal_role'],
        $_SESSION['portal_display_name'],
        $_SESSION['portal_login_time'],
        $_SESSION['portal_last_activity'],
        $_SESSION['portal_user_agent_hash'],
        $_SESSION['zurie_portal_auth']
    );

    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/zurie/');
    $parts = parse_url($requestUri);
    $path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
    if (!str_starts_with($path, '/zurie/')) {
        $requestUri = '/zurie/index.php';
    }
    $_SESSION['portal_return_to'] = $requestUri;

    header('Location: /zurie/login.php');
    exit;
}


$_SESSION['portal_last_activity'] = $now;

if (!function_exists('zurie_portal_role')) {
    function zurie_portal_role(): string {
        return (string)($_SESSION['portal_role'] ?? 'admin');
    }
}
if (!function_exists('zurie_is_guest')) {
    function zurie_is_guest(): bool {
        return zurie_portal_role() === 'guest';
    }
}
if (!function_exists('zurie_client_ip')) {
    function zurie_client_ip(): string {
        $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'];
        foreach ($keys as $key) {
            $value = trim((string)($_SERVER[$key] ?? ''));
            if ($value === '') continue;
            $first = trim(explode(',', $value)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
        return 'unknown';
    }
}
if (!function_exists('zurie_audit_access')) {
    function zurie_audit_access(string $event, string $note = ''): void {
        $file = __DIR__ . '/data/guest_access_log.jsonl';
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $row = [
            'time' => date('c'),
            'event' => $event,
            'username' => (string)($_SESSION['portal_username'] ?? ''),
            'role' => (string)($_SESSION['portal_role'] ?? ''),
            'ip' => zurie_client_ip(),
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
            'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'note' => $note,
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
        ];
        @file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (zurie_is_guest()) {
    zurie_audit_access('guest_access');

    $guestAllowedExact = [
        '/zurie/index.php',
        '/zurie/pages/live_ping.php',
        '/zurie/pages/server_metrics.php',
        '/zurie/pages/server_detail.php',
        '/zurie/pages/down_devices.php',
        '/zurie/map/index.php',
        '/zurie/map/',
    ];
    $guestAllowedApiBasenames = [
        'live_ping.php',
        'noc_status.php',
        'server_metrics_current.php',
    ];

    $pathOnly = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: $scriptName;
    $pathOnly = str_replace('\\', '/', (string)$pathOnly);
    $isAllowed = in_array($pathOnly, $guestAllowedExact, true)
        || str_ends_with($pathOnly, '/zurie/map/')
        || ($pathOnly === '/zurie/' || $pathOnly === '/zurie/index.php')
        || (str_starts_with($pathOnly, '/zurie/api/') && in_array(basename($pathOnly), $guestAllowedApiBasenames, true));

    if (!$isAllowed || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        zurie_audit_access('guest_blocked', 'read_only_or_restricted_page');
        if (str_starts_with($pathOnly, '/zurie/api/')) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Guest read-only. Akses ini disekat.']);
        } else {
            http_response_code(403);
            echo '<!doctype html><meta charset="utf-8"><title>Guest Read-only</title><body style="font-family:Arial;padding:30px;background:#07111f;color:#e5f6ff"><h2>Akses guest read-only</h2><p>Guest hanya boleh melihat monitoring. Menu edit, extract, gambar pelajar, vault dan maklumat sensitif disekat.</p><p><a style="color:#67e8f9" href="/zurie/index.php">Kembali ke Dashboard Monitoring</a></p></body>';
        }
        exit;
    }
}

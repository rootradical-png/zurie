<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('ZURIEPORTALSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/zurie/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$authFile = __DIR__ . '/config/portal_auth.php';
if (!is_file($authFile)) {
    header('Location: /zurie/setup_login.php');
    exit;
}
$config = require $authFile;

function zurie_client_ip_login(): string {
    $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'];
    foreach ($keys as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value === '') continue;
        $first = trim(explode(',', $value)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return 'unknown';
}
function zurie_audit_login(string $event, string $username, string $role = ''): void {
    $file = __DIR__ . '/data/guest_access_log.jsonl';
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $row = [
        'time' => date('c'),
        'event' => $event,
        'username' => $username,
        'role' => $role,
        'ip' => zurie_client_ip_login(),
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
    ];
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// Simpan destinasi asal yang dihantar oleh guard lama/halaman sensitif.
$next = (string)($_GET['next'] ?? '');
if ($next !== '') {
    $parts = parse_url($next);
    $path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
    if (str_starts_with($path, '/zurie/')) {
        $_SESSION['portal_return_to'] = $next;
    }
}

if (!empty($_SESSION['portal_authenticated'])) {
    header('Location: /zurie/index.php');
    exit;
}

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(24));
}

$error = '';
$lockedUntil = (int)($_SESSION['login_locked_until'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $now = time();
    $csrf = (string)($_POST['csrf'] ?? '');
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($lockedUntil > $now) {
        $error = 'Terlalu banyak cubaan. Cuba semula dalam ' . ($lockedUntil - $now) . ' saat.';
    } elseif (!hash_equals($_SESSION['login_csrf'], $csrf)) {
        $error = 'Permintaan tidak sah. Refresh page dan cuba semula.';
    } else {
        $users = [];
        if (isset($config['users']) && is_array($config['users'])) {
            $users = $config['users'];
        } else {
            $users[(string)($config['username'] ?? '')] = [
                'display_name' => (string)($config['display_name'] ?? $config['username'] ?? ''),
                'password_hash' => (string)($config['password_hash'] ?? ''),
                'role' => 'admin',
            ];
        }

        $matchedUser = null;
        $matchedData = null;
        foreach ($users as $userKey => $userData) {
            $userKey = (string)$userKey;
            if ($userKey !== '' && hash_equals($userKey, $username) && is_array($userData)) {
                $matchedUser = $userKey;
                $matchedData = $userData;
                break;
            }
        }

        $validPassword = $matchedData && password_verify($password, (string)($matchedData['password_hash'] ?? ''));

        if ($matchedUser !== null && $validPassword) {
            $role = (string)($matchedData['role'] ?? 'admin');
            if (!in_array($role, ['admin', 'guest'], true)) $role = 'guest';
            session_regenerate_id(true);
            $_SESSION['portal_authenticated'] = true;
            $_SESSION['portal_username'] = $matchedUser;
            $_SESSION['portal_role'] = $role;
            $_SESSION['portal_display_name'] = (string)($matchedData['display_name'] ?? $matchedUser);
            $_SESSION['portal_login_time'] = time();
            $_SESSION['portal_last_activity'] = time();
            $_SESSION['portal_user_agent_hash'] = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
            // Legacy bridge: hanya admin boleh guna halaman eksport lama.
            $_SESSION['zurie_portal_auth'] = [
                'username' => $matchedUser,
                'role' => $role,
                'login_at' => $_SESSION['portal_login_time'],
                'last_activity' => $_SESSION['portal_last_activity'],
                'ua_hash' => $_SESSION['portal_user_agent_hash'],
            ];
            zurie_audit_login('login_success', $matchedUser, $role);
            $_SESSION['login_attempts'] = 0;
            unset($_SESSION['login_locked_until']);

            $returnTo = (string)($_SESSION['portal_return_to'] ?? '/zurie/index.php');
            unset($_SESSION['portal_return_to']);
            if (!str_starts_with($returnTo, '/zurie/')) {
                $returnTo = '/zurie/index.php';
            }
            header('Location: ' . $returnTo);
            exit;
        }

        zurie_audit_login('login_failed', $username, '');
        $attempts = (int)($_SESSION['login_attempts'] ?? 0) + 1;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['login_locked_until'] = time() + 60;
            $_SESSION['login_attempts'] = 0;
            $error = 'Terlalu banyak cubaan. Login dikunci selama 60 saat.';
        } else {
            $error = 'Username atau password tidak betul.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#07111f">
<title>Login | Personal NOC Dashboard</title>
<link rel="icon" href="/zurie/image/zuriex.jpg">
<style>
*{box-sizing:border-box}html,body{min-height:100%;margin:0}body{display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 50% 0,rgba(26,111,174,.26),transparent 42%),linear-gradient(150deg,#06111f,#020a13);font-family:Arial,Helvetica,sans-serif;color:#edf8ff}.card{width:min(420px,100%);padding:28px;border:1px solid rgba(85,217,255,.22);border-radius:20px;background:linear-gradient(155deg,rgba(13,31,49,.98),rgba(6,18,31,.98));box-shadow:0 28px 80px rgba(0,0,0,.45)}.brand{text-align:center;margin-bottom:22px}.brand img{width:78px;height:78px;border-radius:21px;object-fit:cover;border:1px solid rgba(85,217,255,.32);box-shadow:0 0 28px rgba(85,217,255,.12)}h1{margin:14px 0 4px;font-size:23px}.brand p{margin:0;color:#7994aa;font-size:11px}.success,.error{padding:11px 12px;border-radius:10px;font-size:12px;line-height:1.5;margin-bottom:14px}.success{background:rgba(81,227,164,.08);border:1px solid rgba(81,227,164,.22);color:#a9f7d6}.error{background:rgba(255,113,131,.09);border:1px solid rgba(255,113,131,.24);color:#ffabb5}label{display:block;margin:12px 0 6px;color:#91a8bc;font-size:11px;font-weight:700}input{width:100%;height:43px;padding:0 12px;border:1px solid rgba(112,148,188,.22);border-radius:10px;background:#071726;color:#eef8ff;outline:none}input:focus{border-color:rgba(85,217,255,.58);box-shadow:0 0 0 3px rgba(85,217,255,.08)}button{width:100%;height:44px;margin-top:18px;border:1px solid rgba(85,217,255,.35);border-radius:11px;background:linear-gradient(135deg,#1485cc,#0e668f);color:white;font-weight:800;cursor:pointer}small{display:block;margin-top:16px;color:#607a90;font-size:10px;text-align:center}
</style>
</head>
<body>
<main class="card">
<div class="brand"><img src="/zurie/image/zuriex.jpg" alt="Zurie"><h1>Personal NOC Dashboard</h1><p>KMP Operations Center</p></div>
<?php if (isset($_GET['setup'])): ?><div class="success">Login portal berjaya disediakan. Sila log masuk.</div><?php endif; ?>
<?php if (isset($_GET['logout'])): ?><div class="success">Anda telah log keluar.</div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post" autocomplete="on">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
<label>Username</label><input name="username" required autocomplete="username" autofocus>
<label>Password</label><input type="password" name="password" required autocomplete="current-password">
<button type="submit">Log Masuk</button>
</form>
<small>Portal dalaman ICT KMP<br>Akses guest read-only untuk monitoring sahaja. Sila request ID guest daripada <a href="mailto:zurie@kmp.matrik.edu.my"
   style="color:inherit;text-decoration:none;font-weight:400;">
    Pentadbir ICT KMP
</a>.</small>
</main>
</body>
</html>

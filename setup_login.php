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

$authFile = __DIR__ . '/config/portal_auth.php';
if (is_file($authFile)) {
    header('Location: /zurie/login.php');
    exit;
}

function is_private_ip(string $ip): bool
{
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
if (!is_private_ip($clientIp)) {
    http_response_code(403);
    exit('Setup login hanya dibenarkan daripada rangkaian dalaman.');
}

if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(24));
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    $username = trim((string)($_POST['username'] ?? ''));
    $displayName = trim((string)($_POST['display_name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!hash_equals($_SESSION['setup_csrf'], $csrf)) {
        $error = 'Permintaan tidak sah. Refresh page dan cuba semula.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
        $error = 'Username mesti 3–40 aksara dan hanya huruf, nombor, titik, dash atau underscore.';
    } elseif (mb_strlen($password) < 10) {
        $error = 'Password mesti sekurang-kurangnya 10 aksara.';
    } elseif ($password !== $confirm) {
        $error = 'Pengesahan password tidak sama.';
    } else {
        $adminHash = password_hash($password, PASSWORD_DEFAULT);
        $config = [
            'username' => $username,
            'display_name' => $displayName !== '' ? $displayName : $username,
            'password_hash' => $adminHash,
            'idle_timeout' => 3600,
            'absolute_timeout' => 43200,
            'created_at' => date(DATE_ATOM),
            'users' => [
                $username => [
                    'display_name' => $displayName !== '' ? $displayName : $username,
                    'password_hash' => $adminHash,
                    'role' => 'admin',
                ],
                'guest' => [
                    'display_name' => 'Guest Monitoring',
                    'password_hash' => password_hash('guest123', PASSWORD_DEFAULT),
                    'role' => 'guest',
                ],
            ],
        ];

        $php = "<?php\nreturn " . var_export($config, true) . ";\n";
        $temp = $authFile . '.tmp';

        if (file_put_contents($temp, $php, LOCK_EX) === false || !rename($temp, $authFile)) {
            @unlink($temp);
            $error = 'Gagal menyimpan config login. Semak permission folder /zurie/config.';
        } else {
            $_SESSION['setup_csrf'] = bin2hex(random_bytes(24));
            header('Location: /zurie/login.php?setup=success');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup Login | Personal NOC Dashboard</title>
<style>
*{box-sizing:border-box}html,body{min-height:100%;margin:0}body{display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 50% 0,rgba(26,111,174,.26),transparent 42%),linear-gradient(150deg,#06111f,#020a13);font-family:Arial,Helvetica,sans-serif;color:#edf8ff}.card{width:min(470px,100%);padding:28px;border:1px solid rgba(85,217,255,.22);border-radius:20px;background:linear-gradient(155deg,rgba(13,31,49,.98),rgba(6,18,31,.98));box-shadow:0 28px 80px rgba(0,0,0,.45)}.brand{display:flex;align-items:center;gap:13px;margin-bottom:22px}.brand img{width:58px;height:58px;border-radius:16px;object-fit:cover;border:1px solid rgba(85,217,255,.3)}h1{margin:0;font-size:22px}.brand p{margin:4px 0 0;color:#7f9bb3;font-size:12px}.notice,.error{padding:11px 12px;border-radius:10px;font-size:12px;line-height:1.5;margin-bottom:15px}.notice{background:rgba(85,217,255,.08);border:1px solid rgba(85,217,255,.16);color:#aeefff}.error{background:rgba(255,113,131,.09);border:1px solid rgba(255,113,131,.24);color:#ffabb5}label{display:block;margin:12px 0 6px;color:#91a8bc;font-size:11px;font-weight:700}input{width:100%;height:43px;padding:0 12px;border:1px solid rgba(112,148,188,.22);border-radius:10px;background:#071726;color:#eef8ff;outline:none}input:focus{border-color:rgba(85,217,255,.58);box-shadow:0 0 0 3px rgba(85,217,255,.08)}button{width:100%;height:44px;margin-top:18px;border:1px solid rgba(85,217,255,.35);border-radius:11px;background:linear-gradient(135deg,#1485cc,#0e668f);color:white;font-weight:800;cursor:pointer}small{display:block;margin-top:15px;color:#617b91;font-size:10px;text-align:center}
</style>
</head>
<body>
<main class="card">
<div class="brand"><img src="/zurie/image/zuriex.jpg" alt="Zurie"><div><h1>Setup Login Portal</h1><p>Personal NOC Dashboard • First-time setup</p></div></div>
<div class="notice">Tetapkan akaun login sebenar. Selepas setup, semua page di bawah <b>/zurie/</b> akan memerlukan login.</div>
<?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['setup_csrf'], ENT_QUOTES, 'UTF-8') ?>">
<label>Username</label><input name="username" value="zurie" required maxlength="40">
<label>Display Name</label><input name="display_name" value="ZURIE" maxlength="80">
<label>Password</label><input type="password" name="password" required minlength="10" autocomplete="new-password">
<label>Confirm Password</label><input type="password" name="confirm_password" required minlength="10" autocomplete="new-password">
<button type="submit">Simpan & Aktifkan Login</button>
</form>
<small>Akses setup dibenarkan daripada rangkaian dalaman sahaja.<br>Akaun guest read-only turut dibuat, tetapi maklumat ID guest perlu diminta daripada <a href="mailto:zurie@kmp.matrik.edu.my" class="guest-admin-link">Pentadbir ICT KMP</a>.</small>
</main>
</body>
</html>

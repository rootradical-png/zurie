<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/portal_auth.php';
zurie_security_require_extract_network();

$alreadyConfigured = zurie_portal_auth_configured();
if ($alreadyConfigured && !zurie_portal_is_authenticated()) {
    header('Location: /zurie/login.php?next=' . rawurlencode('/zurie/security/setup_portal_login.php'));
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    zurie_security_require_valid_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.@-]{3,40}$/', $username)) {
        $error = 'ID pengguna mesti 3–40 aksara dan hanya mengandungi huruf, nombor, titik, garis, @ atau underscore.';
    } elseif (strlen($password) < 12) {
        $error = 'Kata laluan mesti sekurang-kurangnya 12 aksara.';
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error = 'Kata laluan mesti mempunyai huruf besar, huruf kecil dan nombor.';
    } elseif (!hash_equals($password, $confirm)) {
        $error = 'Pengesahan kata laluan tidak sepadan.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            $error = 'Gagal menghasilkan password hash.';
        } else {
            $config = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'username' => $username,
                'password_hash' => $hash,
                'idle_timeout_seconds' => 1200,
                'absolute_timeout_seconds' => 28800,
                'max_failed_attempts' => 5,
                'lock_seconds' => 900,
            ], true) . ";\n";
            $path = zurie_portal_config_path();
            $tmp = $path . '.tmp';
            if (@file_put_contents($tmp, $config, LOCK_EX) === false || !@rename($tmp, $path)) {
                @unlink($tmp);
                $error = 'Tidak dapat menulis config login. Semak permission folder /zurie/config/.';
            } else {
                @chmod($path, 0600);
                error_log('[ZURIE PORTAL SETUP] username=' . $username . ' ip=' . zurie_security_client_ip());
                header('Location: /zurie/login.php');
                exit;
            }
        }
    }
}

$csrf = zurie_security_csrf_token();
?>
<!doctype html>
<html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup Login Portal</title>
<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:#07111f;color:#eaf4ff;font-family:Segoe UI,Arial,sans-serif}.box{width:min(520px,100%);padding:28px;border:1px solid rgba(110,165,215,.23);border-radius:18px;background:linear-gradient(145deg,#0e2035,#091725);box-shadow:0 25px 80px rgba(0,0,0,.4)}h1{margin:0 0 6px;font-size:22px}p{color:#88a1b8;font-size:13px;line-height:1.55}.tag{display:inline-block;padding:4px 8px;border-radius:999px;background:rgba(75,195,255,.09);color:#8adeff;font-size:10px;font-weight:800}.error{padding:10px 12px;border:1px solid rgba(255,91,111,.2);border-radius:10px;background:rgba(255,91,111,.1);color:#ff9cab;font-size:12px}label{display:block;margin:13px 0 6px;color:#9eb3c6;font-size:12px;font-weight:700}input{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(120,170,215,.22);background:#071421;color:#fff}button{width:100%;margin-top:18px;padding:12px;border:0;border-radius:10px;background:#248ce5;color:#fff;font-weight:800;cursor:pointer}.foot{margin-top:16px;padding-top:13px;border-top:1px solid rgba(120,170,215,.13);font-size:11px;color:#71899f}</style></head>
<body><main class="box"><span class="tag">FASA 2 • MAIN LOGIN</span><h1><?= $alreadyConfigured ? 'Tukar Login Portal' : 'Cipta Login Utama Portal' ?></h1>
<p>Login ini melindungi dashboard utama dan semua page ekstrak daripada akses terus. Kata laluan disimpan sebagai hash sahaja.</p>
<?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post" autocomplete="off"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<label for="username">ID Pengguna Portal</label><input id="username" name="username" maxlength="40" required value="<?= htmlspecialchars((string)($_POST['username'] ?? ($alreadyConfigured ? zurie_portal_auth_config()['username'] : 'zurie')), ENT_QUOTES, 'UTF-8') ?>">
<label for="password">Kata Laluan Baharu</label><input id="password" name="password" type="password" autocomplete="new-password" required>
<label for="confirm_password">Ulang Kata Laluan</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
<button type="submit"><?= $alreadyConfigured ? 'Simpan Kata Laluan Baharu' : 'Aktifkan Login Portal' ?></button></form>
<div class="foot">IP semasa: <?= htmlspecialchars(zurie_security_client_ip(), ENT_QUOTES, 'UTF-8') ?><br>Setup ini hanya boleh dibuka dari rangkaian lokal KMP atau VPN KPM.</div>
</main></body></html>

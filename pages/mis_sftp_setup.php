<?php
/** Zurie Fasa 4 - semakan konfigurasi SFTP MIS. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth_guard.php';
require_once dirname(__DIR__) . '/lib/mis_sftp.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
date_default_timezone_set('Asia/Kuala_Lumpur');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['mis_sftp_csrf'])) $_SESSION['mis_sftp_csrf'] = bin2hex(random_bytes(16));
$token = (string)$_SESSION['mis_sftp_csrf'];

$config = zurie_mis_sftp_config();
$status = zurie_mis_sftp_config_status($config);
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals($token, $sent)) {
        $result = ['ok' => false, 'message' => 'Token keselamatan tidak sah.'];
    } else {
        $result = zurie_mis_sftp_diagnose($config);
    }
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tetapan SFTP MIS | Zurie</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;margin:0;color:#0f172a}.wrap{max-width:850px;margin:30px auto;padding:0 16px}.card{background:#fff;border-radius:18px;padding:22px;box-shadow:0 10px 30px rgba(15,23,42,.08);margin-bottom:16px}.title{margin:0 0 8px;font-size:25px}.muted{color:#64748b}.grid{display:grid;grid-template-columns:190px 1fr;gap:10px}.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:800}.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}.alert{padding:13px;border-radius:12px;margin:12px 0}.btn{display:inline-block;border:0;border-radius:10px;padding:11px 15px;background:#2563eb;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.ghost{background:#e2e8f0;color:#0f172a}.breadcrumb{display:flex;gap:7px;align-items:center;flex-wrap:wrap;font-size:13px;margin-bottom:12px}.breadcrumb a{color:#2563eb;text-decoration:none;font-weight:700}.breadcrumb span{color:#64748b}code{background:#f1f5f9;padding:2px 5px;border-radius:5px}.list{line-height:1.8}@media(max-width:650px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body><div class="wrap">
<div class="card">
<nav class="breadcrumb" aria-label="Breadcrumb">
<a href="/zurie/">Dashboard</a><span>›</span>
<a href="/zurie/pages/photo_audit.php">Audit Gambar MIS</a><span>›</span>
<a href="/zurie/pages/upload_review.php">Semakan Upload</a><span>›</span>
<strong>Tetapan SFTP</strong>
</nav>
<h1 class="title">Tetapan SFTP MIS</h1>
<p class="muted">Halaman ini tidak memaparkan password. Edit konfigurasi terus pada server NOC.</p>
<a class="btn ghost" href="/zurie/pages/upload_review.php">← Semakan Foto</a>
</div>

<?php if ($result): ?>
<div class="alert <?= $result['ok'] ? 'ok' : 'bad' ?>"><?= h((string)$result['message']) ?></div>
<?php endif; ?>

<div class="card">
<h2>Status Konfigurasi</h2>
<p><span class="badge <?= $status['ready'] ? 'ok' : 'bad' ?>"><?= $status['ready'] ? 'SEDIA' : 'BELUM LENGKAP' ?></span></p>
<div class="grid">
<strong>Fail config</strong><span><code>C:\xampp_baru\htdocs\zurie\config\mis_sftp_config.php</code></span>
<strong>Driver</strong><span><?= h((string)$status['driver']) ?></span>
<strong>Host</strong><span><?= h((string)$status['host']) ?>:<?= (int)$status['port'] ?></span>
<strong>Remote folder</strong><span><code><?= h((string)$status['remote_dir']) ?></code></span>
<strong>WinSCP</strong><span><?= is_file((string)($config['winscp_path'] ?? '')) ? 'Ditemui' : 'Tidak ditemui / tidak digunakan' ?></span>
<strong>PHP ssh2</strong><span><?= extension_loaded('ssh2') ? 'Aktif' : 'Tidak aktif / tidak digunakan' ?></span>
</div>
<?php if (!$status['ready']): ?>
<p><strong>Perlu diselesaikan:</strong></p>
<ul class="list"><?php foreach ($status['missing'] as $item): ?><li><?= h((string)$item) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($token) ?>">
<button class="btn" type="submit" <?= !$status['ready'] ? 'disabled' : '' ?>>Uji Sambungan SFTP</button>
</form>
</div>

<div class="card">
<h2>Fail yang akan dihantar</h2>
<p>Sistem mengambil:</p>
<code>upload/files/repaired/MA2614201840.jpg</code>
<p>dan menyimpan di MIS sebagai:</p>
<code><?= h((string)$status['remote_dir']) ?>/MA2614201840.jpg</code>
<p class="muted">Nama fail akhir sentiasa No Matrik + <strong>.jpg</strong>.</p>
</div>
</div></body></html>

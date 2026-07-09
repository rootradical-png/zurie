<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/portal_auth.php';
zurie_portal_require_login();

$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
$xri = (string)($_SERVER['HTTP_X_REAL_IP'] ?? '');
$fwd = (string)($_SERVER['HTTP_FORWARDED'] ?? '');
$detected = zurie_security_client_ip();
$allowed = zurie_security_extract_ip_allowed($detected);

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Semakan IP Pengguna</title>
<style>
body{margin:0;background:#07111f;color:#e9f4ff;font-family:Segoe UI,Arial,sans-serif}.wrap{max-width:760px;margin:7vh auto;padding:24px}.card{background:#0d1c2e;border:1px solid #29425f;border-radius:16px;padding:22px}.row{display:grid;grid-template-columns:220px 1fr;gap:12px;padding:10px 0;border-bottom:1px solid #1b324b}.row:last-child{border:0}.k{color:#86a0b8}.v{word-break:break-all;font-family:Consolas,monospace}.ok{color:#55e3a4}.bad{color:#ff7b8a}a{color:#70cfff}
@media(max-width:620px){.row{grid-template-columns:1fr;gap:4px}}
</style>
</head>
<body><main class="wrap"><section class="card">
<h1>Semakan IP Pengguna</h1>
<p>Halaman ini mengesahkan sama ada reverse proxy menghantar IP asal pengguna.</p>
<div class="row"><div class="k">REMOTE_ADDR</div><div class="v"><?= h($remote) ?></div></div>
<div class="row"><div class="k">X-Forwarded-For</div><div class="v"><?= h($xff !== '' ? $xff : '(tiada)') ?></div></div>
<div class="row"><div class="k">X-Real-IP</div><div class="v"><?= h($xri !== '' ? $xri : '(tiada)') ?></div></div>
<div class="row"><div class="k">Forwarded</div><div class="v"><?= h($fwd !== '' ? $fwd : '(tiada)') ?></div></div>
<div class="row"><div class="k">IP dikesan portal</div><div class="v"><?= h($detected) ?></div></div>
<div class="row"><div class="k">Akses modul extract</div><div class="v <?= $allowed ? 'ok' : 'bad' ?>"><?= $allowed ? 'DIBENARKAN' : 'DISEKAT' ?></div></div>
<p><a href="/zurie/">← Kembali ke dashboard</a></p>
</section></main></body></html>

<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#07111f">
<title>Logout | Personal NOC Dashboard</title>
<link rel="icon" href="/zurie/image/zuriex.jpg">
<style>
*{box-sizing:border-box}html,body{min-height:100%;margin:0}body{display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 50% 0,rgba(19,92,145,.24),transparent 42%),linear-gradient(150deg,#06111f,#020a13);font-family:Arial,Helvetica,sans-serif;color:#eef8ff}.card{width:min(430px,100%);padding:28px;border:1px solid rgba(85,217,255,.2);border-radius:20px;background:linear-gradient(155deg,rgba(13,31,49,.98),rgba(6,18,31,.98));box-shadow:0 28px 80px rgba(0,0,0,.45);text-align:center}.avatar{width:74px;height:74px;margin:0 auto 16px;border-radius:20px;overflow:hidden;border:1px solid rgba(85,217,255,.3)}.avatar img{width:100%;height:100%;object-fit:cover}.check{display:grid;place-items:center;width:42px;height:42px;margin:-36px auto 14px;border-radius:50%;background:#43d99a;color:#06150f;font-weight:900;border:5px solid #0b2033}h1{margin:0;font-size:24px}p{margin:10px 0 22px;color:#8fa8bd;font-size:14px;line-height:1.55}a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;border:1px solid rgba(85,217,255,.3);border-radius:11px;background:rgba(31,133,218,.14);color:#9be7ff;text-decoration:none;font-weight:800;font-size:13px}a:hover{background:rgba(31,133,218,.25)}small{display:block;margin-top:18px;color:#627c92;font-size:11px}
</style>
</head>
<body>
<main class="card">
<div class="avatar"><img src="/zurie/image/zuriex.jpg" alt="Zurie"></div>
<div class="check">✓</div>
<h1>Logout Berjaya</h1>
<p>Session portal dan Credential Vault telah dibersihkan. Portal kini memerlukan login semula.</p>
<a href="/zurie/login.php?logout=success">Log Masuk Semula</a>
<small>Personal NOC Dashboard • KMP Operations Center</small>
</main>
</body>
</html>

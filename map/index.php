<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$title = htmlspecialchars((string)$config['site_name'], ENT_QUOTES, 'UTF-8');
$refresh = (int)$config['browser_refresh_ms'];

$navigation = is_array($config['navigation'] ?? null) ? $config['navigation'] : [];
$mainMenuUrl = htmlspecialchars((string)($navigation['main_menu_url'] ?? '../'), ENT_QUOTES, 'UTF-8');
$dashboardUrl = htmlspecialchars((string)($navigation['dashboard_url'] ?? '../index.php'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $title ?></title>
<link rel="stylesheet" href="assets/map.css?v=2.3">
</head>
<body>
<div class="shell">
<header class="topbar">
  <div class="brand"><div class="logo">⌁</div><div><h1><?= $title ?></h1><p>Campus Network Monitor</p></div></div>
  <div class="metrics">
    <div><small>NETWORK STATUS</small><strong id="overall">LOADING</strong></div>
    <div><small>DEVICES</small><strong><span id="online">0</span> / <span id="total">0</span></strong></div>
    <div><small>ALERTS</small><strong id="alerts">0</strong></div>
    <div><small>LAST UPDATE</small><strong id="updated">—</strong></div>
  </div>
  <div class="actions">
    <a class="nav-button secondary" href="<?= $mainMenuUrl ?>" title="Kembali ke menu utama ZURIE">☰ Menu Utama</a>
    <a class="nav-button" href="<?= $dashboardUrl ?>" title="Kembali ke dashboard ZURIE">← Dashboard</a>
    <label><input id="auto" type="checkbox" checked> Auto</label>
    <button id="refresh" type="button">Refresh</button>
  </div>
</header>
<section class="toolbar">
  <div class="legend"><span id="syncState" class="sync-state">NOC: CHECKING</span><span class="on">● Online</span><span class="warn">● Warning</span><span class="off">● Offline</span><span class="unk">● Unknown</span><span class="l1">━━ 1G</span><span class="l10">━━ 10G</span><span class="l40">━━ 40G</span></div>
  <div><input id="search" placeholder="Cari nama atau IP"><label><input id="onlyDown" type="checkbox"> Only down</label></div>
</section>
<main>
  <section class="mapbox"><svg id="map" viewBox="0 0 1760 990"><g id="areas"></g><g id="links"></g><g id="nodes"></g></svg></section>
  <aside>
    <section class="card"><h2>Active Alerts <b id="downBadge">0</b></h2><div id="alertList"></div></section>
    <section class="card"><h2>Latency Summary</h2><dl><div><dt>&lt;10 ms</dt><dd id="fast">0</dd></div><div><dt>10–50 ms</dt><dd id="mid">0</dd></div><div><dt>&gt;50 ms</dt><dd id="slow">0</dd></div><div><dt>Ping down</dt><dd id="down">0</dd></div></dl></section>
    <section class="card" id="detail"><h2>Device Detail</h2><p>Klik mana-mana peranti.</p></section>
  </aside>
</main>
</div>
<script>window.ZURIE_MAP={refreshMs:<?= $refresh ?>};</script>
<script src="assets/map.js?v=2.3"></script>
</body>
</html>

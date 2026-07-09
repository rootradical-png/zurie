<?php
require_once dirname(__DIR__) . '/lib/security.php';
zurie_security_protect_sensitive_page();
$devicesFile = dirname(__DIR__) . '/data/noc_devices.json';
$all = is_file($devicesFile) ? json_decode((string)file_get_contents($devicesFile), true) : [];
$servers = array_values(array_filter(is_array($all) ? $all : [], fn($d) => ($d['type'] ?? '') === 'Server'));
$selectedId = trim((string)($_GET['device_id'] ?? ''));
function hsm($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Server Metrics | Personal NOC</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/server-metrics.css">
</head>
<body data-selected-server="<?= hsm($selectedId) ?>">
<div class="page-shell server-metrics-shell">
<header class="page-header sm-page-header">
  <div>
    <a href="../index.php" class="back-link">← Dashboard</a>
    <div class="breadcrumb-mini">Monitoring / Server Health</div>
    <h1>🖥️ Server Metrics</h1>
    <p>CPU, RAM, ruang storan dan uptime dihantar oleh agent setiap 60 saat.</p>
  </div>
  <div class="header-actions"><a class="ghost-btn" href="server_agent_setup.php">🔑 Agent Setup</a><button id="smRefreshBtn" class="ghost-btn" type="button">↻ Refresh</button></div>
</header>

<section class="sm-summary-grid">
  <article><span>Monitored</span><strong id="smMonitored">0</strong><small>agent aktif</small></article>
  <article><span>Healthy</span><strong id="smHealthy">0</strong><small>server sihat</small></article>
  <article><span>Warning</span><strong id="smWarning">0</strong><small>perlu perhatian</small></article>
  <article><span>Critical / Stale</span><strong id="smCritical">0</strong><small>semak segera</small></article>
</section>

<div id="smNotice" class="sm-notice neutral">Sedang membaca server metrics...</div>

<section class="sm-server-grid" id="smServerGrid">
<?php foreach ($servers as $d): ?>
<article class="sm-server-card neutral" data-server-id="<?= hsm($d['id'] ?? '') ?>">
  <div class="sm-card-head"><div><span class="sm-type">SERVER</span><h3><?= hsm($d['name'] ?? '') ?></h3><p><code><?= hsm($d['ip'] ?? '') ?></code></p></div><span class="sm-state">WAIT</span></div>
  <div class="sm-metric-row"><span>CPU</span><b class="sm-cpu-value">--%</b><div class="sm-bar"><i class="sm-cpu-bar"></i></div></div>
  <div class="sm-metric-row"><span>Memory</span><b class="sm-memory-value">--%</b><div class="sm-bar"><i class="sm-memory-bar"></i></div></div>
  <div class="sm-metric-row"><span>Disk Max</span><b class="sm-disk-value">--%</b><div class="sm-bar"><i class="sm-disk-bar"></i></div></div>
  <div class="sm-card-foot"><span class="sm-uptime">Uptime --</span><span class="sm-lastseen">Belum ada data</span></div>
  <a class="sm-card-link" href="server_metrics.php?device_id=<?= urlencode((string)($d['id'] ?? '')) ?>" aria-label="Lihat detail"></a>
</article>
<?php endforeach; ?>
</section>

<?php if ($selectedId): ?>
<section class="section-block sm-detail-panel" id="smDetailPanel">
  <div class="section-title row-title"><div><span class="sm-kicker">DETAIL 24 JAM</span><h3 id="smDetailTitle">Server Detail</h3><p id="smDetailSubtitle">Menunggu data...</p></div><a class="ghost-btn" href="server_metrics.php">Tutup Detail</a></div>
  <div class="sm-detail-grid">
    <div class="sm-chart-card"><canvas id="smHistoryChart" width="900" height="260"></canvas><div class="sm-chart-legend"><span>CPU</span><span>Memory</span><span>Disk</span></div></div>
    <div class="sm-info-card"><h4>System</h4><dl id="smSystemInfo"></dl></div>
  </div>
  <div class="sm-detail-grid lower">
    <div class="sm-table-card"><h4>Disk / Partition</h4><div class="sm-table-wrap"><table><thead><tr><th>Disk</th><th>Total</th><th>Used</th><th>Free</th><th>%</th></tr></thead><tbody id="smDiskBody"><tr><td colspan="5">Tiada data</td></tr></tbody></table></div></div>
    <div class="sm-table-card"><h4>Services</h4><div class="sm-service-list" id="smServiceList">Tiada data</div></div>
  </div>
</section>
<?php endif; ?>

</div>
<script>window.SERVER_METRICS_API='../api/server_metrics_current.php';</script>
<script src="../assets/js/server-metrics.js?v=1"></script>
</body>
</html>

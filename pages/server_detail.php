<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/server_metrics.php';

$isGuest = function_exists('zurie_is_guest') && zurie_is_guest();
function hsd_mask_ip($ip): string
{
    $ip = (string)$ip;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return $parts[0] . '.' . $parts[1] . '.x.x';
    }
    return $ip === '' ? '' : 'hidden';
}

$devices = sm_load_devices();
$deviceId = trim((string)($_GET['device_id'] ?? ''));
$device = $devices[$deviceId] ?? null;

function hsd($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$ids = array_keys($devices);
$currentIndex = array_search($deviceId, $ids, true);
$previousId = null;
$nextId = null;
if ($currentIndex !== false) {
    if ($currentIndex > 0) $previousId = $ids[$currentIndex - 1];
    if ($currentIndex < count($ids) - 1) $nextId = $ids[$currentIndex + 1];
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?= $device ? hsd(($device['name'] ?? 'Server') . ' | Server Detail') : 'Server Tidak Ditemui' ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/server-metrics.css?v=2">
</head>
<body data-selected-server="<?= hsd($deviceId) ?>">
<div class="page-shell server-metrics-shell sm-detail-page-shell">
<header class="page-header sm-page-header sm-detail-header">
  <div>
    <a href="server_metrics.php" class="back-link">← Semua Server</a>
    <div class="breadcrumb-mini">Monitoring / Server Health / Detail</div>
    <h1 id="sdTitle"><?= $device ? hsd($device['name'] ?? 'Server') : 'Server tidak ditemui' ?></h1>
    <p id="sdSubtitle"><?= $device ? hsd(($isGuest ? hsd_mask_ip($device['ip'] ?? '') : ($device['ip'] ?? '')) . (!$isGuest && !empty($device['model']) ? ' · ' . $device['model'] : '')) : 'Device ID tidak sah atau server sudah dibuang.' ?></p>
  </div>
  <div class="header-actions sm-detail-actions">
    <?php if ($device && !empty($device['url'])): ?>
      <a id="sdOpenServer" class="ghost-btn" href="<?= hsd($device['url']) ?>" target="_blank" rel="noopener noreferrer">↗ Buka Server</a>
    <?php endif; ?>
    <?php if ($device && !$isGuest): ?><button id="sdCopyIp" class="ghost-btn" type="button" data-ip="<?= hsd($device['ip'] ?? '') ?>">⧉ Salin IP</button><?php endif; ?>
    <button id="sdRefreshBtn" class="ghost-btn" type="button">↻ Refresh</button>
  </div>
</header>

<?php if (!$device): ?>
<section class="sm-invalid-device">
  <strong>Server tidak ditemui.</strong>
  <p>Pilih server melalui halaman Server Metrics.</p>
  <a class="sm-primary-btn" href="server_metrics.php">Kembali ke Senarai Server</a>
</section>
<?php else: ?>

<section class="sd-hero neutral" id="sdHero">
  <div class="sd-hero-main">
    <div>
      <span class="sm-kicker">LIVE SERVER HEALTH</span>
      <h2><?= hsd($device['name'] ?? '') ?></h2>
      <div class="sd-server-meta">
        <button type="button" class="sd-ip-chip" id="sdIpChip" data-ip="<?= $isGuest ? '' : hsd($device['ip'] ?? '') ?>"><?= hsd($isGuest ? hsd_mask_ip($device['ip'] ?? '') : ($device['ip'] ?? '')) ?></button>
        <?php if (!$isGuest && !empty($device['model'])): ?><span><?= hsd($device['model']) ?></span><?php endif; ?>
        <?php if (!$isGuest && !empty($device['serial'])): ?><span>S/N <?= hsd($device['serial']) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="sd-live-block">
      <span class="sd-live-dot"></span>
      <strong id="sdState">WAIT</strong>
      <small id="sdLastSeen">Menunggu data...</small>
    </div>
  </div>
  <div class="sd-refresh-track"><i id="sdRefreshTrack"></i></div>
</section>

<div id="sdNotice" class="sm-notice neutral">Sedang memuatkan butiran server...</div>

<section class="sd-metric-grid">
  <article class="sd-metric-card">
    <span>CPU Usage</span>
    <strong id="sdCpu">--%</strong>
    <div class="sm-bar"><i id="sdCpuBar"></i></div>
    <small id="sdCpuNote">Menunggu agent</small>
  </article>
  <article class="sd-metric-card">
    <span>Memory Usage</span>
    <strong id="sdMemory">--%</strong>
    <div class="sm-bar"><i id="sdMemoryBar"></i></div>
    <small id="sdMemoryNote">-- / --</small>
  </article>
  <article class="sd-metric-card">
    <span>Disk Tertinggi</span>
    <strong id="sdDisk">--%</strong>
    <div class="sm-bar"><i id="sdDiskBar"></i></div>
    <small id="sdDiskNote">Semua partition</small>
  </article>
  <article class="sd-metric-card">
    <span>Server Uptime</span>
    <strong id="sdUptime">--</strong>
    <div class="sd-uptime-line"><i></i></div>
    <small id="sdCollected">Belum ada data</small>
  </article>
</section>

<section class="sd-main-grid">
  <article class="sm-chart-card sd-history-card">
    <div class="sd-section-head">
      <div><span class="sm-kicker">TREND</span><h3>Prestasi 24 Jam</h3></div>
      <span id="sdSampleCount" class="sd-sample-count">0 sampel</span>
    </div>
    <canvas id="sdHistoryChart" width="1000" height="300"></canvas>
    <div class="sm-chart-legend"><span>CPU</span><span>Memory</span><span>Disk</span></div>
  </article>

  <article class="sm-info-card sd-system-card">
    <div class="sd-section-head"><div><span class="sm-kicker">SYSTEM</span><h3>Maklumat Server</h3></div></div>
    <dl id="sdSystemInfo">
      <dt>Nama</dt><dd><?= hsd($device['name'] ?? '-') ?></dd>
      <dt>IP</dt><dd><?= hsd($isGuest ? hsd_mask_ip($device['ip'] ?? '') : ($device['ip'] ?? '-')) ?></dd>
      <dt>Model / OS</dt><dd><?= hsd($isGuest ? '-' : ($device['model'] ?? '-')) ?></dd>
      <dt>Status</dt><dd>Menunggu agent...</dd>
    </dl>
  </article>
</section>

<section class="sd-lower-grid">
  <article class="sm-table-card">
    <div class="sd-section-head"><div><span class="sm-kicker">STORAGE</span><h3>Disk / Partition</h3></div></div>
    <div class="sm-table-wrap">
      <table>
        <thead><tr><th>Disk</th><th>Total</th><th>Used</th><th>Free</th><th>Usage</th></tr></thead>
        <tbody id="sdDiskBody"><tr><td colspan="5">Tiada data</td></tr></tbody>
      </table>
    </div>
  </article>

  <article class="sm-table-card">
    <div class="sd-section-head"><div><span class="sm-kicker">PROCESSES / SERVICES</span><h3>Status Aplikasi</h3></div></div>
    <div class="sm-service-list" id="sdServiceList">Tiada data</div>
  </article>
</section>

<section class="sd-agent-card">
  <div>
    <span class="sm-kicker">AGENT CONNECTION</span>
    <h3>Maklumat Penghantaran Data</h3>
  </div>
  <dl id="sdAgentInfo"><dt>Status</dt><dd>Menunggu data...</dd></dl>
</section>

<nav class="sd-server-nav" aria-label="Navigasi server">
  <?php if ($previousId !== null): ?><a href="server_detail.php?device_id=<?= rawurlencode($previousId) ?>">← Server Sebelumnya</a><?php else: ?><span></span><?php endif; ?>
  <a href="server_metrics.php">Semua Server</a>
  <?php if ($nextId !== null): ?><a href="server_detail.php?device_id=<?= rawurlencode($nextId) ?>">Server Seterusnya →</a><?php else: ?><span></span><?php endif; ?>
</nav>

<?php endif; ?>
</div>
<?php if ($device): ?>
<script>
window.SERVER_METRICS_API='../api/server_metrics_current.php';
window.SERVER_DETAIL_ID=<?= json_encode($deviceId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="../assets/js/server-detail.js?v=2"></script>
<?php endif; ?>
</body>
</html>

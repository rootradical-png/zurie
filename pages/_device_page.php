<?php
$allowedTypes = ['Switch','Server','AP','Service','ALL'];
// Simpan kategori halaman sebelum include fail data. Fail PHP yang di-include berkongsi scope,
// jadi pemboleh ubah umum seperti $type boleh tertindih jika tidak dikunci di sini.
$requestedType = isset($type) && in_array($type, $allowedTypes, true) ? $type : 'Switch';
$type = $requestedType;
$filterStatus = isset($filterStatus) ? strtoupper(trim((string)$filterStatus)) : '';
$title = isset($title) ? $title : ($requestedType === 'ALL' ? 'Semua Peranti' : $requestedType);
$icon = isset($icon) ? $icon : '📊';
$baseDir = dirname(__DIR__);
$devicesFile = $baseDir . '/data/noc_devices.php';
$devices = file_exists($devicesFile) ? include $devicesFile : [];
// Pulihkan kategori halaman selepas include sebagai perlindungan tambahan.
$type = $requestedType;
$rows = array_values(array_filter($devices, function($d) use ($requestedType) {
    return $requestedType === 'ALL' || ($d['type'] ?? '') === $requestedType;
}));
// Susunan awal: nama A-Z. JavaScript akan mengekalkan sorting selepas status live dimuatkan.
usort($rows, function($a, $b) {
    return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});
// Status sebenar hanya diketahui selepas API ping. Untuk paparan DOWN, jangan papar semua sebagai READY.
$initialRows = $filterStatus === 'DOWN' ? [] : $rows;
$editUrl = 'device_manager.php' . ($type !== 'ALL' ? '?type=' . urlencode($type) : '');
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function device_type_label($type) {
    $labels = ['Switch'=>'Switch','Server'=>'Server','AP'=>'Access Point (AP)','Service'=>'Network Service','Other'=>'Lain-lain'];
    return $labels[$type] ?? $type;
}
function device_type_class($type) {
    return 'type-' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', (string)$type));
}
$isGuest = function_exists('zurie_is_guest') && zurie_is_guest();
$canEditMonitoring = !$isGuest;
?>
<!DOCTYPE html>
<html lang="ms">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?> | Zurie NOC</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="../assets/css/device-sort.css">
</head>
<body data-device-type="<?= h($type) ?>" data-device-status="<?= h($filterStatus) ?>" data-can-edit-monitoring="<?= $canEditMonitoring ? '1' : '0' ?>">
<div class="page-shell device-page-shell">
  <header class="page-header device-page-header">
    <div>
      <a href="../index.php" class="back-link">← Dashboard</a>
      <div class="breadcrumb-mini">📶 Network & WiFi / <?= h($title) ?></div>
      <h1><?= $icon ?> <?= h($title) ?></h1>
      <p><?= $filterStatus === 'DOWN'
          ? 'Paparan ini hanya menunjukkan peranti yang gagal ping atau tidak dapat dicapai pada semakan terkini.'
          : 'Senarai peranti dipaparkan terus daripada <code>data/noc_devices.json</code>. Status live akan dikemas kini jika API ping berjaya.' ?></p>
    </div>
    <div class="header-actions">
      <?php if ($canEditMonitoring): ?><a class="ghost-btn" href="<?= h($editUrl) ?>">📝 Edit Peranti</a><?php endif; ?>
      <button id="nocRefreshBtn" class="ghost-btn" type="button">Refresh Status</button>
    </div>
  </header>

  <section class="section-block noc-overview-card">
    <div class="section-title row-title">
      <div>
        <h3><?= $filterStatus === 'DOWN' ? 'Peranti Sedang DOWN' : 'Senarai ' . h($title) ?></h3>
        <p><span id="deviceCount"><?= count($initialRows) ?></span> rekod<?= $filterStatus === 'DOWN' ? ' DOWN' : '' ?>.</p>
      </div>
      <label class="auto-refresh"><input id="nocAutoRefresh" type="checkbox" checked> Auto 60s</label>
    </div>
    <div id="nocLastUpdate" class="notice-box slim-notice"><?= $filterStatus === 'DOWN'
        ? 'Sedang menyemak status semua peranti. Hanya peranti DOWN akan dipaparkan.'
        : 'Paparan awal daripada fail data. Tekan Refresh Status untuk semak ping.' ?></div>
    <div class="device-list-tools">
      <input id="deviceSearch" class="search-input" placeholder="Cari jenis / nama / IP / model / serial...">
      <label class="sort-select-label">Susun
        <select id="deviceSortSelect" class="sort-select">
          <option value="name:asc" selected>Nama A–Z</option>
          <option value="name:desc">Nama Z–A</option>
          <option value="type:asc">Jenis A–Z</option>
          <option value="ip:asc">IP terendah</option>
          <option value="ip:desc">IP tertinggi</option>
          <option value="status:asc">Status DOWN dahulu</option>
          <option value="model:asc">Model A–Z</option>
        </select>
      </label>
    </div>
    <div class="sort-help">Klik tajuk kolum untuk tukar susunan menaik atau menurun.</div>
    <div class="device-table-wrap device-detail-table">
      <table class="device-table" id="deviceDetailTable">
        <thead><tr>
          <th class="sortable-th"><button type="button" data-device-sort-key="type">Jenis Peranti <span class="sort-arrow"></span></button></th>
          <th class="sortable-th"><button type="button" data-device-sort-key="name">Nama / Serial <span class="sort-arrow">▲</span></button></th>
          <th class="sortable-th"><button type="button" data-device-sort-key="model">Model <span class="sort-arrow"></span></button></th>
          <th class="sortable-th"><button type="button" data-device-sort-key="ip">IP <span class="sort-arrow"></span></button></th>
          <th class="sortable-th"><button type="button" data-device-sort-key="status">Status <span class="sort-arrow"></span></button></th>
          <th>Link</th>
          <?php if ($canEditMonitoring): ?><th>Monitoring</th><?php endif; ?>
        </tr></thead>
        <tbody id="deviceListBody">
        <?php if (!$initialRows): ?>
          <tr><td colspan="<?= $canEditMonitoring ? 7 : 6 ?>"><?= $filterStatus === 'DOWN' ? 'Sedang menyemak peranti DOWN...' : 'Tiada rekod untuk kategori ini.' ?></td></tr>
        <?php else: ?>
          <?php foreach ($initialRows as $d):
            $url = $d['url'] ?? (($d['ip'] ?? '') ? 'http://' . $d['ip'] : '#');
          ?>
          <tr data-device-static="1">
            <td><span class="device-type-badge <?= h(device_type_class($d['type'] ?? 'Other')) ?>"><?= h(device_type_label($d['type'] ?? 'Other')) ?></span></td>
            <td><b><?= h($d['name'] ?? '') ?></b><small><?= h($d['serial'] ?? '-') ?></small></td>
            <td><?= h($d['model'] ?? '-') ?></td>
            <td><a href="<?= h($url) ?>" target="_blank"><code><?= h($d['ip'] ?? '') ?></code></a></td>
            <?php $isPaused = strtolower((string)($d['monitoring_status'] ?? 'active')) === 'paused'; ?>
            <td><span class="status-pill <?= $isPaused ? 'pending' : 'pending' ?>"><?= $isPaused ? 'PAUSED' : 'READY' ?></span><?= $isPaused && !empty($d['monitoring_note']) ? '<small>' . h($d['monitoring_note']) . '</small>' : '' ?></td>
            <td><a class="open-link" href="<?= h($url) ?>" target="_blank">Open</a></td>
            <?php if ($canEditMonitoring): ?>
            <td>
              <button class="open-link monitor-toggle-btn" type="button" data-device-id="<?= h($d['id'] ?? '') ?>" data-next-status="<?= $isPaused ? 'active' : 'paused' ?>">
                <?= $isPaused ? 'Aktifkan' : 'Pause' ?>
              </button>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
<script src="../assets/js/noc.js"></script>
</body>
</html>

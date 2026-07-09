<?php
session_start();

$devicesFile = __DIR__ . '/../data/noc_devices.json';
$favoritesFile = __DIR__ . '/../data/live_ping_favorites.json';
$recentFile = __DIR__ . '/../data/live_ping_recent.json';
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$isDownOnly = $statusFilter === 'down';
$livePingApi = '../api/live_ping.php' . ($isDownOnly ? '?status=down' : '');

function lp_e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function lp_id($device) {
    return substr(sha1(strtolower(trim(($device['type'] ?? '') . '|' . ($device['name'] ?? '') . '|' . ($device['ip'] ?? '')))), 0, 16);
}
function lp_read_json($file, $default) {
    if (!is_file($file)) return $default;
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}
function lp_recent_time($recentMap, $id) {
    return isset($recentMap[$id]) ? (int)$recentMap[$id] : 0;
}

$devices = lp_read_json($devicesFile, []);
$favorites = lp_read_json($favoritesFile, ['device_ids' => []]);
$selected = array_map('strval', $favorites['device_ids'] ?? []);
$recentPayload = lp_read_json($recentFile, ['devices' => []]);
$recentMap = is_array($recentPayload['devices'] ?? null) ? $recentPayload['devices'] : [];

foreach ($devices as &$device) {
    $device['id'] = lp_id($device);
    $device['last_opened'] = lp_recent_time($recentMap, $device['id']);
}
unset($device);

usort($devices, function($a, $b) use ($selected) {
    $aRecent = (int)($a['last_opened'] ?? 0);
    $bRecent = (int)($b['last_opened'] ?? 0);
    if ($aRecent !== $bRecent) return $bRecent <=> $aRecent;

    $aSelected = in_array((string)($a['id'] ?? ''), $selected, true) ? 1 : 0;
    $bSelected = in_array((string)($b['id'] ?? ''), $selected, true) ? 1 : 0;
    if ($aSelected !== $bSelected) return $bSelected <=> $aSelected;

    $typeCmp = strcmp((string)($a['type'] ?? ''), (string)($b['type'] ?? ''));
    return $typeCmp !== 0 ? $typeCmp : strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

$message = '';
$error = '';

if (empty($_SESSION['lp_csrf'])) $_SESSION['lp_csrf'] = bin2hex(random_bytes(24));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['lp_csrf'], $token)) {
        $error = 'Sesi tidak sah. Refresh halaman dan cuba semula.';
    } else {
        $validIds = [];
        foreach ($devices as $device) $validIds[$device['id']] = true;
        $posted = isset($_POST['device_ids']) && is_array($_POST['device_ids']) ? $_POST['device_ids'] : [];
        $posted = array_values(array_unique(array_filter(array_map('strval', $posted), function($id) use ($validIds) { return isset($validIds[$id]); })));
        if (count($posted) > 6) {
            $error = 'Pilih maksimum 6 device sahaja supaya live ping kekal ringan.';
        } elseif (count($posted) < 1) {
            $error = 'Pilih sekurang-kurangnya satu device.';
        } else {
            $payload = ['device_ids' => $posted, 'updated_at' => date('Y-m-d H:i:s')];
            $ok = @file_put_contents($favoritesFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            if ($ok === false) {
                $error = 'Tidak dapat simpan. Pastikan folder /zurie/data boleh ditulis oleh Apache.';
            } else {
                $selected = $posted;
                $message = 'Pilihan Live Ping berjaya disimpan.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Live Ping Favorites</title>
<link rel="icon" href="/zurie/image/zuriex.jpg">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/noc-dashboard.css">
<link rel="stylesheet" href="../assets/css/live-ping.css?v=20260622-compactdetail1">
</head>
<body class="live-ping-page is-fit-mode">
<div class="live-ping-page-shell">
    <div class="live-ping-page-top">
        <div class="live-ping-title-block">
            <a href="../index.php">← Dashboard</a>
            <div class="live-ping-title-row">
                <h1><?= $isDownOnly ? 'Live Ping - Device DOWN' : 'Live Ping Favorites' ?></h1>
                <span class="live-ping-live-badge"><i></i> LIVE</span>
            </div>
            <p><?= $isDownOnly ? 'Paparan ini hanya memaparkan device yang sedang DOWN.' : 'Latency masa nyata dari server NOC • purata 4 paket • refresh setiap 10 saat' ?></p>
        </div>
        <div class="live-ping-top-actions">
            <button type="button" class="live-ping-top-btn" data-view-toggle>⛶ Paparan Penuh</button>
            <?php if ($isDownOnly): ?>
            <a class="live-ping-top-btn" href="live_ping.php">Semua Device</a>
            <?php endif; ?>
            <button type="button" class="live-ping-top-btn is-primary" data-config-open>⚙ Pilih Device</button>
            <a href="../pages/device_manager.php">Device Manager</a>
        </div>
    </div>

    <section class="noc-panel live-ping-panel" data-live-ping data-api="<?= lp_e($livePingApi) ?>" data-interval="10000" data-server-detail-base="server_detail.php" data-csrf="<?= lp_e($_SESSION['lp_csrf']) ?>">
        <div class="panel-heading live-ping-heading">
            <div class="live-ping-heading-left">
                <h3><?= $isDownOnly ? 'DEVICE DOWN' : 'LIVE LATENCY' ?> <span>(10 SAAT)</span></h3>
                <small><?= $isDownOnly ? 'Hanya device yang gagal ping akan dipaparkan di sini' : 'Graf sentiasa bergerak • klik kad Server untuk buka detail' ?></small>
            </div>
            <div class="live-ping-run-state">
                <div class="live-ping-running"><span></span><b data-live-state>Menunggu semakan</b></div>
                <div class="live-ping-last">Belum disemak</div>
            </div>
        </div>
        <div class="live-ping-progress-track" aria-hidden="true"><span data-live-progress></span></div>
        <div class="live-ping-next-row"><span data-live-next>Semakan bermula...</span><span data-live-cycle>Cycle #0</span></div>
        <div class="live-ping-grid" data-live-ping-grid><div class="live-ping-empty">Sedang memuatkan graph ping...</div></div>
    </section>
</div>

<div class="live-ping-config-backdrop" data-config-backdrop></div>
<form method="post" class="live-ping-config" data-config-panel <?= ($message || $error) ? 'data-force-open="1"' : '' ?>>
    <div class="live-ping-config-head">
        <div><h2>Pilih device untuk dipantau</h2><p>Maksimum 6 device. Device terbaru dibuka akan disusun di hadapan.</p></div>
        <button type="button" class="live-ping-config-close" data-config-close>×</button>
    </div>
    <?php if ($message): ?><div class="live-ping-msg"><?= lp_e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="live-ping-msg live-ping-error"><?= lp_e($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= lp_e($_SESSION['lp_csrf']) ?>">
    <div class="live-ping-selector">
        <?php foreach ($devices as $device): ?>
        <label class="live-ping-option <?= !empty($device['last_opened']) ? 'is-recent-device' : '' ?>">
            <input type="checkbox" name="device_ids[]" value="<?= lp_e($device['id']) ?>" <?= in_array($device['id'], $selected, true) ? 'checked' : '' ?>>
            <span>
                <b><?= lp_e($device['name'] ?? '') ?></b>
                <small><?= lp_e(($device['type'] ?? '') . ' • ' . ($device['ip'] ?? '') . ' • ' . ($device['model'] ?? '')) ?></small>
                <?php if (!empty($device['last_opened'])): ?>
                <em>Terakhir dibuka: <?= lp_e(date('d/m H:i', (int)$device['last_opened'])) ?></em>
                <?php endif; ?>
            </span>
        </label>
        <?php endforeach; ?>
    </div>
    <div class="live-ping-save-row"><span class="live-ping-msg">Graph menyimpan 60 sampel terakhir bagi setiap device.</span><button type="submit">Simpan Pilihan</button></div>
</form>

<script src="../assets/js/live-ping.js?v=20260622-compactdetail1"></script>
<script>
(function(){
  var body=document.body;
  var panel=document.querySelector('[data-config-panel]');
  var backdrop=document.querySelector('[data-config-backdrop]');
  var openBtn=document.querySelector('[data-config-open]');
  var closeBtn=document.querySelector('[data-config-close]');
  var viewBtn=document.querySelector('[data-view-toggle]');
  function setConfig(open){
    if(!panel||!backdrop)return;
    panel.classList.toggle('is-open',open);
    backdrop.classList.toggle('is-open',open);
    body.classList.toggle('is-config-open',open);
  }
  if(openBtn)openBtn.addEventListener('click',function(){setConfig(true);});
  if(closeBtn)closeBtn.addEventListener('click',function(){setConfig(false);});
  if(backdrop)backdrop.addEventListener('click',function(){setConfig(false);});
  if(panel&&panel.getAttribute('data-force-open')==='1')setConfig(true);
  if(viewBtn)viewBtn.addEventListener('click',function(){
    var full=body.classList.toggle('is-full-mode');
    body.classList.toggle('is-fit-mode',!full);
    viewBtn.textContent=full?'▣ Fit Screen':'⛶ Paparan Penuh';
  });
  var form=document.querySelector('.live-ping-config');
  if(form)form.addEventListener('change',function(ev){
    var checked=form.querySelectorAll('input[name="device_ids[]"]:checked');
    if(checked.length>6){ev.target.checked=false;alert('Maksimum 6 device sahaja.');}
  });
  document.addEventListener('keydown',function(ev){if(ev.key==='Escape')setConfig(false);});
})();
</script>
</body>
</html>

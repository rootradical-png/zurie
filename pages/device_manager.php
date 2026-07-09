<?php
$baseDir = dirname(__DIR__);
$dataFile = $baseDir . '/data/noc_devices.json';
$devices = [];
$message = '';

$typeOptions = [
    'Switch'  => 'Switch',
    'Server'  => 'Server',
    'AP'      => 'Access Point (AP)',
    'Service' => 'Network Service',
    'Other'   => 'Lain-lain',
];

function clean_text($value) {
    return trim(str_replace(["\r", "\n"], ' ', (string)$value));
}
function normalize_url($ip, $url) {
    $ip = trim((string)$ip);
    $url = trim((string)$url);
    if ($url !== '') return $url;
    return $ip !== '' ? 'http://' . $ip : '';
}

function generate_device_id() {
    try { return 'dev_' . bin2hex(random_bytes(8)); }
    catch (Throwable $e) { return 'dev_' . uniqid('', true); }
}

function normalize_device_type($value, $typeOptions) {
    $value = clean_text($value);
    return array_key_exists($value, $typeOptions) ? $value : 'Other';
}
function normalize_monitoring_status($value) {
    $value = strtolower(clean_text($value));
    return in_array($value, ['active', 'paused'], true) ? $value : 'active';
}
function save_devices($file, $devices) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return file_put_contents(
        $file,
        json_encode(array_values($devices), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    ) !== false;
}
function load_devices($file) {
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}
function deduplicate_devices($devices) {
    $unique = [];
    foreach ($devices as $device) {
        $type = $device['type'] ?? 'Other';
        $ip = $device['ip'] ?? '';
        $url = $device['url'] ?? '';
        $key = strtolower($type . '|' . $ip . (($type === 'Service') ? ('|' . $url) : ''));
        $unique[$key] = $device;
    }
    return array_values($unique);
}

$devices = load_devices($dataFile);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Butang padam berada dalam borang edit. Semak dahulu supaya tidak tertukar dengan Simpan Semua.
    if (isset($_POST['delete_idx'])) {
        $idx = (int)$_POST['delete_idx'];
        if ($idx >= 0 && isset($devices[$idx])) {
            array_splice($devices, $idx, 1);
            $message = save_devices($dataFile, $devices)
                ? '✅ Peranti telah dipadam.'
                : '❌ Gagal padam. Semak permission folder data/.';
        }
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';

        if ($action === 'save_all') {
            $indices = isset($_POST['device_idx']) && is_array($_POST['device_idx']) ? $_POST['device_idx'] : [];
            $types = isset($_POST['type']) && is_array($_POST['type']) ? $_POST['type'] : [];
            $names = isset($_POST['name']) && is_array($_POST['name']) ? $_POST['name'] : [];
            $models = isset($_POST['model']) && is_array($_POST['model']) ? $_POST['model'] : [];
            $serials = isset($_POST['serial']) && is_array($_POST['serial']) ? $_POST['serial'] : [];
            $ips = isset($_POST['ip']) && is_array($_POST['ip']) ? $_POST['ip'] : [];
            $urls = isset($_POST['url']) && is_array($_POST['url']) ? $_POST['url'] : [];
            $monitoringStatuses = isset($_POST['monitoring_status']) && is_array($_POST['monitoring_status']) ? $_POST['monitoring_status'] : [];
            $monitoringNotes = isset($_POST['monitoring_note']) && is_array($_POST['monitoring_note']) ? $_POST['monitoring_note'] : [];

            $count = count($indices);
            for ($i = 0; $i < $count; $i++) {
                $idx = (int)($indices[$i] ?? -1);
                if ($idx < 0 || !isset($devices[$idx])) continue;

                $type = normalize_device_type($types[$i] ?? 'Other', $typeOptions);
                $name = clean_text($names[$i] ?? '');
                $model = clean_text($models[$i] ?? '');
                $serial = clean_text($serials[$i] ?? '');
                $ip = clean_text($ips[$i] ?? '');
                $url = normalize_url($ip, clean_text($urls[$i] ?? ''));
                $monitoringStatus = normalize_monitoring_status($monitoringStatuses[$i] ?? 'active');
                $monitoringNote = clean_text($monitoringNotes[$i] ?? '');

                if ($name === '' && $ip === '') continue;
                $deviceId = isset($devices[$idx]['id']) && $devices[$idx]['id'] !== '' ? $devices[$idx]['id'] : generate_device_id();
                $devices[$idx] = [
                    'id' => $deviceId,
                    'type' => $type,
                    'name' => $name,
                    'model' => $model,
                    'serial' => $serial,
                    'ip' => $ip,
                    'url' => $url,
                    'monitoring_status' => $monitoringStatus,
                    'monitoring_note' => $monitoringNote,
                ];
            }

            $devices = deduplicate_devices($devices);
            $message = save_devices($dataFile, $devices)
                ? '✅ Senarai peranti berjaya disimpan.'
                : '❌ Gagal simpan. Semak permission folder data/.';
        }

        if ($action === 'add') {
            $type = normalize_device_type($_POST['new_type'] ?? 'Switch', $typeOptions);
            $name = clean_text($_POST['new_name'] ?? '');
            $model = clean_text($_POST['new_model'] ?? '');
            $serial = clean_text($_POST['new_serial'] ?? '');
            $ip = clean_text($_POST['new_ip'] ?? '');
            $url = normalize_url($ip, clean_text($_POST['new_url'] ?? ''));
            $monitoringStatus = normalize_monitoring_status($_POST['new_monitoring_status'] ?? 'active');
            $monitoringNote = clean_text($_POST['new_monitoring_note'] ?? '');

            if ($name !== '' || $ip !== '') {
                $devices[] = [
                    'id' => generate_device_id(),
                    'type' => $type,
                    'name' => $name,
                    'model' => $model,
                    'serial' => $serial,
                    'ip' => $ip,
                    'url' => $url,
                    'monitoring_status' => $monitoringStatus,
                    'monitoring_note' => $monitoringNote,
                ];
                $devices = deduplicate_devices($devices);
                $message = save_devices($dataFile, $devices)
                    ? '✅ Peranti baru berjaya ditambah.'
                    : '❌ Gagal tambah. Semak permission folder data/.';
            }
        }
    }

    $devices = load_devices($dataFile);
}

$typeFilter = isset($_GET['type']) && array_key_exists($_GET['type'], $typeOptions) ? $_GET['type'] : '';
$filtered = [];
foreach ($devices as $idx => $d) {
    if ($typeFilter === '' || ($d['type'] ?? '') === $typeFilter) {
        $d['_idx'] = $idx;
        $filtered[] = $d;
    }
}

$totals = array_fill_keys(array_keys($typeOptions), 0);
foreach ($devices as $d) {
    $t = $d['type'] ?? 'Other';
    if (!isset($totals[$t])) $totals[$t] = 0;
    $totals[$t]++;
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Device Manager | Personal NOC Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/device-sort.css">
</head>
<body>
<div class="page-shell">
    <header class="page-header">
        <div>
            <a href="../index.php" class="back-link">← Dashboard</a>
            <div class="breadcrumb-mini">📶 Network & WiFi / 📝 Device Manager</div>
            <h1>Device Manager</h1>
            <p>Edit semua peranti. Gunakan <b>Monitoring</b> untuk pause server/peranti yang block ping atau memang ditutup supaya tidak jadi false alarm.</p>
        </div>
        <div class="header-actions">
            <a class="ghost-btn" href="../index.php#noc-status">📊 NOC Dashboard</a>
            <a class="ghost-btn" href="switch_inventory.php">🔀 Switch Inventory</a>
        </div>
    </header>

    <?php if ($message): ?><div class="notice-box"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <section class="section-block compact-panel">
        <div class="noc-stats-grid compact-noc-stats">
            <?php foreach (['Switch'=>'🔀 Switch','AP'=>'📶 AP','Server'=>'🖥️ Server','Service'=>'🔐 Service'] as $key=>$label): ?>
            <a class="noc-stat" href="?type=<?= urlencode($key) ?>"><span><?= $label ?></span><strong><?= (int)($totals[$key] ?? 0) ?></strong><small>Edit <?= htmlspecialchars($typeOptions[$key]) ?></small></a>
            <?php endforeach; ?>
            <a class="noc-stat" href="device_manager.php"><span>📋 Semua</span><strong><?= count($devices) ?></strong><small>Semua peranti</small></a>
        </div>
    </section>

    <section class="section-block compact-panel">
        <div class="section-title row-title"><div><h3>Tambah Peranti Baru</h3><p>Pilih jenis peranti, kemudian masukkan nama dan IP. URL boleh dikosongkan.</p></div></div>
        <form method="post" class="device-add-form">
            <input type="hidden" name="action" value="add">
            <select name="new_type" aria-label="Jenis peranti baru">
                <?php foreach ($typeOptions as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <input name="new_name" placeholder="Nama peralatan">
            <input name="new_model" placeholder="Model">
            <input name="new_serial" placeholder="Serial">
            <input name="new_ip" placeholder="IP contoh 10.14.60.3">
            <input name="new_url" placeholder="URL optional">
            <select name="new_monitoring_status" aria-label="Status monitoring baru">
                <option value="active" selected>Monitor Aktif</option>
                <option value="paused">Pause Monitoring</option>
            </select>
            <input name="new_monitoring_note" placeholder="Nota pause optional">
            <button class="hero-btn" type="submit">+ Tambah</button>
        </form>
    </section>

    <section class="section-block compact-panel">
        <div class="section-title row-title">
            <div><h3>Senarai Editable <?= $typeFilter ? htmlspecialchars($typeOptions[$typeFilter]) : 'Semua Peranti' ?></h3><p>Ubah jenis peranti melalui dropdown. Pause monitoring untuk server block ping/ditutup supaya tidak masuk alert down.</p></div>
            <div class="device-manager-tools">
                <input id="deviceSearch" class="search-input small-search" placeholder="Cari jenis / nama / IP / model...">
                <select id="managerSortSelect" class="sort-select" aria-label="Susun senarai peranti">
                    <option value="name:asc" selected>Nama A–Z</option>
                    <option value="name:desc">Nama Z–A</option>
                    <option value="type:asc">Jenis A–Z</option>
                    <option value="ip:asc">IP terendah</option>
                    <option value="ip:desc">IP tertinggi</option>
                    <option value="model:asc">Model A–Z</option>
                </select>
            </div>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="save_all">
            <div class="device-table-wrap editable-table-wrap">
                <table class="device-table editable-table" id="deviceEditTable">
                    <thead><tr>
                        <th class="sortable-th"><button type="button" data-manager-sort-key="type">Jenis Peranti <span class="sort-arrow"></span></button></th>
                        <th class="sortable-th"><button type="button" data-manager-sort-key="name">Nama Peralatan <span class="sort-arrow">▲</span></button></th>
                        <th class="sortable-th"><button type="button" data-manager-sort-key="model">Model <span class="sort-arrow"></span></button></th>
                        <th class="sortable-th"><button type="button" data-manager-sort-key="serial">Serial <span class="sort-arrow"></span></button></th>
                        <th class="sortable-th"><button type="button" data-manager-sort-key="ip">IP <span class="sort-arrow"></span></button></th>
                        <th>URL</th><th>Monitoring</th><th>Nota</th><th>Open</th><th>Padam</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($filtered as $d): $i = (int)$d['_idx']; ?>
                        <tr>
                            <td>
                                <input type="hidden" name="device_idx[]" value="<?= $i ?>">
                                <select name="type[]" aria-label="Jenis peranti <?= htmlspecialchars($d['name'] ?? '') ?>">
                                    <?php foreach ($typeOptions as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>" <?= (($d['type'] ?? 'Other') === $value) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input name="name[]" value="<?= htmlspecialchars($d['name'] ?? '') ?>"></td>
                            <td><input name="model[]" value="<?= htmlspecialchars($d['model'] ?? '') ?>"></td>
                            <td><input name="serial[]" value="<?= htmlspecialchars($d['serial'] ?? '') ?>"></td>
                            <td><input name="ip[]" value="<?= htmlspecialchars($d['ip'] ?? '') ?>"></td>
                            <td><input name="url[]" value="<?= htmlspecialchars($d['url'] ?? '') ?>"></td>
                            <td>
                                <select name="monitoring_status[]" aria-label="Status monitoring <?= htmlspecialchars($d['name'] ?? '') ?>">
                                    <option value="active" <?= (($d['monitoring_status'] ?? 'active') !== 'paused') ? 'selected' : '' ?>>Aktif</option>
                                    <option value="paused" <?= (($d['monitoring_status'] ?? 'active') === 'paused') ? 'selected' : '' ?>>Pause</option>
                                </select>
                            </td>
                            <td><input name="monitoring_note[]" value="<?= htmlspecialchars($d['monitoring_note'] ?? '') ?>" placeholder="cth: block ping / shutdown"></td>
                            <td><a class="open-link" href="<?= htmlspecialchars($d['url'] ?? ('http://' . ($d['ip'] ?? ''))) ?>" target="_blank">Open</a></td>
                            <td><button class="danger-mini" type="submit" name="delete_idx" value="<?= $i ?>" onclick="return confirm('Padam peranti ini?')">Padam</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-actions sticky-actions">
                <button class="hero-btn" type="submit">💾 Simpan Semua Perubahan</button>
                <a class="ghost-btn" href="../index.php?live=1#noc-status">Check Live Status</a>
            </div>
        </form>
        <p class="note-text small-note">Kategori utama: Switch, Server, Access Point (AP), Network Service dan Lain-lain. Device berstatus <b>Pause</b> tidak diping dan tidak dikira sebagai alert DOWN.</p>
    </section>
</div>
<script>
(function () {
  const search = document.getElementById('deviceSearch');
  const tbody = document.querySelector('#deviceEditTable tbody');
  const sortSelect = document.getElementById('managerSortSelect');
  const buttons = Array.from(document.querySelectorAll('[data-manager-sort-key]'));
  let sortKey = 'name';
  let sortDirection = 'asc';

  function rowValue(row, key) {
    const names = { type: 'type[]', name: 'name[]', model: 'model[]', serial: 'serial[]', ip: 'ip[]', monitoring: 'monitoring_status[]' };
    const field = row.querySelector('[name="' + names[key] + '"]');
    return field ? String(field.value || '').trim() : '';
  }

  function ipParts(value) {
    const host = String(value || '').split(':')[0];
    const octets = host.split('.').map(n => parseInt(n, 10));
    return octets.length === 4 && octets.every(Number.isFinite) ? octets : [999, 999, 999, 999];
  }

  function compareValues(a, b, key) {
    if (key === 'ip') {
      const aa = ipParts(a), bb = ipParts(b);
      for (let i = 0; i < 4; i++) if (aa[i] !== bb[i]) return aa[i] - bb[i];
      return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
    }
    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
  }

  function updateIndicators() {
    buttons.forEach(button => {
      const active = button.dataset.managerSortKey === sortKey;
      button.classList.toggle('active-sort', active);
      const arrow = button.querySelector('.sort-arrow');
      if (arrow) arrow.textContent = active ? (sortDirection === 'asc' ? '▲' : '▼') : '';
    });
    if (sortSelect) sortSelect.value = sortKey + ':' + sortDirection;
  }

  function applySearch() {
    const q = search ? search.value.toLowerCase().trim() : '';
    Array.from(tbody.querySelectorAll('tr')).forEach(row => {
      const values = ['type','name','model','serial','ip','monitoring'].map(key => rowValue(row, key)).join(' ').toLowerCase();
      row.style.display = !q || values.includes(q) ? '' : 'none';
    });
  }

  function sortRows() {
    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((ra, rb) => {
      const result = compareValues(rowValue(ra, sortKey), rowValue(rb, sortKey), sortKey);
      return sortDirection === 'asc' ? result : -result;
    });
    rows.forEach(row => tbody.appendChild(row));
    updateIndicators();
    applySearch();
  }

  buttons.forEach(button => {
    button.addEventListener('click', function () {
      const key = this.dataset.managerSortKey;
      if (sortKey === key) sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
      else { sortKey = key; sortDirection = 'asc'; }
      sortRows();
    });
  });

  if (sortSelect) {
    sortSelect.addEventListener('change', function () {
      const parts = this.value.split(':');
      sortKey = parts[0] || 'name';
      sortDirection = parts[1] || 'asc';
      sortRows();
    });
  }
  if (search) search.addEventListener('input', applySearch);
  sortRows();
})();
</script>
</body>
</html>

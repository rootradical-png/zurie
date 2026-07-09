<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/topology.php';

$error = null;
$data = [];
$sync = [];
try {
    $data = topology_load_live($config);
    $sync = $data['_sync'] ?? [];
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NOC Sync Diagnostic</title>
<style>
body{font-family:system-ui,sans-serif;background:#07111d;color:#eaf7ff;margin:0;padding:24px}a{color:#54d8ff}.box{background:#0d2033;border:1px solid #2a4c63;border-radius:12px;padding:18px;margin-bottom:16px}.ok{color:#45f59a}.bad{color:#ff6674}.warn{color:#ffc65b}table{width:100%;border-collapse:collapse;background:#091826}th,td{text-align:left;padding:9px;border-bottom:1px solid #20384a;font-size:13px}th{color:#9ec6da}.pill{display:inline-block;padding:3px 8px;border-radius:999px;background:#163349}code{color:#a9eaff}</style>
</head>
<body>
<p><a href="./">← Kembali ke Network Map</a></p>
<div class="box">
<h1>NOC Sync Diagnostic</h1>
<?php if ($error !== null): ?>
<p class="bad"><?= h($error) ?></p>
<?php else: ?>
<p>Sumber: <strong><?= h($sync['source'] ?? '—') ?></strong></p>
<p>Status: <strong class="<?= !empty($sync['available']) ? 'ok' : 'warn' ?>"><?= !empty($sync['available']) ? 'NOC TERHUBUNG' : 'FALLBACK LOCAL' ?></strong></p>
<p>Rekod provider: <?= h($sync['provider_count'] ?? 0) ?> · Padanan map: <?= h($sync['matched_count'] ?? 0) ?>/<?= h($sync['map_count'] ?? 0) ?> · Tidak sepadan: <?= h($sync['unmatched_count'] ?? 0) ?></p>
<?php if (!empty($sync['error'])): ?><p class="bad"><?= h($sync['error']) ?></p><?php endif; ?>
<?php endif; ?>
</div>

<?php if ($error === null): ?>
<div class="box">
<h2>Peranti Map</h2>
<table>
<thead><tr><th>Map ID</th><th>Nama</th><th>IP aktif</th><th>Padanan</th><th>Kaedah</th><th>IP lama</th></tr></thead>
<tbody>
<?php foreach (($data['devices'] ?? []) as $device): $noc = $device['_noc'] ?? []; ?>
<tr>
<td><code><?= h($device['id'] ?? '') ?></code></td>
<td><?= h($device['name'] ?? '') ?></td>
<td><?= h($device['ip'] ?? '') ?></td>
<td><span class="pill <?= !empty($noc['matched']) ? 'ok' : 'warn' ?>"><?= !empty($noc['matched']) ? 'MATCHED' : 'LOCAL' ?></span></td>
<td><?= h($noc['match_method'] ?? '—') ?></td>
<td><?= h($noc['previous_ip'] ?? '—') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</body>
</html>

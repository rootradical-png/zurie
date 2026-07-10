<?php
/**
 * i-SIMS — Sijil Akuan Kokurikulum (legacy database browser)
 * Reads directly from accessible i-SIMS databases. No legacy data is copied.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/portal_auth.php';
require_once dirname(__DIR__) . '/lib/isims_kokurikulum.php';
zurie_portal_require_extract_access();

$config = ik_config();
$error = '';
$databases = [];
$allDatabases = [];
$currentDbAccount = '';
$students = [];
$student = null;
$activities = ['section1' => [], 'section2' => [], 'section3' => [], 'activity_database' => ''];

$selectedDb = trim((string)($_GET['db'] ?? ''));
$query = ik_clean_query((string)($_GET['q'] ?? ''));
$selectedMatrik = strtoupper(trim((string)($_GET['matrik'] ?? '')));
$sessionValue = trim((string)($_GET['session'] ?? ''));

try {
    $pdo = ik_connect($config);
    $allDatabases = ik_list_accessible_databases($pdo, $config);
    $databases = ik_list_databases($pdo, $config);
    $currentDbAccount = ik_current_account($pdo);
    if ($selectedDb !== '' && !in_array($selectedDb, $databases, true)) {
        throw new RuntimeException('Database yang dipilih tidak dibenarkan atau tidak dapat dicapai oleh user i-SIMS.');
    }

    if ($sessionValue === '' && $selectedDb !== '') {
        $sessionValue = ik_infer_session($selectedDb, (string)$config['default_session']);
    }

    if ($selectedDb !== '' && $query !== '') {
        $students = ik_search_students($pdo, $selectedDb, $query, 50);
    }

    if ($selectedDb !== '' && $selectedMatrik !== '') {
        $student = ik_get_student($pdo, $selectedDb, $selectedMatrik);
        $activityDatabases = ik_activity_database_order($pdo, $config, $selectedDb);
        $activities = ik_get_activities($pdo, $activityDatabases, $selectedDb, $selectedMatrik);
        if ($query === '') $query = $selectedMatrik;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$assetFiles = [];
foreach (['logo_kpm','logo_kmp','stamp_kmp','director_signature'] as $assetKey) {
    $assetFiles[$assetKey] = ik_asset_file($config, $assetKey);
}
$assetUris = array_map('ik_asset_data_uri', $assetFiles);

function koku_query(array $changes = []): string
{
    $params = array_merge($_GET, $changes);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) unset($params[$key]);
    }
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sijil Akuan Kokurikulum | Zurie</title>
<style>
:root{--blue:#1d4ed8;--blue2:#eff6ff;--ink:#0f172a;--muted:#64748b;--line:#dbe4f0;--green:#15803d;--red:#b91c1c;--bg:#f3f7fc}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,sans-serif;color:var(--ink)}.wrap{max-width:1450px;margin:24px auto;padding:0 16px 60px}.card{background:#fff;border:1px solid #e5eaf2;border-radius:17px;padding:18px;box-shadow:0 8px 25px rgba(15,23,42,.06);margin-bottom:16px}.breadcrumb{font-size:13px;margin-bottom:10px}.breadcrumb a{color:var(--blue);font-weight:700;text-decoration:none}.head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}.head h1{margin:0 0 7px;font-size:27px}.muted{color:var(--muted)}.toolbar{display:grid;grid-template-columns:minmax(220px,1fr) minmax(260px,2fr) minmax(150px,.7fr) auto;gap:10px;align-items:end}.field label{display:block;font-size:12px;font-weight:800;margin-bottom:6px;color:#334155}.field input,.field select{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:11px;background:#fff;font-size:14px}.btn{border:0;border-radius:10px;padding:11px 15px;background:var(--blue);color:#fff;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}.btn.green{background:var(--green)}.btn.secondary{background:#e2e8f0;color:#0f172a}.notice{padding:13px 15px;border-radius:12px;margin-bottom:15px;font-weight:700}.notice.bad{background:#fee2e2;color:#991b1b}.notice.info{background:#e0f2fe;color:#075985}.status{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.pill{border-radius:999px;padding:6px 10px;background:#f1f5f9;font-size:12px;font-weight:800}.pill.ok{background:#dcfce7;color:#166534}.pill.bad{background:#fee2e2;color:#991b1b}.results{overflow:auto}.results table{width:100%;border-collapse:collapse;min-width:800px}.results th,.results td{padding:10px;border-bottom:1px solid var(--line);text-align:left;font-size:13px}.results th{background:#f8fafc}.certificate{width:210mm;min-height:297mm;margin:0 auto;background:#fff;padding:10mm;box-shadow:0 10px 30px rgba(15,23,42,.12);font-family:"Times New Roman",serif;color:#111}.cert-header{position:relative;min-height:31mm;text-align:center;border-bottom:1.5px solid #111;padding-top:1mm}.cert-logo-left{position:absolute;left:0;top:0;max-width:45mm;max-height:22mm}.cert-logo-right{position:absolute;right:0;top:0;max-width:20mm;max-height:22mm}.cert-header h2{font-size:14pt;margin:0 0 2mm}.cert-header h3{font-size:11pt;margin:0 0 2mm}.cert-header p{font-size:10pt;margin:0}.cert-title{text-align:center;font-weight:bold;font-size:14pt;margin:7mm 0 4mm}.info-grid{display:grid;grid-template-columns:22mm 1fr 28mm 55mm;row-gap:2mm;font-size:10pt}.label{font-weight:bold}.section-title{font-weight:bold;color:#036;font-size:11pt;margin:6mm 0 2mm}.cert-table{width:100%;border-collapse:collapse;font-size:9pt;table-layout:fixed}.cert-table th,.cert-table td{border:1px solid #222;padding:1.8mm;vertical-align:middle}.cert-table th{background:#f0f0f0;text-align:center}.cert-table td.center{text-align:center}.sign-area{position:relative;min-height:55mm;margin-top:9mm}.stamp{position:absolute;left:10mm;top:0;max-width:35mm;max-height:32mm}.signature{position:absolute;right:20mm;top:0;max-width:30mm;max-height:20mm}.director{position:absolute;right:0;top:25mm;width:60mm;font-size:9pt;line-height:1.45}.serial{position:absolute;left:0;bottom:4mm;font-size:8pt;font-weight:bold}.footer{text-align:center;color:#666;font-style:italic;font-size:7pt;border-top:1px solid #ddd;padding-top:2mm}.empty{text-align:center;padding:35px;color:var(--muted)}@media(max-width:1000px){.toolbar{grid-template-columns:1fr 1fr}.certificate-shell{overflow:auto}.certificate{transform-origin:top left}}@media(max-width:650px){.toolbar{grid-template-columns:1fr}.head h1{font-size:23px}}
</style>
</head>
<body><div class="wrap">
<div class="card">
<div class="breadcrumb"><a href="/zurie/">Dashboard</a> › <strong>i-SIMS › Sijil Akuan Kokurikulum</strong></div>
<div class="head">
<div><h1>Sijil Akuan Kokurikulum</h1><div class="muted">Pilih mana-mana database lama yang boleh dicapai pada server i-SIMS, cari pelajar menggunakan No. Matrik atau No. KP, preview dan jana PDF individu.</div></div>
<?php if ($student): ?>
<a class="btn green" target="_blank" href="/zurie/pages/isims_sijil_kokurikulum_pdf.php?<?= http_build_query(['db'=>$selectedDb,'matrik'=>$student['matrik'],'session'=>$sessionValue]) ?>">⬇ Jana PDF Individu</a>
<?php endif; ?>
</div>
<div class="status">
<span class="pill <?= ik_config_ready($config) ? 'ok' : 'bad' ?>">Config: <?= ik_config_ready($config) ? 'SEDIA' : 'BELUM LENGKAP' ?></span>
<span class="pill">Database pelajar: <?= count($databases) ?></span>
<span class="pill">Semua DB boleh dicapai: <?= count($allDatabases) ?></span>
<?php if ($currentDbAccount !== ''): ?><span class="pill">Akaun DB: <?= ik_h($currentDbAccount) ?></span><?php endif; ?>
<?php foreach ($assetFiles as $key => $file): ?><span class="pill <?= $file ? 'ok' : 'bad' ?>"><?= ik_h(str_replace('_',' ',strtoupper($key))) ?>: <?= $file ? 'OK' : 'TIDAK DIJUMPAI' ?></span><?php endforeach; ?>
</div>
</div>

<?php if ($error !== ''): ?><div class="notice bad"><?= ik_h($error) ?></div><?php endif; ?>
<?php if ($error === '' && !$databases): ?>
<div class="notice bad">Tiada database pelajar boleh dicapai. Jalankan SQL akses dalam <code>sql/isims_kokurikulum_grant.sql</code> menggunakan akaun MySQL admin/root. Akaun aplikasi semasa: <b><?= ik_h($currentDbAccount ?: 'tidak dapat dikenal pasti') ?></b>.</div>
<?php endif; ?>

<div class="card">
<form method="get" class="toolbar">
<div class="field"><label>Database i-SIMS lama</label><select name="db" required onchange="this.form.submit()"><option value="">— Pilih database —</option><?php foreach ($databases as $db): ?><option value="<?= ik_h($db) ?>" <?= $db === $selectedDb ? 'selected' : '' ?>><?= ik_h(ik_database_label($db)) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>No. Matrik atau No. KP</label><input type="text" name="q" value="<?= ik_h($query) ?>" placeholder="Contoh: MA2614110409 atau 071028100344" autocomplete="off"></div>
<div class="field"><label>Sesi pada sijil</label><input type="text" name="session" value="<?= ik_h($sessionValue) ?>" placeholder="2025/2026"></div>
<div><button class="btn" type="submit">Cari Pelajar</button></div>
</form>
<p class="muted" style="margin-bottom:0">Dropdown dibina terus daripada server i-SIMS menggunakan <code>SHOW DATABASES</code>, kemudian ditapis kepada <code>db_pelajarkmp</code>, <code>_pelajarkmp</code> dan <code>_pelajarkmpYYYY</code>. Database tahun baharu akan muncul automatik apabila akaun MySQL diberi akses. Data dibaca terus; tiada salinan disimpan dalam Zurie.</p>
</div>

<?php if ($students): ?>
<div class="card results"><h3 style="margin-top:0">Hasil Carian</h3><table><thead><tr><th>No. Matrik</th><th>No. KP</th><th>Nama</th><th>Jurusan</th><th>Kuliah</th><th>Tindakan</th></tr></thead><tbody>
<?php foreach ($students as $row): ?><tr><td><b><?= ik_h($row['matrik']) ?></b></td><td><?= ik_h($row['nokp']) ?></td><td><?= ik_h($row['nama']) ?></td><td><?= ik_h($row['jurusan']) ?></td><td><?= ik_h($row['kuliah']) ?></td><td><a class="btn" href="<?= ik_h(koku_query(['matrik'=>$row['matrik'],'q'=>$query])) ?>">Preview</a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php elseif ($query !== '' && !$student && $error === ''): ?><div class="card empty">Tiada pelajar ditemui menggunakan No. Matrik atau No. KP tersebut dalam database <b><?= ik_h($selectedDb) ?></b>.</div><?php endif; ?>

<?php if ($student): ?>
<div class="card"><div class="head"><div><h2 style="margin:0 0 5px">Preview Sijil</h2><div class="muted">Database pelajar: <b><?= ik_h($selectedDb) ?></b> · Database aktiviti: <b><?= ik_h($activities['activity_database'] ?: 'Tiada rekod/table dijumpai') ?></b></div></div><a class="btn green" target="_blank" href="/zurie/pages/isims_sijil_kokurikulum_pdf.php?<?= http_build_query(['db'=>$selectedDb,'matrik'=>$student['matrik'],'session'=>$sessionValue]) ?>">⬇ Jana PDF</a></div></div>
<div class="certificate-shell"><article class="certificate">
<header class="cert-header">
<?php if ($assetUris['logo_kpm']): ?><img class="cert-logo-left" src="<?= $assetUris['logo_kpm'] ?>" alt="Logo KPM"><?php endif; ?>
<?php if ($assetUris['logo_kmp']): ?><img class="cert-logo-right" src="<?= $assetUris['logo_kmp'] ?>" alt="Logo KMP"><?php endif; ?>
<h2><?= ik_h($config['college_name']) ?></h2><h3><?= ik_h($config['ministry_name']) ?></h3><p><?= ik_h($config['college_address']) ?></p>
</header>
<div class="cert-title">SIJIL AKUAN KOKURIKULUM</div>
<div class="info-grid">
<div class="label">NAMA</div><div>: <?= ik_h($student['nama']) ?></div><div></div><div></div>
<div class="label">NO. KP</div><div>: <?= ik_h($student['nokp']) ?></div><div class="label">NO. MATRIK</div><div>: <?= ik_h($student['matrik']) ?></div>
<div class="label">PROGRAM</div><div>: <?= ik_h(ik_program_label($student['program'])) ?></div><div class="label">SESI</div><div>: <?= ik_h($sessionValue) ?></div>
<div class="label">JURUSAN</div><div>: <?= ik_h($student['jurusan']) ?></div><div></div><div></div>
</div>

<div class="section-title">1. AKTIVITI KOKURIKULUM</div>
<table class="cert-table"><colgroup><col style="width:5%"><col style="width:23%"><col style="width:18%"><col style="width:18%"><col style="width:20%"><col style="width:16%"></colgroup><thead><tr><th>Bil</th><th>Perkara</th><th>Kelab/Persatuan</th><th>Peringkat</th><th>Jawatan</th><th>Pencapaian</th></tr></thead><tbody>
<?php if (!$activities['section1']): ?><tr><td colspan="6">* Tiada Rekod Penglibatan</td></tr><?php else: foreach ($activities['section1'] as $i=>$row): ?><tr><td class="center"><?= $i+1 ?></td><td><?= ik_h($row[0]) ?></td><td class="center"><?= ik_h($row[1]) ?></td><td class="center"><?= ik_h($row[2]) ?></td><td class="center"><?= ik_h($row[3]) ?></td><td class="center"><?= ik_h($row[4]) ?></td></tr><?php endforeach; endif; ?>
</tbody></table>

<div class="section-title">2. SUMBANGAN</div>
<table class="cert-table"><colgroup><col style="width:5%"><col style="width:69%"><col style="width:18%"><col style="width:8%"></colgroup><thead><tr><th>Bil</th><th>Perkara</th><th>Peringkat</th><th>Tahun</th></tr></thead><tbody>
<?php if (!$activities['section2']): ?><tr><td colspan="4">* Tiada Rekod Penglibatan</td></tr><?php else: foreach ($activities['section2'] as $i=>$row): ?><tr><td class="center"><?= $i+1 ?></td><td><?= ik_h($row[0]) ?></td><td class="center"><?= ik_h($row[1]) ?></td><td class="center"><?= ik_h($row[2]) ?></td></tr><?php endforeach; endif; ?>
</tbody></table>

<div class="section-title">3. ANUGERAH</div>
<table class="cert-table"><colgroup><col style="width:5%"><col style="width:69%"><col style="width:18%"><col style="width:8%"></colgroup><thead><tr><th>Bil</th><th>Perkara</th><th>Peringkat</th><th>Tahun</th></tr></thead><tbody>
<?php if (!$activities['section3']): ?><tr><td colspan="4">* Tiada Rekod Penglibatan</td></tr><?php else: foreach ($activities['section3'] as $i=>$row): ?><tr><td class="center"><?= $i+1 ?></td><td><?= ik_h($row[0]) ?></td><td class="center"><?= ik_h($row[1]) ?></td><td class="center"><?= ik_h($row[2]) ?></td></tr><?php endforeach; endif; ?>
</tbody></table>

<div class="sign-area">
<?php if ($assetUris['stamp_kmp']): ?><img class="stamp" src="<?= $assetUris['stamp_kmp'] ?>" alt="Cop KMP"><?php endif; ?>
<?php if ($assetUris['director_signature']): ?><img class="signature" src="<?= $assetUris['director_signature'] ?>" alt="Tandatangan Pengarah"><?php endif; ?>
<div class="director"><b>(<?= ik_h($config['director_name']) ?>)</b><br>Pengarah<br><?= ik_h($config['college_name']) ?></div>
<div class="serial">NO. SIRI: <?= ik_h(ik_serial_number($student)) ?></div>
</div>
<div class="footer">Dokumen preview dijana pada: <?= date('d/m/Y H:i:s') ?></div>
</article></div>
<?php endif; ?>
</div></body></html>

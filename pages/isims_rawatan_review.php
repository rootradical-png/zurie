<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/portal_auth.php';
zurie_portal_require_extract_access();

function raw_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function raw_config(): array
{
    $configFile = 'C:/xampp_baru/secure/isims_mysql_config.php';
    $loaded = is_file($configFile) ? require $configFile : [];
    $loaded = is_array($loaded) ? $loaded : [];

    return [
        'config_path' => $configFile,
        'enabled' => (bool)($loaded['enabled'] ?? false),
        'host' => trim((string)($loaded['host'] ?? '')),
        'port' => (int)($loaded['port'] ?? 3306),
        'dbname' => trim((string)($loaded['dbname'] ?? $loaded['database'] ?? 'db_pelajarkmp')),
        'user' => trim((string)($loaded['user'] ?? $loaded['username'] ?? '')),
        'password' => (string)($loaded['password'] ?? ''),
        'charset' => trim((string)($loaded['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
        'timeout' => max(2, min(30, (int)($loaded['timeout'] ?? 8))),
        'table' => 'rawatan',
    ];
}

function raw_config_ready(array $config): bool
{
    return $config['enabled'] && $config['host'] !== '' && $config['dbname'] !== '' && $config['user'] !== '';
}

function raw_connect(array $config): PDO
{
    if (!raw_config_ready($config)) {
        throw new RuntimeException('Konfigurasi MySQL i-SIMS belum lengkap. Isi C:\\xampp_baru\\secure\\isims_mysql_config.php.');
    }
    if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO MySQL belum aktif dalam PHP.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['dbname'],
        $config['charset']
    );

    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => $config['timeout'],
    ]);
}

function raw_quote_identifier(string $value): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        throw new RuntimeException('Nama database atau table i-SIMS tidak sah.');
    }
    return '`' . $value . '`';
}

function raw_table_ref(array $config): string
{
    return raw_quote_identifier($config['dbname']) . '.' . raw_quote_identifier($config['table']);
}

function raw_columns(): array
{
    return [
        'nama', 'nomatrik', 'jenis', 'keyin_user', 'keyin_tarikh', 'keyin_masa',
        'tutoran', 'asrama', 'status', 'tarikh_akhir', 'nosiri', 'penyakit',
        'felo', 'tempat', 'biliksakit', 'bilik', 'status_batal',
    ];
}

function raw_inputs(array $source): array
{
    $limit = isset($source['limit']) ? (int)$source['limit'] : 20;
    $limit = max(1, min(200, $limit));
    $nosiri = trim((string)($source['nosiri'] ?? ''));
    $nomatrik = strtoupper(trim((string)($source['nomatrik'] ?? '')));
    $nama = trim((string)($source['nama'] ?? ''));

    return [
        'nosiri' => preg_replace('/[^0-9]/', '', $nosiri) ?? '',
        'nomatrik' => preg_replace('/[^A-Z0-9]/', '', $nomatrik) ?? '',
        'nama' => $nama,
        'limit' => $limit,
    ];
}

function raw_build_where(array $input, array &$params): string
{
    $where = [];
    if ($input['nosiri'] !== '') {
        $where[] = '`nosiri` = :nosiri';
        $params[':nosiri'] = (int)$input['nosiri'];
    }
    if ($input['nomatrik'] !== '') {
        $where[] = '`nomatrik` LIKE :nomatrik';
        $params[':nomatrik'] = '%' . $input['nomatrik'] . '%';
    }
    if ($input['nama'] !== '') {
        $where[] = '`nama` LIKE :nama';
        $params[':nama'] = '%' . $input['nama'] . '%';
    }

    return $where ? (' WHERE ' . implode(' AND ', $where)) : '';
}

function raw_fetch_rows(PDO $pdo, array $config, array $input): array
{
    $params = [];
    $where = raw_build_where($input, $params);

    $table = raw_table_ref($config);
    $select = 'SELECT ' . implode(', ', array_map(static fn($c) => '`' . $c . '`', raw_columns()));
    $order = ' ORDER BY `keyin_tarikh` DESC, `keyin_masa` DESC, `nosiri` DESC, `nomatrik` ASC';
    $sql = $select . " FROM {$table}{$where}{$order} LIMIT " . (int)$input['limit'];
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, $key === ':nosiri' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function raw_fetch_duplicate_nosiri(PDO $pdo, array $config, int $limit = 80): array
{
    $table = raw_table_ref($config);
    $stmt = $pdo->query("SELECT `nosiri`, COUNT(*) AS jumlah, MIN(`keyin_tarikh`) AS tarikh_awal, MAX(`keyin_tarikh`) AS tarikh_akhir FROM {$table} GROUP BY `nosiri` HAVING COUNT(*) > 1 ORDER BY jumlah DESC, `nosiri` DESC LIMIT " . (int)$limit);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function raw_identity(PDO $pdo): array
{
    $row = $pdo->query('SELECT USER() AS session_user, CURRENT_USER() AS grant_user, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function raw_token(array $row): string
{
    $payload = [];
    foreach (raw_columns() as $column) {
        $payload[$column] = array_key_exists($column, $row) ? $row[$column] : null;
    }
    return rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'), '+/', '-_'), '=');
}

function raw_decode_token(string $token): array
{
    $json = base64_decode(strtr($token, '-_', '+/'), true);
    if ($json === false) {
        throw new RuntimeException('Token rekod tidak sah.');
    }
    $row = json_decode($json, true);
    if (!is_array($row)) {
        throw new RuntimeException('Token rekod tidak dapat dibaca.');
    }
    $clean = [];
    foreach (raw_columns() as $column) {
        $clean[$column] = array_key_exists($column, $row) ? $row[$column] : null;
    }
    return $clean;
}

function raw_delete_row(PDO $pdo, array $config, array $row): int
{
    $conditions = [];
    $params = [];
    foreach (raw_columns() as $index => $column) {
        $key = ':c' . $index;
        $conditions[] = '`' . $column . '` <=> ' . $key;
        $params[$key] = $row[$column];
    }
    $table = raw_table_ref($config);
    $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $conditions) . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($key, (string)$value, PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    return $stmt->rowCount();
}

function raw_output_csv(array $rows): void
{
    $filename = 'rawatan_extract_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    fputcsv($out, raw_columns());
    foreach ($rows as $row) {
        $line = [];
        foreach (raw_columns() as $column) {
            $line[] = zurie_security_csv_cell($row[$column] ?? '');
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

$config = raw_config();
$input = raw_inputs($_REQUEST);
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'latest');
$error = '';
$success = '';
$rows = [];
$duplicates = [];
$identity = [];
$configExists = is_file($config['config_path']);
$mysqlDriverAvailable = class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true);

try {
    if ($action === 'test') {
        zurie_security_require_valid_csrf();
        $pdo = raw_connect($config);
        $identity = raw_identity($pdo);
        $count = (int)$pdo->query('SELECT COUNT(*) FROM ' . raw_table_ref($config))->fetchColumn();
        $success = 'Sambungan i-SIMS OK. Table rawatan boleh dibaca. Jumlah rekod: ' . number_format($count);
    } elseif (in_array($action, ['latest', 'preview', 'search', 'csv'], true)) {
        if ($action === 'preview' || $action === 'search') {
            zurie_security_require_valid_csrf();
        }
        $pdo = raw_connect($config);
        $rows = raw_fetch_rows($pdo, $config, $input);
        if ($action === 'csv') {
            raw_output_csv($rows);
        }
        $label = ($input['nosiri'] !== '' || $input['nomatrik'] !== '' || $input['nama'] !== '') ? 'Carian berjaya.' : '20 rekod terkini dipaparkan.';
        $success = $label . ' ' . count($rows) . ' rekod untuk semakan.';
    } elseif ($action === 'duplicates') {
        zurie_security_require_valid_csrf();
        $pdo = raw_connect($config);
        $duplicates = raw_fetch_duplicate_nosiri($pdo, $config);
        $success = 'Semakan duplicate No Siri selesai. ' . count($duplicates) . ' No Siri dipaparkan.';
    } elseif ($action === 'delete') {
        zurie_security_require_valid_csrf();
        $tokens = $_POST['row_token'] ?? [];
        $confirm = trim((string)($_POST['confirm_delete'] ?? ''));
        if (!is_array($tokens) || count($tokens) === 0) {
            throw new RuntimeException('Pilih sekurang-kurangnya satu rekod untuk dipadam.');
        }
        if ($confirm !== 'PADAM') {
            throw new RuntimeException('Taip PADAM pada kotak pengesahan sebelum memadam.');
        }
        $pdo = raw_connect($config);
        $pdo->beginTransaction();
        $deleted = 0;
        try {
            foreach ($tokens as $token) {
                $deleted += raw_delete_row($pdo, $config, raw_decode_token((string)$token));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $success = 'Padam selesai. ' . $deleted . ' rekod rawatan telah dipadam.';
        if ($input['nosiri'] !== '' || $input['nomatrik'] !== '' || $input['nama'] !== '') {
            $rows = raw_fetch_rows($pdo, $config, $input);
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>i-SIMS Rawatan — Extract, Search & Padam Rekod Salah</title>
<link rel="icon" href="/zurie/image/zuriex.jpg">
<style>
:root{--bg:#07111f;--card:#0d1c2e;--line:rgba(130,170,210,.18);--text:#eaf4ff;--muted:#86a0b8;--cyan:#55d9ff;--green:#51e3a4;--red:#ff7183;--yellow:#ffd36a}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top left,#123456 0,#07111f 36%,#040b14 100%);color:var(--text);font-family:Segoe UI,Arial,sans-serif;font-size:13px}.wrap{max-width:1450px;margin:0 auto;padding:18px}.top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px}.top a{color:#9ddfff;text-decoration:none}.title h1{margin:0;font-size:22px}.title p{margin:4px 0 0;color:var(--muted)}.card{background:linear-gradient(145deg,rgba(13,28,46,.96),rgba(8,18,31,.96));border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.25);padding:16px;margin-bottom:14px}.status-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.status{padding:10px;border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.02)}.status span{display:block;color:var(--muted);font-size:10px}.status b{display:block;margin-top:3px}.ok{color:var(--green)}.bad{color:var(--red)}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.field label{display:block;color:var(--muted);font-size:11px;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em}.field input{width:100%;border:1px solid rgba(130,170,210,.25);background:#081523;color:var(--text);border-radius:10px;padding:10px 11px;outline:none}.field input:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(85,217,255,.09)}.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}.btn{border:1px solid rgba(85,217,255,.32);background:rgba(85,217,255,.10);color:#bff0ff;border-radius:10px;padding:10px 13px;text-decoration:none;cursor:pointer;font-weight:700}.btn.primary{background:linear-gradient(135deg,rgba(85,217,255,.22),rgba(81,227,164,.14));border-color:rgba(85,217,255,.55)}.btn.export{background:linear-gradient(135deg,rgba(81,227,164,.22),rgba(85,217,255,.12));border-color:rgba(81,227,164,.5);color:#aaffd9}.btn.danger{background:rgba(255,113,131,.11);border-color:rgba(255,113,131,.55);color:#ffd2d8}.alert{padding:11px 13px;border-radius:11px;margin-bottom:13px}.alert.error{border:1px solid rgba(255,113,131,.3);background:rgba(255,113,131,.08);color:#ffc1c9}.alert.success{border:1px solid rgba(81,227,164,.28);background:rgba(81,227,164,.08);color:#aaffd9}.note{color:var(--muted);font-size:12px;line-height:1.6}.note code{color:#c9efff;background:#06111d;padding:2px 5px;border-radius:5px}.danger-note{border:1px dashed rgba(255,113,131,.45);background:rgba(255,113,131,.06);color:#ffd2d8;border-radius:12px;padding:10px 12px;margin-top:12px}.preview-wrap{overflow:auto;max-height:610px;border:1px solid var(--line);border-radius:12px}.preview{width:100%;border-collapse:collapse;font-size:11px;white-space:nowrap}.preview th,.preview td{padding:7px 9px;border-bottom:1px solid rgba(130,170,210,.1);border-right:1px solid rgba(130,170,210,.07);text-align:left}.preview th{position:sticky;top:0;background:#10263d;color:#9de7ff;z-index:1}.preview td{color:#c4d5e4}.preview tr:hover td{background:rgba(85,217,255,.04)}.mini-table{width:100%;border-collapse:collapse}.mini-table th,.mini-table td{padding:9px;border-bottom:1px solid rgba(130,170,210,.1);text-align:left}.mini-table th{color:#9de7ff}.confirm-delete{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:14px}.confirm-delete input{min-width:180px;border:1px solid rgba(255,113,131,.35);background:#081523;color:var(--text);border-radius:10px;padding:10px 11px}@media(max-width:850px){.grid,.status-grid{grid-template-columns:repeat(2,1fr)}.top{display:block}.wrap{padding:12px}}@media(max-width:520px){.grid,.status-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="title">
      <h1>i-SIMS — Rawatan: Extract 20 Terkini &amp; Padam Rekod Salah</h1>
      <p>Paparkan 20 rekod rawatan terkini, cari ikut No Siri, kemudian padam rekod yang tersalah key-in dua kali.</p>
    </div>
    <a href="../index.php">← Dashboard</a>
  </div>

  <?php if ($error !== ''): ?><div class="alert error"><?= raw_e($error) ?></div><?php endif; ?>
  <?php if ($success !== ''): ?><div class="alert success"><?= raw_e($success) ?></div><?php endif; ?>

  <section class="card">
    <div class="status-grid">
      <div class="status"><span>PDO MySQL</span><b class="<?= $mysqlDriverAvailable ? 'ok' : 'bad' ?>"><?= $mysqlDriverAvailable ? 'AKTIF' : 'TIDAK AKTIF' ?></b></div>
      <div class="status"><span>Config i-SIMS</span><b class="<?= $configExists ? 'ok' : 'bad' ?>"><?= $configExists ? 'DIJUMPAI' : 'BELUM ADA' ?></b></div>
      <div class="status"><span>Database</span><b><?= raw_e($config['dbname']) ?></b></div>
      <div class="status"><span>Table</span><b><?= raw_e($config['table']) ?></b></div>
    </div>
    <p class="note">Menggunakan config <code>C:\xampp_baru\secure\isims_mysql_config.php</code>. Page ini dilindungi akses sama seperti modul extract i-SIMS lain.</p>
  </section>

  <form class="card" method="post" action="isims_rawatan_review.php">
    <input type="hidden" name="_csrf" value="<?= raw_e(zurie_security_csrf_token()) ?>">
    <div class="grid">
      <div class="field"><label>No Siri</label><input name="nosiri" value="<?= raw_e($input['nosiri']) ?>" inputmode="numeric" placeholder="Contoh: 12345"></div>
      <div class="field"><label>No Matrik</label><input name="nomatrik" value="<?= raw_e($input['nomatrik']) ?>" placeholder="MA/MS..."></div>
      <div class="field"><label>Nama</label><input name="nama" value="<?= raw_e($input['nama']) ?>" placeholder="Carian nama"></div>
      <div class="field"><label>Had Rekod</label><input name="limit" value="<?= (int)$input['limit'] ?>" type="number" min="1" max="200"></div>
    </div>
    <div class="actions">
      <button class="btn" type="submit" name="action" value="test">Test i-SIMS</button>
      <a class="btn primary" href="isims_rawatan_review.php?action=latest&amp;limit=20">Extract 20 Terkini</a>
      <button class="btn primary" type="submit" name="action" value="search">Search / Preview</button>
      <a class="btn export" href="isims_rawatan_review.php?action=csv&amp;nosiri=<?= raw_e($input['nosiri']) ?>&amp;nomatrik=<?= raw_e($input['nomatrik']) ?>&amp;nama=<?= raw_e($input['nama']) ?>&amp;limit=<?= (int)$input['limit'] ?>">Download CSV</a>
      <a class="btn" href="/zurie/pages/isims_sync.php">Sync i-SIMS</a>
    </div>
    <div class="danger-note">Aliran selamat: semak 20 data terkini atau cari No Siri → confirm rekod yang salah/double key-in → pilih rekod → taip <b>PADAM</b>.</div>
  </form>

  <?php if ($duplicates): ?>
  <section class="card">
    <h2 style="margin-top:0;font-size:15px">No Siri yang mempunyai lebih daripada satu rekod</h2>
    <div class="preview-wrap"><table class="mini-table">
      <thead><tr><th>No Siri</th><th>Jumlah</th><th>Tarikh Awal</th><th>Tarikh Akhir</th><th>Tindakan</th></tr></thead>
      <tbody>
      <?php foreach ($duplicates as $dup): ?>
        <tr>
          <td><?= raw_e($dup['nosiri']) ?></td>
          <td><?= raw_e($dup['jumlah']) ?></td>
          <td><?= raw_e($dup['tarikh_awal']) ?></td>
          <td><?= raw_e($dup['tarikh_akhir']) ?></td>
          <td><a class="btn" href="isims_rawatan_review.php?nosiri=<?= raw_e($dup['nosiri']) ?>">Lihat rekod</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <?php endif; ?>

  <?php if ($rows): ?>
  <form class="card" method="post" action="isims_rawatan_review.php" onsubmit="return confirm('Padam rekod rawatan yang dipilih? Pastikan CSV/preview telah disemak.');">
    <input type="hidden" name="_csrf" value="<?= raw_e(zurie_security_csrf_token()) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="nosiri" value="<?= raw_e($input['nosiri']) ?>">
    <input type="hidden" name="nomatrik" value="<?= raw_e($input['nomatrik']) ?>">
    <input type="hidden" name="nama" value="<?= raw_e($input['nama']) ?>">
    <input type="hidden" name="limit" value="<?= (int)$input['limit'] ?>">
    <h2 style="margin-top:0;font-size:15px">Senarai Rekod Rawatan — <?= count($rows) ?> rekod</h2>
    <div class="preview-wrap"><table class="preview">
      <thead><tr><th>Pilih</th><?php foreach (raw_columns() as $column): ?><th><?= raw_e($column) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><input type="checkbox" name="row_token[]" value="<?= raw_e(raw_token($row)) ?>"></td>
          <?php foreach (raw_columns() as $column): ?><td><?= raw_e($row[$column] ?? '') ?></td><?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="confirm-delete">
      <div class="field"><label>Taip PADAM untuk sahkan</label><input name="confirm_delete" placeholder="PADAM"></div>
      <button class="btn danger" type="submit">Padam Rekod Dipilih</button>
    </div>
    <p class="note">Disebabkan table rawatan tiada primary key, sistem memadam berdasarkan padanan penuh semua column dan <code>LIMIT 1</code> untuk setiap rekod dipilih.</p>
  </form>
  <?php endif; ?>
</div>
</body>
</html>

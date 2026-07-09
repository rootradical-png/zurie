<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/portal_auth.php';
zurie_portal_require_extract_access();
require_once dirname(__DIR__) . '/lib/pg_runtime_auth.php';
zurie_pg_runtime_gate('ilmu_pg_gl14', 'ILMU GL14 Export');

// Personal NOC Dashboard - ILMU GL14 PostgreSQL -> CSV Export
// Fail: /zurie/pages/ilmu_export.php

function ilmu_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ilmu_prefix($value, string $fallback): string {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    return $value !== '' ? $value : $fallback;
}

function ilmu_date8($value, string $fallback): string {
    $value = preg_replace('/[^0-9]/', '', (string)$value) ?? '';
    if (!preg_match('/^[0-9]{8}$/', $value)) {
        return $fallback;
    }
    $year = (int)substr($value, 0, 4);
    $month = (int)substr($value, 4, 2);
    $day = (int)substr($value, 6, 2);
    return checkdate($month, $day, $year) ? $value : $fallback;
}

function ilmu_inputs(array $source): array {
    $limit = isset($source['limit']) ? (int)$source['limit'] : 1300;
    $limit = max(1, min(5000, $limit));

    return [
        'matrik1' => ilmu_prefix($source['matrik1'] ?? 'MA26', 'MA26'),
        'matrik2' => ilmu_prefix($source['matrik2'] ?? 'MS26', 'MS26'),
        'nokp1' => ilmu_prefix($source['nokp1'] ?? '07', '07'),
        'nokp2' => ilmu_prefix($source['nokp2'] ?? '08', '08'),
        'nokp3' => ilmu_prefix($source['nokp3'] ?? '09', '09'),
        'tarikh_mula' => ilmu_date8($source['tarikh_mula'] ?? '20260701', '20260701'),
        'tarikh_tamat' => ilmu_date8($source['tarikh_tamat'] ?? '20270701', '20270701'),
        'limit' => $limit,
        'bom' => isset($source['bom']) && (string)$source['bom'] === '1',
    ];
}

function ilmu_config(): array
{
    return zurie_pg_runtime_config('ilmu_pg_gl14');
}

function ilmu_config_ready(array $config): bool {
    return $config['host'] !== '' && $config['dbname'] !== '' && $config['user'] !== '';
}

function ilmu_connect(array $config): PDO {
    if (!class_exists('PDO') || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO PostgreSQL belum aktif dalam PHP. Aktifkan extension=pdo_pgsql dan restart Apache.');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        $config['host'],
        $config['port'],
        $config['dbname'],
        $config['sslmode'] !== '' ? $config['sslmode'] : 'prefer'
    );

    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function ilmu_sql(bool $withLimit = true): string {
    $sql = <<<'SQL'
WITH param AS (
    SELECT
        CAST(:matrik1 AS text) AS matrik1,
        CAST(:matrik2 AS text) AS matrik2,
        CAST(:nokp1 AS text) AS nokp1,
        CAST(:nokp2 AS text) AS nokp2,
        CAST(:nokp3 AS text) AS nokp3,
        CAST(:tarikh_mula AS text) AS tarikh_mula,
        CAST(:tarikh_tamat AS text) AS tarikh_tamat
)
SELECT
    p.nomatrik AS "GL14PATR",
    'x' AS "GL14PASW",
    '07' AS "GL14GRID",
    p.nama AS "GL14NAME",
    '' AS "GL14ABBR",
    '' AS "GL14OLDIC",
    REGEXP_REPLACE(p.nokp, '[^0-9]', '', 'g') AS "GL14NEWIC",
    '03' AS "GL14CATE",
    '' AS "GL14DEPT",

    CASE
        WHEN p.nomatrik ILIKE param.matrik2 || '%' THEN 'M001'
        WHEN p.nomatrik ILIKE param.matrik1 || '%' THEN 'M003'
        ELSE ''
    END AS "GL14COURSE",

    '01' AS "GL14STAT",
    '' AS "GL14RELID",
    COALESCE(p.alamat1, '') AS "GL14ADD1",
    COALESCE(p.alamat2, '') AS "GL14ADD2",
    COALESCE(p.bandar, '') AS "GL14ADD3",
    COALESCE(p.poskod, '') AS "GL14CODE",
    COALESCE(p.negeri, '') AS "GL14TOWN",
    REGEXP_REPLACE(COALESCE(p.nohp, ''), '[^0-9]', '', 'g') AS "GL14HTEL",
    REGEXP_REPLACE(COALESCE(p.nohp, ''), '[^0-9]', '', 'g') AS "GL14OTEL",
    '' AS "GL14FAX",
    '' AS "GL14IPADD",

    CASE
        WHEN p.tlahir ~ '^[0-9]{8}$'
        THEN SUBSTRING(p.tlahir FROM 5 FOR 4) ||
             SUBSTRING(p.tlahir FROM 3 FOR 2) ||
             SUBSTRING(p.tlahir FROM 1 FOR 2)
        ELSE ''
    END AS "GL14DOB",

    COALESCE(p.jantina, '') AS "GL14SEX",
    '' AS "GL14RACE",
    '' AS "GL14DESC",
    param.tarikh_mula AS "GL14MEMDATE",
    param.tarikh_tamat AS "GL14EXPDATE",
    '' AS "GL14MEMFEE",
    '$0.00' AS "GL14DEPOSIT",
    '' AS "GL14RECEIPT",
    '' AS "GL14IMAGE",
    '$0.00' AS "GL14FINEOUT",
    '0' AS "GL14FINECOL",
    '0' AS "GL14LOSTBOK",
    '0' AS "GL14SUSPEND",
    '0' AS "GL14BORDATE",
    '5' AS "GL14BORYEAR",
    '5' AS "GL14LTDATE",
    '0' AS "GL14LTYEAR",
    '20080328' AS "GL14LASTBOR",
    '0' AS "GL14LASTRET",
    '' AS "GL14LOGIN",
    '' AS "GL14REMARK",
    '' AS "GL14USID",
    '' AS "GL14DUEF",
    '' AS "GL14COLOR",
    '' AS "GL14RELIGION",
    '' AS "GL14EMPLOYEE",
    '' AS "GL14DATEJOIN",
    '' AS "GL14STAFFLEVEL",
    '' AS "GL14REGISTER",
    '' AS "GL14SUPERVISOR",
    '' AS "GL14DATEREC",
    '' AS "GL14RECBY",
    '' AS "GL14LASTDATE",
    '' AS "GL14LOCA",
    '' AS "GL14OFFADD1",
    '' AS "GL14OFFADD2",
    '' AS "GL14OFFADD3",
    '' AS "GL14NAMETITLE",
    '' AS "GL14MAILFLAG",
    '' AS "GL14OFFCODE",
    '' AS "GL14OFFTOWN",
    '' AS "GL14BPRINT",
    '' AS "GL14ADD21",
    '' AS "GL14ADD22",
    '' AS "GL14ADD23",
    '' AS "GL14CODE2",
    '' AS "GL14TOWN2",
    '' AS "GL14HTEL2",
    '' AS "GL14HTELX",
    '' AS "GL14SECURE",
    '' AS "GL14PBAR",
    '' AS "GL14SNOTICE",
    '' AS "GL14PARENTID"

FROM public.personal p
CROSS JOIN param
INNER JOIN public.status_pendaftaran sp
    ON sp.status_kp = p.nokp
INNER JOIN public.jurusan_pelajar jp
    ON jp.jp_nokp = p.nokp
LEFT JOIN public.tutoran t
    ON t.tutoran_id = jp.jp_tutoran

WHERE sp.status_daftar = '1'
  AND (
        p.nomatrik ILIKE param.matrik1 || '%'
        OR p.nomatrik ILIKE param.matrik2 || '%'
      )
  AND (
        p.nokp LIKE param.nokp1 || '%'
        OR p.nokp LIKE param.nokp2 || '%'
        OR p.nokp LIKE param.nokp3 || '%'
      )
  AND t.tutoran_nama IS NOT NULL
ORDER BY p.nomatrik
SQL;

    return $withLimit ? $sql . "\nLIMIT :row_limit" : $sql;
}

function ilmu_bind(PDOStatement $stmt, array $input, bool $withLimit = true): void {
    $stmt->bindValue(':matrik1', $input['matrik1'], PDO::PARAM_STR);
    $stmt->bindValue(':matrik2', $input['matrik2'], PDO::PARAM_STR);
    $stmt->bindValue(':nokp1', $input['nokp1'], PDO::PARAM_STR);
    $stmt->bindValue(':nokp2', $input['nokp2'], PDO::PARAM_STR);
    $stmt->bindValue(':nokp3', $input['nokp3'], PDO::PARAM_STR);
    $stmt->bindValue(':tarikh_mula', $input['tarikh_mula'], PDO::PARAM_STR);
    $stmt->bindValue(':tarikh_tamat', $input['tarikh_tamat'], PDO::PARAM_STR);
    if ($withLimit) {
        $stmt->bindValue(':row_limit', $input['limit'], PDO::PARAM_INT);
    }
}

function ilmu_headers(string $sql): array {
    preg_match_all('/\sAS\s+"([^"]+)"/i', $sql, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

function ilmu_filename(array $input): string {
    return sprintf(
        'ILMU_GL14_%s_%s_%s_%s.csv',
        $input['matrik1'],
        $input['matrik2'],
        $input['tarikh_mula'],
        $input['tarikh_tamat']
    );
}

$input = ilmu_inputs($_POST ?: $_GET);
$config = ilmu_config();
$action = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? (string)($_POST['action'] ?? '') : '';
if ($action !== '') { zurie_security_require_valid_csrf(); }
$error = '';
$success = '';
$previewRows = [];
$previewCount = null;

if ($action === 'export') {
    try {
        if (!ilmu_config_ready($config)) {
            throw new RuntimeException('Konfigurasi PostgreSQL belum lengkap. Isi fail /zurie/config/ilmu_pg_config.php dahulu.');
        }

        $pdo = ilmu_connect($config);
        $stmt = $pdo->prepare(ilmu_sql(true));
        ilmu_bind($stmt, $input, true);
        $stmt->execute();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . ilmu_filename($input) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new RuntimeException('Tidak dapat membuka output CSV.');
        }

        if ($input['bom']) {
            fwrite($out, "\xEF\xBB\xBF");
        }

        $headers = ilmu_headers(ilmu_sql(false));
        fputcsv($out, zurie_security_csv_row($headers), ',', '"', '\\', "\r\n");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ordered = [];
            foreach ($headers as $header) {
                $ordered[] = $row[$header] ?? '';
            }
            fputcsv($out, zurie_security_csv_row($ordered), ',', '"', '\\', "\r\n");
        }

        fclose($out);
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($action === 'preview' || $action === 'test') {
    try {
        if (!ilmu_config_ready($config)) {
            throw new RuntimeException('Konfigurasi PostgreSQL belum lengkap. Isi fail /zurie/config/ilmu_pg_config.php dahulu.');
        }

        $pdo = ilmu_connect($config);

        if ($action === 'test') {
            $pdo->query('SELECT 1');
            $success = 'Sambungan PostgreSQL berjaya.';
        } else {
            $countSql = 'SELECT COUNT(*) FROM (' . ilmu_sql(false) . ') AS ilmu_export_count';
            $countStmt = $pdo->prepare($countSql);
            ilmu_bind($countStmt, $input, false);
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();
            $previewCount = min($total, $input['limit']);

            $previewSql = ilmu_sql(false) . "\nLIMIT 20";
            $previewStmt = $pdo->prepare($previewSql);
            ilmu_bind($previewStmt, $input, false);
            $previewStmt->execute();
            $previewRows = $previewStmt->fetchAll(PDO::FETCH_ASSOC);
            $success = 'Query berjaya. ' . $previewCount . ' rekod akan dieksport berdasarkan limit semasa.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$pgsqlDriverAvailable = class_exists('PDO') && in_array('pgsql', PDO::getAvailableDrivers(), true);
$configExists = is_file($config['config_path']);
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ILMU GL14 CSV Export</title>
<link rel="icon" href="/zurie/image/zuriex.jpg">
<style>
:root{--bg:#07111f;--card:#0d1c2e;--line:rgba(130,170,210,.18);--text:#eaf4ff;--muted:#86a0b8;--cyan:#55d9ff;--green:#51e3a4;--yellow:#ffd36c;--red:#ff7183}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top left,#123456 0,#07111f 36%,#040b14 100%);color:var(--text);font-family:Segoe UI,Arial,sans-serif;font-size:13px}.wrap{max-width:1280px;margin:0 auto;padding:18px}.top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px}.top a{color:#9ddfff;text-decoration:none}.title h1{margin:0;font-size:22px}.title p{margin:4px 0 0;color:var(--muted)}.card{background:linear-gradient(145deg,rgba(13,28,46,.96),rgba(8,18,31,.96));border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.25);padding:16px;margin-bottom:14px}.status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.status{padding:10px;border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.02)}.status span{display:block;color:var(--muted);font-size:10px}.status b{display:block;margin-top:3px}.ok{color:var(--green)}.bad{color:var(--red)}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.field label{display:block;color:var(--muted);font-size:11px;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em}.field input{width:100%;border:1px solid rgba(130,170,210,.25);background:#081523;color:var(--text);border-radius:10px;padding:10px 11px;outline:none}.field input:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(85,217,255,.09)}.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}.btn{border:1px solid rgba(85,217,255,.32);background:rgba(85,217,255,.10);color:#bff0ff;border-radius:10px;padding:10px 13px;text-decoration:none;cursor:pointer;font-weight:700}.btn.primary{background:linear-gradient(135deg,rgba(85,217,255,.22),rgba(81,227,164,.14));border-color:rgba(85,217,255,.55)}.btn.export{background:linear-gradient(135deg,rgba(81,227,164,.22),rgba(85,217,255,.12));border-color:rgba(81,227,164,.5);color:#aaffd9}.alert{padding:11px 13px;border-radius:11px;margin-bottom:13px}.alert.error{border:1px solid rgba(255,113,131,.3);background:rgba(255,113,131,.08);color:#ffc1c9}.alert.success{border:1px solid rgba(81,227,164,.28);background:rgba(81,227,164,.08);color:#aaffd9}.setup{color:var(--muted);font-size:12px;line-height:1.6}.setup code{color:#c9efff;background:#06111d;padding:2px 5px;border-radius:5px}.preview-wrap{overflow:auto;max-height:520px;border:1px solid var(--line);border-radius:12px}.preview{width:100%;border-collapse:collapse;font-size:11px;white-space:nowrap}.preview th,.preview td{padding:7px 9px;border-bottom:1px solid rgba(130,170,210,.1);border-right:1px solid rgba(130,170,210,.07);text-align:left}.preview th{position:sticky;top:0;background:#10263d;color:#9de7ff;z-index:1}.preview td{color:#c4d5e4}.check{display:flex;align-items:center;gap:7px;color:var(--muted);font-size:12px}.check input{accent-color:#51e3a4}@media(max-width:850px){.grid{grid-template-columns:repeat(2,1fr)}.status-grid{grid-template-columns:1fr}.top{display:block}.wrap{padding:12px}}@media(max-width:480px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="title"><h1>ILMU GL14 CSV Export</h1><p>Jalankan query terus dari PostgreSQL dan download hasil sebagai fail CSV.</p></div>
    <a href="../index.php">← Dashboard</a>
  </div>

  <?php if ($error !== ''): ?><div class="alert error"><?= ilmu_e($error) ?></div><?php endif; ?>
  <?php if ($success !== ''): ?><div class="alert success"><?= ilmu_e($success) ?></div><?php endif; ?>

  <section class="card">
    <div class="status-grid">
      <div class="status"><span>PHP PDO PostgreSQL</span><b class="<?= $pgsqlDriverAvailable ? 'ok' : 'bad' ?>"><?= $pgsqlDriverAvailable ? 'AKTIF' : 'TIDAK AKTIF' ?></b></div>
      <div class="status"><span>Fail konfigurasi</span><b class="<?= $configExists ? 'ok' : 'bad' ?>"><?= $configExists ? 'DIJUMPAI' : 'BELUM ADA' ?></b></div>
      <div class="status"><span>Database sasaran</span><b><?= ilmu_e($config['host'] !== '' ? $config['host'] . ' / ' . $config['dbname'] : 'Belum dikonfigurasi') ?></b></div>
    </div>
    <?php if (!$configExists): ?>
    <div class="setup">
      Salin <code>/zurie/config/ilmu_pg_config.php.example</code> menjadi
      <code>/zurie/config/ilmu_pg_config.php</code>, kemudian isi host dan nama database sahaja. ID dan kata laluan dimasukkan melalui borang sambungan sementara.
    </div>
    <?php endif; ?>
  </section>

  <form class="card" method="post" action="ilmu_export.php">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(zurie_security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <div class="grid">
      <div class="field"><label>Prefix Matrik 1</label><input name="matrik1" value="<?= ilmu_e($input['matrik1']) ?>" maxlength="6"></div>
      <div class="field"><label>Prefix Matrik 2</label><input name="matrik2" value="<?= ilmu_e($input['matrik2']) ?>" maxlength="6"></div>
      <div class="field"><label>No KP Prefix 1</label><input name="nokp1" value="<?= ilmu_e($input['nokp1']) ?>" maxlength="4"></div>
      <div class="field"><label>No KP Prefix 2</label><input name="nokp2" value="<?= ilmu_e($input['nokp2']) ?>" maxlength="4"></div>
      <div class="field"><label>No KP Prefix 3</label><input name="nokp3" value="<?= ilmu_e($input['nokp3']) ?>" maxlength="4"></div>
      <div class="field"><label>Tarikh Start GL14MEMDATE</label><input name="tarikh_mula" value="<?= ilmu_e($input['tarikh_mula']) ?>" maxlength="8" placeholder="YYYYMMDD"></div>
      <div class="field"><label>Tarikh Tamat GL14EXPDATE</label><input name="tarikh_tamat" value="<?= ilmu_e($input['tarikh_tamat']) ?>" maxlength="8" placeholder="YYYYMMDD"></div>
      <div class="field"><label>Had Rekod</label><input name="limit" value="<?= (int)$input['limit'] ?>" type="number" min="1" max="5000"></div>
    </div>

    <div class="actions">
      <button class="btn" type="submit" name="action" value="test">Test Connection</button>
      <button class="btn primary" type="submit" name="action" value="preview">Semak & Preview</button>
      <button class="btn export" type="submit" name="action" value="export">Download CSV</button>
      <label class="check"><input type="checkbox" name="bom" value="1" <?= $input['bom'] ? 'checked' : '' ?>> UTF-8 BOM untuk Excel</label>
    </div>
  </form>

  <?php if ($previewCount !== null): ?>
  <section class="card">
    <h2 style="margin-top:0;font-size:15px">Preview 20 rekod pertama — <?= (int)$previewCount ?> rekod akan dieksport</h2>
    <?php if ($previewRows): ?>
    <div class="preview-wrap"><table class="preview">
      <thead><tr><?php foreach (array_keys($previewRows[0]) as $column): ?><th><?= ilmu_e($column) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($previewRows as $row): ?><tr><?php foreach ($row as $value): ?><td><?= ilmu_e($value) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?><p class="setup">Tiada rekod sepadan dengan tapisan.</p><?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php zurie_pg_runtime_widget('ilmu_pg_gl14'); ?>
</body>
</html>

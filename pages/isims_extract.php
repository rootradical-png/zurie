<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/portal_auth.php';
zurie_portal_require_extract_access();
require_once dirname(__DIR__) . '/lib/pg_runtime_auth.php';
zurie_pg_runtime_gate('isims_mis_lengkap', 'i-SIMS Senarai MIS Lengkap');

// Personal NOC Dashboard - i-SIMS Senarai MIS Lengkap PostgreSQL -> CSV
// Guna konfigurasi PostgreSQL yang sama dengan modul ILMU:
// /zurie/config/ilmu_pg_config.php

function sml_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sml_prefix($value, string $fallback): string
{
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    return $value !== '' ? $value : $fallback;
}

function sml_inputs(array $source): array
{
    $limit = isset($source['limit']) ? (int)$source['limit'] : 1500;
    $limit = max(1, min(5000, $limit));

    return [
        'matrik1' => sml_prefix($source['matrik1'] ?? 'MA26', 'MA26'),
        'matrik2' => sml_prefix($source['matrik2'] ?? 'MS26', 'MS26'),
        'nokp1' => sml_prefix($source['nokp1'] ?? '07', '07'),
        'nokp2' => sml_prefix($source['nokp2'] ?? '08', '08'),
        'nokp3' => sml_prefix($source['nokp3'] ?? '09', '09'),
        'limit' => $limit,
        'bom' => isset($source['bom']) && (string)$source['bom'] === '1',
    ];
}

function sml_config(): array
{
    return zurie_pg_runtime_config('isims_mis_lengkap');
}

function sml_config_ready(array $config): bool
{
    return $config['host'] !== '' && $config['dbname'] !== '' && $config['user'] !== '';
}

function sml_connect(array $config): PDO
{
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

function sml_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.columns " .
        "WHERE table_schema = 'public' AND table_name = :table_name " .
        "AND column_name = :column_name LIMIT 1"
    );
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    return (bool)$stmt->fetchColumn();
}

function sml_income_expression(PDO $pdo): string
{
    return 'personal.dapatan';
}

function sml_sql(bool $withLimit = true, string $incomeExpression = 'personal.dapatan'): string
{
    $sql = <<<'SQL'
SELECT
    personal.nama AS "nama",
    personal.agilir AS "spm",
    personal.nokp AS "nokp",
    personal.nomatrik AS "nomatrik",
    personal.kaumc AS "kaum",
    personal.warga AS "warganegara",
    personal.jantina AS "jantina",
    personal.agama AS "agama",
    personal.cacat AS "kecatatan",
    personal.alamat1 AS "alamat1",
    personal.alamat2 AS "alamat2",
    personal.bandar AS "bandar",
    personal.poskod AS "poskod",
    personal.negeri AS "negeri",
    personal.notel AS "notelefon",
    personal.nohp AS "notelefonbimbit",
    personal.kategori AS "kategoripermohonan",
    personal.tlahir AS "tarikhlahir",
    personal.kaumi AS "kaumibu",
    personal.kaumb AS "kaumbapa",
    __INCOME_EXPRESSION__ AS "pendapatankeluarga",
    personal.tanggung AS "tanggungan",
    personal.kursus AS "kursus",
    personal.nolembaga AS "nolembaga",
    personal.meritall AS "nomerit",
    personal.markah_a AS "markahakademik",
    personal.markah_b AS "markahkhas",
    personal.markah_all AS "markahseluruh",
    personal.kolejtawar AS "kolejtawar",
    personal.norujuk AS "norujukan",

    CASE
        WHEN b.blok_nama IS NOT NULL THEN
            CONCAT(
                b.blok_nama,
                'T',
                CASE WHEN k.ktl_aras = '0' THEN 'G' ELSE k.ktl_aras END,
                '.',
                k.ktl_bilik
            )
        ELSE ''
    END AS "asrama",

    kl.kuliah_nama AS "kuliah",
    pr.praktikum_nama AS "praktikum",
    t.tutoran_nama AS "tutoran",
    e.english_nama AS "english",
    '' AS "moral",
    kk.koko_nama AS "kokurikulum",
    jurusan_pelajar.jp_jurusan AS "jurusan",
    ldk AS "kumpulanldk",
    mt.mentor_group AS "kumpulanmentor",
    st.staf_name AS "Nama Mentor",
    'AKTIF' AS "StatusPelajar",
    stud_tarikh_status AS "Tarikh Status",
    '' AS "No. Resit",
    no_bdmo AS "No BDMO",
    stud_no_akaun AS "No Akaun",
    stud_yuran AS "Nilai Yuran",
    '' AS "Nilai Yuran Semester II",
    tar_daftar AS "Tarikh Daftar",
    stud_sekolah AS "Sekolah Asal",
    stud_intake AS "Pengambilan",
    stud_blok AS "Pra Penempatan",
    stud_sesi AS "Sesi Pendaftaran",
    stud_tarikh AS "Tarikh Pendaftaran",
    stud_bumi AS "Status Bumiputra",
    '' AS "Jumlah Gaji Ibu/Bapa",
    '' AS "Mata Kelab/Persatuan",
    '' AS "Mata Sukan/Permainan",
    '' AS "Mata Badan Beruniform",
    '' AS "Mata PLKN",
    '' AS "Purata Skor",
    '' AS "PNGK Semester I",
    '' AS "PNGK Semester II",
    '' AS "Status Semester I",
    '' AS "Status Semester II",
    '' AS "waris1",
    '' AS "waris2",
    '' AS "bapa",
    '' AS "ibu"

FROM public.personal
INNER JOIN public.status_pendaftaran
    ON status_pendaftaran.status_kp = personal.nokp
INNER JOIN public.pelajar
    ON public.pelajar.stud_kp = personal.nokp
INNER JOIN public.jurusan_pelajar
    ON jurusan_pelajar.jp_nokp = personal.nokp
LEFT JOIN public.asrama a
    ON a.asr_profileid = personal.profileid
LEFT JOIN public.katil k
    ON k.ktl_id = a.asr_katil
LEFT JOIN public.blok b
    ON b.blok_id = k.ktl_blok
LEFT JOIN public.tutoran t
    ON t.tutoran_id = jurusan_pelajar.jp_tutoran
LEFT JOIN public.kuliah kl
    ON kl.kuliah_id = t.tutoran_kuliah
LEFT JOIN public.praktikum pr
    ON pr.praktikum_id = t.tutoran_praktikum
LEFT JOIN public.english e
    ON e.english_id = jurusan_pelajar.jp_english
LEFT JOIN public.koko kk
    ON kk.koko_id = jurusan_pelajar.jp_koko
LEFT JOIN public.mentor_senarai mt
    ON mt.group_id = jurusan_pelajar.jp_mentor
LEFT JOIN public.staff_profile st
    ON st.staf_id = mt.mentor_id

WHERE status_pendaftaran.status_daftar = '1'
  AND (
        personal.nomatrik ILIKE :matrik1_pattern
        OR personal.nomatrik ILIKE :matrik2_pattern
      )
  AND (
        personal.nokp LIKE :nokp1_pattern
        OR personal.nokp LIKE :nokp2_pattern
        OR personal.nokp LIKE :nokp3_pattern
      )
  AND t.tutoran_nama IS NOT NULL
ORDER BY personal.nomatrik
SQL;

    $sql = str_replace('__INCOME_EXPRESSION__', $incomeExpression, $sql);
    return $withLimit ? $sql . "\nLIMIT :row_limit" : $sql;
}

function sml_bind(PDOStatement $stmt, array $input, bool $withLimit = true): void
{
    $stmt->bindValue(':matrik1_pattern', $input['matrik1'] . '%', PDO::PARAM_STR);
    $stmt->bindValue(':matrik2_pattern', $input['matrik2'] . '%', PDO::PARAM_STR);
    $stmt->bindValue(':nokp1_pattern', $input['nokp1'] . '%', PDO::PARAM_STR);
    $stmt->bindValue(':nokp2_pattern', $input['nokp2'] . '%', PDO::PARAM_STR);
    $stmt->bindValue(':nokp3_pattern', $input['nokp3'] . '%', PDO::PARAM_STR);

    if ($withLimit) {
        $stmt->bindValue(':row_limit', $input['limit'], PDO::PARAM_INT);
    }
}

function sml_headers(string $sql): array
{
    preg_match_all('/\sAS\s+"([^"]+)"/i', $sql, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

function sml_filename(array $input): string
{
    return sprintf(
        'SENARAI_MIS_LENGKAP_%s_%s_%s.csv',
        $input['matrik1'],
        $input['matrik2'],
        date('Ymd_His')
    );
}

function sml_isims_config(): array
{
    $configFile = 'C:/xampp_baru/secure/isims_mysql_config.php';
    $config = is_file($configFile) ? require $configFile : [];

    return [
        'config_path' => $configFile,
        'enabled' => (bool)($config['enabled'] ?? false),
        'host' => trim((string)($config['host'] ?? '')),
        'port' => (int)($config['port'] ?? 3306),
        'dbname' => trim((string)($config['dbname'] ?? 'db_pelajarkmp')),
        'user' => trim((string)($config['user'] ?? '')),
        'password' => (string)($config['password'] ?? ''),
        'charset' => trim((string)($config['charset'] ?? 'utf8')) ?: 'utf8',
        'table' => 'senarai_mis_lengkap',
        'timeout' => max(2, min(30, (int)($config['timeout'] ?? 8))),
    ];
}

function sml_isims_config_ready(array $config): bool
{
    return $config['enabled']
        && $config['host'] !== ''
        && $config['dbname'] !== ''
        && $config['user'] !== ''
        && $config['table'] !== '';
}

function sml_isims_identifier(string $value, string $label): string
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        throw new RuntimeException($label . ' tidak sah. Hanya huruf, nombor dan underscore dibenarkan.');
    }
    return '`' . str_replace('`', '``', $value) . '`';
}

function sml_isims_column_identifier(string $value): string
{
    if ($value === '') {
        throw new RuntimeException('Nama column kosong.');
    }
    return '`' . str_replace('`', '``', $value) . '`';
}

function sml_isims_connect(array $config): PDO
{
    if (!sml_isims_config_ready($config)) {
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

function sml_normalize_name(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '_', $value) ?? '';
    $value = trim($value, '_');
    return $value;
}

function sml_isims_table_columns(PDO $pdo, array $config): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = :schema_name AND TABLE_NAME = :table_name '
        . 'ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([
        ':schema_name' => $config['dbname'],
        ':table_name' => $config['table'],
    ]);

    $columns = [];
    while (($name = $stmt->fetchColumn()) !== false) {
        $name = (string)$name;
        $columns[sml_normalize_name($name)] = $name;
    }
    if (!$columns) {
        throw new RuntimeException('Table ' . $config['dbname'] . '.' . $config['table'] . ' tidak ditemui atau akaun tiada akses.');
    }
    return $columns;
}

function sml_isims_target_info(PDO $pdo, array $config): array
{
    $columns = sml_isims_table_columns($pdo, $config);
    $keyColumn = $columns['nomatrik'] ?? $columns['matrik'] ?? null;
    if ($keyColumn === null) {
        throw new RuntimeException('Table ' . $config['dbname'] . '.' . $config['table'] . ' tidak mempunyai column nomatrik atau matrik.');
    }

    $dbName = sml_isims_identifier($config['dbname'], 'Nama database');
    $tableName = sml_isims_identifier($config['table'], 'Nama table');
    $keyQuoted = sml_isims_column_identifier($keyColumn);
    $pdo->query('SELECT ' . $keyQuoted . ' FROM ' . $dbName . '.' . $tableName . ' LIMIT 1');

    return [
        'table' => $config['table'],
        'columns' => count($columns),
        'key' => $keyColumn,
    ];
}

function sml_fetch_source_rows(PDO $pgsql, array $input): array
{
    $incomeExpression = sml_income_expression($pgsql);
    $stmt = $pgsql->prepare(sml_sql(true, $incomeExpression));
    sml_bind($stmt, $input, true);
    $stmt->execute();

    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (trim((string)($row['nomatrik'] ?? '')) !== '') {
            $rows[] = $row;
        }
    }
    return $rows;
}

function sml_sync_rows_to_isims(PDO $pgsql, PDO $isims, array $input, array $config): array
{
    $columns = sml_isims_table_columns($isims, $config);
    $keyTarget = $columns['nomatrik'] ?? $columns['matrik'] ?? null;
    if ($keyTarget === null) {
        throw new RuntimeException('Table ' . $config['dbname'] . '.' . $config['table'] . ' mesti mempunyai column nomatrik atau matrik.');
    }

    $rows = sml_fetch_source_rows($pgsql, $input);
    if (!$rows) {
        return [
            'table' => $config['table'],
            'source' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'mapped_columns' => [],
        ];
    }

    $sourceHeaders = array_keys($rows[0]);
    $fieldMap = [];
    foreach ($sourceHeaders as $sourceHeader) {
        $normalized = sml_normalize_name((string)$sourceHeader);
        if ($normalized === '') {
            continue;
        }

        if ($normalized === 'nomatrik' && isset($columns['matrik']) && !isset($columns['nomatrik'])) {
            $fieldMap[$sourceHeader] = $columns['matrik'];
            continue;
        }

        if (isset($columns[$normalized])) {
            $fieldMap[$sourceHeader] = $columns[$normalized];
        }
    }

    if (!$fieldMap) {
        throw new RuntimeException('Tiada column sepadan antara output MIS Lengkap dan table i-SIMS.');
    }

    $hasKey = false;
    foreach ($fieldMap as $sourceHeader => $targetColumn) {
        if ($targetColumn === $keyTarget) {
            $hasKey = true;
            break;
        }
    }
    if (!$hasKey) {
        if (isset($rows[0]['nomatrik'])) {
            $fieldMap['nomatrik'] = $keyTarget;
        } else {
            throw new RuntimeException('Output MIS Lengkap tidak mempunyai nomatrik untuk dijadikan kunci sync.');
        }
    }

    $dbName = sml_isims_identifier($config['dbname'], 'Nama database');
    $tableName = sml_isims_identifier($config['table'], 'Nama table');
    $qualified = $dbName . '.' . $tableName;
    $keyQuoted = sml_isims_column_identifier($keyTarget);

    $existsStmt = $isims->prepare('SELECT 1 FROM ' . $qualified . ' WHERE ' . $keyQuoted . ' = :key_value LIMIT 1');

    $insertColumns = [];
    $insertParams = [];
    foreach ($fieldMap as $source => $target) {
        $paramName = ':f' . count($insertParams);
        $insertColumns[] = sml_isims_column_identifier($target);
        $insertParams[] = $paramName;
    }
    $insertSql = 'INSERT INTO ' . $qualified
        . ' (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertParams) . ')';
    $insertStmt = $isims->prepare($insertSql);

    $updateParts = [];
    $updateParamNames = [];
    foreach ($fieldMap as $source => $target) {
        if ($target === $keyTarget) {
            continue;
        }
        $paramName = ':u' . count($updateParamNames);
        $updateParts[] = sml_isims_column_identifier($target) . ' = ' . $paramName;
        $updateParamNames[$source] = $paramName;
    }
    $updateStmt = $updateParts
        ? $isims->prepare('UPDATE ' . $qualified . ' SET ' . implode(', ', $updateParts) . ' WHERE ' . $keyQuoted . ' = :_key')
        : null;

    $result = [
        'table' => $config['table'],
        'source' => count($rows),
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'mapped_columns' => array_values($fieldMap),
    ];

    $isims->beginTransaction();
    try {
        foreach ($rows as $row) {
            $keyValue = trim((string)($row['nomatrik'] ?? $row['matrik'] ?? ''));
            if ($keyValue === '') {
                $result['skipped']++;
                continue;
            }

            $existsStmt->execute([':key_value' => $keyValue]);
            $exists = (bool)$existsStmt->fetchColumn();

            if ($exists) {
                if ($updateStmt === null) {
                    $result['skipped']++;
                    continue;
                }
                $params = [];
                foreach ($updateParamNames as $source => $paramName) {
                    $params[$paramName] = $row[$source] ?? '';
                }
                $params[':_key'] = $keyValue;
                $updateStmt->execute($params);
                $result['updated']++;
            } else {
                $params = [];
                $idx = 0;
                foreach ($fieldMap as $source => $target) {
                    $params[':f' . $idx] = ($target === $keyTarget) ? $keyValue : ($row[$source] ?? '');
                    $idx++;
                }
                $insertStmt->execute($params);
                $result['inserted']++;
            }
        }
        $isims->commit();
    } catch (Throwable $e) {
        if ($isims->inTransaction()) {
            $isims->rollBack();
        }
        throw $e;
    }

    return $result;
}

$input = sml_inputs($_POST ?: $_GET);
$config = sml_config();
$isimsConfig = sml_isims_config();
$action = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? (string)($_POST['action'] ?? '') : '';
if ($action !== '') { zurie_security_require_valid_csrf(); }
$error = '';
$success = '';
$previewRows = [];
$previewCount = null;


if ($action === 'test_isims') {
    try {
        $isims = sml_isims_connect($isimsConfig);
        $target = sml_isims_target_info($isims, $isimsConfig);
        $success = sprintf(
            'Sambungan i-SIMS berjaya. Sasaran disahkan: %s.%s (%d column, kunci: %s).',
            $isimsConfig['dbname'],
            $target['table'],
            $target['columns'],
            $target['key']
        );
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($action === 'sync_isims') {
    try {
        if (!sml_config_ready($config)) {
            throw new RuntimeException('Konfigurasi PostgreSQL belum lengkap. Isi fail /zurie/config/ilmu_pg_config.php dahulu.');
        }
        $pgsql = sml_connect($config);
        $isims = sml_isims_connect($isimsConfig);
        $syncResult = sml_sync_rows_to_isims($pgsql, $isims, $input, $isimsConfig);
        $success = sprintf(
            'Sync terus ke i-SIMS berjaya. %s — sumber: %d, baharu: %d, dikemas kini: %d, dilangkau: %d. Column dipadankan automatik, contoh Nama Mentor → nama_mentor jika column wujud. Tiada rekod lama dipadam.',
            $syncResult['table'],
            $syncResult['source'],
            $syncResult['inserted'],
            $syncResult['updated'],
            $syncResult['skipped']
        );
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($action === 'export') {
    try {
        if (!sml_config_ready($config)) {
            throw new RuntimeException('Konfigurasi PostgreSQL belum lengkap. Isi fail /zurie/config/ilmu_pg_config.php dahulu.');
        }

        $pdo = sml_connect($config);
        $incomeExpression = sml_income_expression($pdo);
        $exportSql = sml_sql(true, $incomeExpression);
        $stmt = $pdo->prepare($exportSql);
        sml_bind($stmt, $input, true);
        $stmt->execute();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . sml_filename($input) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new RuntimeException('Tidak dapat membuka output CSV.');
        }

        if ($input['bom']) {
            fwrite($out, "\xEF\xBB\xBF");
        }

        $headers = sml_headers(sml_sql(false, $incomeExpression));
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
        if (!sml_config_ready($config)) {
            throw new RuntimeException('Konfigurasi PostgreSQL belum lengkap. Isi fail /zurie/config/ilmu_pg_config.php dahulu.');
        }

        $pdo = sml_connect($config);

        if ($action === 'test') {
            $pdo->query('SELECT 1');
            $success = 'Sambungan PostgreSQL berjaya.';
        } else {
            $incomeExpression = sml_income_expression($pdo);
            $baseSql = sml_sql(false, $incomeExpression);
            $countSql = 'SELECT COUNT(*) FROM (' . $baseSql . ') AS senarai_mis_lengkap_count';
            $countStmt = $pdo->prepare($countSql);
            sml_bind($countStmt, $input, false);
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();
            $previewCount = min($total, $input['limit']);

            $previewSql = $baseSql . "\nLIMIT 20";
            $previewStmt = $pdo->prepare($previewSql);
            sml_bind($previewStmt, $input, false);
            $previewStmt->execute();
            $previewRows = $previewStmt->fetchAll(PDO::FETCH_ASSOC);
            $success = 'Query berjaya. ' . $previewCount . ' rekod akan dieksport berdasarkan limit semasa.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$pgsqlDriverAvailable = class_exists('PDO') && in_array('pgsql', PDO::getAvailableDrivers(), true);
$mysqlDriverAvailable = class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true);
$configExists = is_file($config['config_path']);
$isimsConfigExists = is_file($isimsConfig['config_path']);
$isimsReady = sml_isims_config_ready($isimsConfig);
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>i-SIMS Senarai MIS Lengkap CSV</title>
<link rel="icon" href="/zurie/image/zuriex.jpg">
<style>
:root{--bg:#07111f;--card:#0d1c2e;--line:rgba(130,170,210,.18);--text:#eaf4ff;--muted:#86a0b8;--cyan:#55d9ff;--green:#51e3a4;--red:#ff7183}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top left,#123456 0,#07111f 36%,#040b14 100%);color:var(--text);font-family:Segoe UI,Arial,sans-serif;font-size:13px}.wrap{max-width:1400px;margin:0 auto;padding:18px}.top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px}.top a{color:#9ddfff;text-decoration:none}.title h1{margin:0;font-size:22px}.title p{margin:4px 0 0;color:var(--muted)}.card{background:linear-gradient(145deg,rgba(13,28,46,.96),rgba(8,18,31,.96));border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.25);padding:16px;margin-bottom:14px}.status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.status{padding:10px;border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.02)}.status span{display:block;color:var(--muted);font-size:10px}.status b{display:block;margin-top:3px}.ok{color:var(--green)}.bad{color:var(--red)}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.field label{display:block;color:var(--muted);font-size:11px;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em}.field input{width:100%;border:1px solid rgba(130,170,210,.25);background:#081523;color:var(--text);border-radius:10px;padding:10px 11px;outline:none}.field input:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(85,217,255,.09)}.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}.btn{border:1px solid rgba(85,217,255,.32);background:rgba(85,217,255,.10);color:#bff0ff;border-radius:10px;padding:10px 13px;text-decoration:none;cursor:pointer;font-weight:700}.btn.primary{background:linear-gradient(135deg,rgba(85,217,255,.22),rgba(81,227,164,.14));border-color:rgba(85,217,255,.55)}.btn.export{background:linear-gradient(135deg,rgba(81,227,164,.22),rgba(85,217,255,.12));border-color:rgba(81,227,164,.5);color:#aaffd9}.alert{padding:11px 13px;border-radius:11px;margin-bottom:13px}.alert.error{border:1px solid rgba(255,113,131,.3);background:rgba(255,113,131,.08);color:#ffc1c9}.alert.success{border:1px solid rgba(81,227,164,.28);background:rgba(81,227,164,.08);color:#aaffd9}.setup{color:var(--muted);font-size:12px;line-height:1.6}.setup code{color:#c9efff;background:#06111d;padding:2px 5px;border-radius:5px}.preview-wrap{overflow:auto;max-height:560px;border:1px solid var(--line);border-radius:12px}.preview{width:100%;border-collapse:collapse;font-size:11px;white-space:nowrap}.preview th,.preview td{padding:7px 9px;border-bottom:1px solid rgba(130,170,210,.1);border-right:1px solid rgba(130,170,210,.07);text-align:left}.preview th{position:sticky;top:0;background:#10263d;color:#9de7ff;z-index:1}.preview td{color:#c4d5e4}.check{display:flex;align-items:center;gap:7px;color:var(--muted);font-size:12px}.check input{accent-color:#51e3a4}@media(max-width:850px){.grid{grid-template-columns:repeat(2,1fr)}.status-grid{grid-template-columns:1fr}.top{display:block}.wrap{padding:12px}}@media(max-width:480px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="title">
      <h1>i-SIMS — Senarai MIS Lengkap</h1>
      <p>Jalankan query terus dari PostgreSQL MIS, preview/download CSV dan sync terus ke table senarai_mis_lengkap di server i-SIMS.</p>
    </div>
    <a href="../index.php">← Dashboard</a>
  </div>

  <?php if ($error !== ''): ?><div class="alert error"><?= sml_e($error) ?></div><?php endif; ?>
  <?php if ($success !== ''): ?><div class="alert success"><?= sml_e($success) ?></div><?php endif; ?>

  <section class="card">
    <div class="status-grid">
      <div class="status"><span>PHP PDO PostgreSQL</span><b class="<?= $pgsqlDriverAvailable ? 'ok' : 'bad' ?>"><?= $pgsqlDriverAvailable ? 'AKTIF' : 'TIDAK AKTIF' ?></b></div>
      <div class="status"><span>Fail konfigurasi PostgreSQL</span><b class="<?= $configExists ? 'ok' : 'bad' ?>"><?= $configExists ? 'DIJUMPAI' : 'BELUM ADA' ?></b></div>
      <div class="status"><span>Database MIS</span><b><?= sml_e($config['host'] !== '' ? $config['host'] . ' / ' . $config['dbname'] : 'Belum dikonfigurasi') ?></b></div>
      <div class="status"><span>PHP PDO MySQL</span><b class="<?= $mysqlDriverAvailable ? 'ok' : 'bad' ?>"><?= $mysqlDriverAvailable ? 'AKTIF' : 'TIDAK AKTIF' ?></b></div>
      <div class="status"><span>Config i-SIMS</span><b class="<?= $isimsConfigExists ? 'ok' : 'bad' ?>"><?= $isimsConfigExists ? 'DIJUMPAI' : 'BELUM ADA' ?></b></div>
      <div class="status"><span>Sasaran i-SIMS</span><b class="<?= $isimsReady ? 'ok' : 'bad' ?>"><?= sml_e($isimsConfig['dbname'] . '.' . $isimsConfig['table']) ?></b></div>
    </div>
    <p class="setup">PostgreSQL menggunakan <code>/zurie/config/ilmu_pg_config.php</code>. i-SIMS menggunakan <code>C:\xampp_baru\secure\isims_mysql_config.php</code>. Sync i-SIMS hanya INSERT + UPDATE, tiada DELETE/TRUNCATE.</p>
  </section>

  <form class="card" method="post" action="isims_extract.php">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(zurie_security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <div class="grid">
      <div class="field"><label>Prefix Matrik 1</label><input name="matrik1" value="<?= sml_e($input['matrik1']) ?>" maxlength="6"></div>
      <div class="field"><label>Prefix Matrik 2</label><input name="matrik2" value="<?= sml_e($input['matrik2']) ?>" maxlength="6"></div>
      <div class="field"><label>No KP Prefix 1</label><input name="nokp1" value="<?= sml_e($input['nokp1']) ?>" maxlength="4"></div>
      <div class="field"><label>No KP Prefix 2</label><input name="nokp2" value="<?= sml_e($input['nokp2']) ?>" maxlength="4"></div>
      <div class="field"><label>No KP Prefix 3</label><input name="nokp3" value="<?= sml_e($input['nokp3']) ?>" maxlength="4"></div>
      <div class="field"><label>Had Rekod</label><input name="limit" value="<?= (int)$input['limit'] ?>" type="number" min="1" max="5000"></div>
    </div>

    <div class="actions">
      <button class="btn" type="submit" name="action" value="test">Test PostgreSQL</button>
      <button class="btn primary" type="submit" name="action" value="preview">Semak & Preview</button>
      <button class="btn export" type="submit" name="action" value="export">Download CSV Backup</button>
      <button class="btn" type="submit" name="action" value="test_isims">Test i-SIMS</button>
      <button class="btn export" type="submit" name="action" value="sync_isims" onclick="return confirm('Sync terus ke i-SIMS table senarai_mis_lengkap? Rekod matrik sedia ada akan dikemas kini. Rekod lama tidak dipadam.')">Sync ke i-SIMS</button>
      <label class="check"><input type="checkbox" name="bom" value="1" <?= $input['bom'] ? 'checked' : '' ?>> UTF-8 BOM untuk Excel</label>
    </div>
  </form>

  <?php if ($previewCount !== null): ?>
  <section class="card">
    <h2 style="margin-top:0;font-size:15px">Preview 20 rekod pertama — <?= (int)$previewCount ?> rekod akan dieksport</h2>
    <?php if ($previewRows): ?>
    <div class="preview-wrap"><table class="preview">
      <thead><tr><?php foreach (array_keys($previewRows[0]) as $column): ?><th><?= sml_e($column) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($previewRows as $row): ?><tr><?php foreach ($row as $value): ?><td><?= sml_e($value) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?><p class="setup">Tiada rekod sepadan dengan tapisan.</p><?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php zurie_pg_runtime_widget('isims_mis_lengkap'); ?>
</body>
</html>

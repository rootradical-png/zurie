<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth_guard.php';
require_once dirname(__DIR__) . '/lib/pg_live_lookup.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

date_default_timezone_set('Asia/Kuala_Lumpur');

function pgl_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pgl_csrf(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['pg_live_setup_csrf'])) {
        $_SESSION['pg_live_setup_csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['pg_live_setup_csrf'];
}

function pgl_require_csrf(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    $real = (string)($_SESSION['pg_live_setup_csrf'] ?? '');
    if ($sent === '' || $real === '' || !hash_equals($real, $sent)) {
        throw new RuntimeException('Token keselamatan tidak sah. Refresh halaman dan cuba semula.');
    }
}

function pgl_mask_ic(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    return strlen($digits) >= 10 ? substr($digits, 0, 6) . '-**-' . substr($digits, -4) : '********';
}

function pgl_mask_phone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) < 7) {
        return '-';
    }
    return substr($digits, 0, 3) . '****' . substr($digits, -3);
}

function pgl_validate_runtime_input(array $source): array
{
    $semester = (int)($source['semester'] ?? 0);
    $activeStatus = strtoupper(trim((string)($source['active_status'] ?? '')));
    $activeStatus = preg_replace('/[^A-Z0-9_-]/', '', $activeStatus) ?? '';
    $academicSession = trim((string)($source['academic_session'] ?? ''));

    if ($semester <= 0) {
        throw new RuntimeException('Pilih semester aktif yang sah.');
    }
    if ($activeStatus === '') {
        throw new RuntimeException('Kod status aktif tidak boleh kosong.');
    }
    if ($academicSession === '') {
        throw new RuntimeException('Sesi akademik tidak boleh kosong. Contoh: 2026/2027.');
    }
    if (strlen($academicSession) > 30) {
        throw new RuntimeException('Sesi akademik terlalu panjang.');
    }

    return [
        'semester' => $semester,
        'active_status' => $activeStatus,
        'academic_session' => $academicSession,
    ];
}

$config = zurie_pg_live_config();
$connectionReady = zurie_pg_live_connection_ready($config);
$ready = zurie_pg_live_config_ready($config);
$message = '';
$error = '';
$studentResult = null;
$testedCount = null;

$formSemester = (int)($config['semester'] ?? 0);
$formActiveStatus = (string)($config['active_status'] ?? '');
$formAcademicSession = (string)($config['academic_session'] ?? '');

if (isset($_GET['saved'])) {
    $message = 'Tetapan semester aktif berjaya disimpan dan akan digunakan oleh semakan automatik.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        pgl_require_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_settings' || $action === 'test_settings') {
            $runtimeInput = pgl_validate_runtime_input($_POST);
            $formSemester = $runtimeInput['semester'];
            $formActiveStatus = $runtimeInput['active_status'];
            $formAcademicSession = $runtimeInput['academic_session'];

            $testConfig = $config;
            $testConfig['semester'] = $formSemester;
            $testConfig['active_status'] = $formActiveStatus;
            $testConfig['academic_session'] = $formAcademicSession;

            $pgsql = zurie_pg_live_connect($testConfig);
            $testedCount = zurie_pg_live_count_active($pgsql, $formSemester, $formActiveStatus);

            if ($action === 'save_settings') {
                $mysql = zurie_pg_live_settings_pdo();
                $actor = (string)($_SESSION['SESS_MEMBER_ID'] ?? $_SESSION['username'] ?? 'admin');
                zurie_pg_live_save_settings(
                    $mysql,
                    $formSemester,
                    $formActiveStatus,
                    $formAcademicSession,
                    $actor
                );
                header('Location: /zurie/pages/pg_live_lookup_setup.php?saved=1');
                exit;
            }

            $message = 'Tetapan sah. Pelajar aktif unik dijumpai: ' . number_format($testedCount) . '.';
        } else {
            $pdo = zurie_pg_live_connect($config);

            if ($action === 'test_connection') {
                $pdo->query('SELECT 1')->fetchColumn();
                $message = 'Sambungan PostgreSQL read-only berjaya.';
            } elseif ($action === 'test_student') {
                if (!$ready) {
                    throw new RuntimeException('Simpan semester aktif dan kod status terlebih dahulu.');
                }
                $matrik = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($_POST['matrik'] ?? '')) ?? '');
                $nokp = preg_replace('/\D+/', '', (string)($_POST['nokp'] ?? '')) ?? '';
                if ($matrik === '' || strlen($nokp) < 10) {
                    throw new RuntimeException('Masukkan No Matrik dan No KP lengkap.');
                }
                $activeResult = zurie_pg_live_lookup_student($pdo, $matrik, $nokp, $config);
                if (is_array($activeResult)) {
                    $studentResult = $activeResult;
                    $studentResult['_lookup_status'] = 'active';
                    $message = 'Pelajar aktif ditemui. Ujian ini tidak mengubah MySQL.';
                } else {
                    $rawResult = zurie_pg_live_lookup_raw_student($pdo, $matrik, $nokp);
                    if (is_array($rawResult)) {
                        $studentResult = $rawResult;
                        $studentResult['_lookup_status'] = 'pending_registration';
                        $message = 'Identiti ditemui dalam data mentah, tetapi pendaftaran fizikal belum aktif.';
                    } else {
                        $message = 'No Matrik dan No KP tidak sepadan dalam rekod PostgreSQL.';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$semesterOptions = [];
if ($connectionReady) {
    try {
        $pgsqlForOptions = zurie_pg_live_connect($config);
        $semesterOptions = zurie_pg_live_semester_options($pgsqlForOptions, $formActiveStatus !== '' ? $formActiveStatus : '01');
    } catch (Throwable $e) {
        if ($error === '') {
            $error = 'Senarai semester tidak dapat dimuatkan: ' . $e->getMessage();
        }
    }
}

$expectedExternal = 'C:\\xampp_baru\\secure\\zurie_pg_live_config.php';
$configPath = (string)($config['config_path'] ?? '');
$isExternal = $configPath !== '' && stripos(str_replace('\\', '/', $configPath), '/htdocs/') === false;
$settingsSource = (string)($config['settings_source'] ?? 'config');
?>
<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Semakan PostgreSQL Langsung | Zurie</title>
<style>
body{margin:0;background:#f4f7fb;color:#0f172a;font-family:Segoe UI,Arial,sans-serif}.wrap{max-width:940px;margin:28px auto;padding:0 16px}.crumb{font-size:13px;color:#64748b;margin-bottom:14px}.crumb a{color:#2563eb;text-decoration:none}.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:16px;box-shadow:0 8px 28px rgba(15,23,42,.08)}h1{margin:0 0 6px;font-size:25px}h2{font-size:18px;margin-top:0}.muted{color:#64748b}.grid{display:grid;grid-template-columns:180px 1fr;gap:9px;font-size:14px}.badge{display:inline-block;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:800}.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}.warn{background:#fef3c7;color:#92400e}.info{background:#eff6ff;color:#1e40af}.alert{padding:12px 14px;border-radius:11px;margin:12px 0}.btn{border:0;border-radius:9px;padding:10px 14px;background:#2563eb;color:#fff;font-weight:800;cursor:pointer}.btn.secondary{background:#475569}.btn:disabled{opacity:.45;cursor:not-allowed}.fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}.settings-fields{display:grid;grid-template-columns:1.2fr .8fr 1fr;gap:12px}label{display:block;font-size:13px;font-weight:700;margin-bottom:5px}input,select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:9px;background:#fff}.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}.result{margin-top:14px;padding:13px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px}.count-box{margin-top:12px;padding:12px 14px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:10px;font-weight:700}.hint{font-size:12px;color:#64748b;margin-top:5px}.source{font-size:12px;color:#475569}@media(max-width:700px){.grid,.fields,.settings-fields{grid-template-columns:1fr}}
</style>
</head>
<body><main class="wrap">
<div class="crumb"><a href="/zurie/index.php">Dashboard</a> › <a href="/zurie/pages/upload_review.php">Semakan Foto</a> › PostgreSQL Langsung</div>
<div class="card">
  <h1>Semakan PostgreSQL Langsung</h1>
  <p class="muted">Digunakan secara automatik hanya apabila pelajar belum ditemui dalam table MySQL <b>senarai</b>.</p>
  <p><span class="badge <?= $ready ? 'ok' : 'warn' ?>"><?= $ready ? 'SEDIA' : 'PERLU TETAPAN OPERASI' ?></span></p>
  <?php if ($message !== ''): ?><div class="alert ok"><?= pgl_h($message) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert bad"><?= pgl_h($error) ?></div><?php endif; ?>
  <?php if ($configPath !== '' && !$isExternal): ?><div class="alert warn">Config sedang dibaca dari dalam htdocs. Untuk keselamatan, pindahkan ke <?= pgl_h($expectedExternal) ?>.</div><?php endif; ?>
  <div class="grid">
    <strong>Fail config</strong><span><?= pgl_h($configPath !== '' ? $configPath : $expectedExternal . ' (belum ada)') ?></span>
    <strong>PDO PostgreSQL</strong><span><?= in_array('pgsql', PDO::getAvailableDrivers(), true) ? 'Aktif' : 'Tidak aktif' ?></span>
    <strong>Host</strong><span><?= pgl_h(($config['host'] ?? '-') . ':' . ($config['port'] ?? 5432)) ?></span>
    <strong>Database</strong><span><?= pgl_h($config['dbname'] ?? '-') ?></span>
    <strong>User read-only</strong><span><?= pgl_h($config['user'] ?? '-') ?></span>
    <strong>Semester aktif</strong><span><?= pgl_h($config['semester'] ?: '-') ?></span>
    <strong>Kod aktif</strong><span><?= pgl_h($config['active_status'] ?: '-') ?></span>
    <strong>Sesi akademik</strong><span><?= pgl_h($config['academic_session'] ?: '-') ?></span>
    <strong>Sumber tetapan</strong><span class="source"><?= $settingsSource === 'mysql' ? 'MySQL Zurie (boleh diubah pada halaman ini)' : 'Fail config selamat' ?></span>
  </div>
</div>

<div class="card">
  <h2>Tetapan operasi semasa</h2>
  <p class="muted">Pilih semester terus daripada PostgreSQL. Tetapan ini disimpan dalam MySQL Zurie, jadi tahun depan tidak perlu edit fail PHP.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= pgl_h(pgl_csrf()) ?>">
    <div class="settings-fields">
      <div>
        <label>Semester aktif</label>
        <select name="semester" required>
          <?php if ($formSemester <= 0): ?><option value="">-- Pilih semester --</option><?php endif; ?>
          <?php foreach ($semesterOptions as $option): ?>
            <?php $semesterValue = (int)$option['stud_semester']; ?>
            <?php
              $activeRecords = (int)($option['active_count'] ?? 0);
              $activeUnique = (int)($option['active_unique_count'] ?? $activeRecords);
              $activeBlankKp = (int)($option['active_blank_kp_count'] ?? 0);
              $activeDuplicates = (int)($option['active_duplicate_count'] ?? max(0, $activeRecords - $activeBlankKp - $activeUnique));
            ?>
            <option value="<?= $semesterValue ?>" <?= $semesterValue === $formSemester ? 'selected' : '' ?>>
              Semester <?= $semesterValue ?> — <?= number_format($activeUnique) ?> pelajar aktif unik / <?= number_format($activeRecords) ?> rekod aktif<?= $activeDuplicates > 0 ? ' / ' . number_format($activeDuplicates) . ' rekod pendua' : '' ?><?= $activeBlankKp > 0 ? ' / ' . number_format($activeBlankKp) . ' tanpa No. KP' : '' ?> / <?= number_format((int)$option['total_count']) ?> jumlah rekod
            </option>
          <?php endforeach; ?>
          <?php if ($formSemester > 0 && !in_array($formSemester, array_map(static fn($o) => (int)$o['stud_semester'], $semesterOptions), true)): ?>
            <option value="<?= $formSemester ?>" selected>Semester <?= $formSemester ?> (tetapan semasa)</option>
          <?php endif; ?>
        </select>
        <div class="hint">Senarai diambil terus daripada table PostgreSQL <code>pelajar</code>.</div>
      </div>
      <div>
        <label>Kod status aktif</label>
        <input name="active_status" value="<?= pgl_h($formActiveStatus) ?>" maxlength="10" required>
        <div class="hint">Contoh semasa: 01</div>
      </div>
      <div>
        <label>Sesi akademik</label>
        <input name="academic_session" value="<?= pgl_h($formAcademicSession) ?>" maxlength="30" placeholder="2026/2027" required>
        <div class="hint">Digunakan untuk label sesi dan rujukan admin.</div>
      </div>
    </div>
    <?php if ($testedCount !== null): ?><div class="count-box">Pelajar aktif unik dijumpai: <?= number_format($testedCount) ?></div><?php endif; ?>
    <div class="actions">
      <button class="btn secondary" type="submit" name="action" value="test_settings" <?= !$connectionReady ? 'disabled' : '' ?>>Uji Tetapan</button>
      <button class="btn" type="submit" name="action" value="save_settings" <?= !$connectionReady ? 'disabled' : '' ?> onclick="return confirm('Simpan semester, kod aktif dan sesi akademik ini sebagai tetapan operasi semasa?')">Simpan Tetapan</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Uji sambungan</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= pgl_h(pgl_csrf()) ?>">
    <input type="hidden" name="action" value="test_connection">
    <button class="btn" type="submit" <?= !$connectionReady ? 'disabled' : '' ?>>Uji PostgreSQL</button>
  </form>
</div>

<div class="card">
  <h2>Uji seorang pelajar</h2>
  <p class="muted">Sistem menyemak status aktif berdasarkan tetapan di atas, kemudian fallback kepada data mentah <code>personal</code>. Ujian ini tidak menyimpan ke MySQL.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= pgl_h(pgl_csrf()) ?>">
    <input type="hidden" name="action" value="test_student">
    <div class="fields">
      <div><label>No Matrik</label><input name="matrik" value="<?= pgl_h($_POST['matrik'] ?? '') ?>" required></div>
      <div><label>No KP</label><input name="nokp" value="<?= pgl_h($_POST['nokp'] ?? '') ?>" required></div>
    </div>
    <p><button class="btn" type="submit" <?= !$ready ? 'disabled' : '' ?>>Semak Pelajar</button></p>
  </form>
  <?php if (is_array($studentResult)): ?>
  <div class="result">
    <b><?= pgl_h($studentResult['nama'] ?? '') ?></b><br>
    <?= pgl_h($studentResult['matrik'] ?? '') ?> · <?= pgl_h(pgl_mask_ic((string)($studentResult['nokp'] ?? ''))) ?><br>
    <?php if (($studentResult['_lookup_status'] ?? '') === 'active'): ?>
      <span class="badge ok">AKTIF</span>
      Intake <?= pgl_h($studentResult['stud_intake'] ?? '-') ?> · Semester <?= pgl_h($studentResult['stud_semester'] ?? '-') ?> · Status <?= pgl_h($studentResult['stud_status'] ?? '-') ?><br>
    <?php else: ?>
      <span class="badge warn">MENUNGGU PENDAFTARAN FIZIKAL</span><br>
    <?php endif; ?>
    Telefon: <?= pgl_h(pgl_mask_phone((string)($studentResult['nohp'] ?? ''))) ?>
  </div>
  <?php endif; ?>
</div>
</main></body></html>

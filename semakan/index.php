<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'none'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) {
    session_name('ZURIESEMAKAN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/zurie/semakan/',
        'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

define('ZURIE_SEMAKAN_BOOTSTRAPPED', true);
$database = require __DIR__ . '/data/kelayakan.php';
$meta = is_array($database['meta'] ?? null) ? $database['meta'] : [];
$records = is_array($database['records'] ?? null) ? $database['records'] : [];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clean_matrik(string $value): string
{
    return strtoupper((string)(preg_replace('/[^A-Za-z0-9]/', '', trim($value)) ?? ''));
}

function clean_nokp(string $value): string
{
    return (string)(preg_replace('/\D+/', '', $value) ?? '');
}

function mask_nokp(string $value): string
{
    return strlen($value) === 12
        ? substr($value, 0, 6) . '-XX-XXXX'
        : '************';
}

function csrf_token(): string
{
    $token = (string)($_SESSION['semakan_csrf'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['semakan_csrf'] = $token;
    }
    return $token;
}

function register_failed_attempt(): void
{
    $now = time();
    $attempts = array_values(array_filter(
        (array)($_SESSION['semakan_attempts'] ?? []),
        static fn($time): bool => is_int($time) && $time >= $now - 600
    ));
    $attempts[] = $now;
    $_SESSION['semakan_attempts'] = $attempts;
    if (count($attempts) >= 8) {
        $_SESSION['semakan_locked_until'] = $now + 600;
    }
}

function clear_failed_attempts(): void
{
    unset($_SESSION['semakan_attempts'], $_SESSION['semakan_locked_until']);
}

$result = null;
$error = '';
$matrikInput = '';
$nokpInput = '';
$lockedRemaining = max(0, (int)($_SESSION['semakan_locked_until'] ?? 0) - time());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $matrikInput = trim((string)($_POST['matrik'] ?? ''));
    $nokpInput = trim((string)($_POST['nokp'] ?? ''));
    $sentToken = (string)($_POST['_csrf'] ?? '');

    if ($lockedRemaining > 0) {
        $error = 'Terlalu banyak cubaan tidak berjaya. Sila cuba semula selepas beberapa minit.';
    } elseif (!hash_equals(csrf_token(), $sentToken)) {
        $error = 'Sesi semakan telah tamat. Muat semula halaman dan cuba semula.';
    } else {
        $matrik = clean_matrik($matrikInput);
        $nokp = clean_nokp($nokpInput);

        if (!preg_match('/^[A-Z0-9]{8,16}$/', $matrik)) {
            $error = 'Sila masukkan No. Matrik yang sah.';
        } elseif (strlen($nokp) !== 12) {
            $error = 'No. Kad Pengenalan mestilah 12 digit.';
        } else {
            $record = $records[$matrik] ?? null;
            if (is_array($record)
                && isset($record['kp_hash'])
                && password_verify($nokp, (string)$record['kp_hash'])) {
                clear_failed_attempts();
                $result = [
                    'nama' => (string)($record['nama'] ?? ''),
                    'matrik' => $matrik,
                    'nokp' => mask_nokp($nokp),
                    'status' => strtoupper((string)($record['status'] ?? '')),
                    'maklumat' => trim((string)($record['maklumat'] ?? '')),
                ];
                $matrikInput = '';
                $nokpInput = '';
            } else {
                register_failed_attempt();
                $error = 'Maklumat tidak ditemui atau No. Matrik dan No. Kad Pengenalan tidak sepadan.';
            }
        }
    }
}

$isEligible = is_array($result) && ($result['status'] ?? '') === 'LAYAK';
$title = (string)($meta['title'] ?? 'Semakan Kelayakan BSHP');
$institution = (string)($meta['institution'] ?? 'Kolej Matrikulasi Perlis');
$updatedAt = (string)($meta['updated_at'] ?? '');
?>
<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?= h($title) ?> | <?= h($institution) ?></title>
<style>
:root{--navy:#0b2146;--blue:#155eef;--sky:#eaf2ff;--ink:#10213a;--muted:#64748b;--line:#d9e2ef;--green:#15803d;--green-bg:#ecfdf3;--red:#b42318;--red-bg:#fff1f0;--amber:#92400e;--amber-bg:#fffbeb;--white:#fff;--shadow:0 24px 70px rgba(11,33,70,.16)}
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Segoe UI,Arial,sans-serif;color:var(--ink);background:radial-gradient(circle at 15% 10%,rgba(21,94,239,.16),transparent 32%),linear-gradient(145deg,#f8fbff,#edf4ff 50%,#f7fbff);padding:28px 16px}.shell{width:min(760px,100%);margin:auto}.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px}.brand{display:flex;align-items:center;gap:12px}.crest{width:52px;height:52px;border-radius:16px;background:linear-gradient(145deg,var(--navy),var(--blue));color:#fff;display:grid;place-items:center;font-size:17px;font-weight:900;letter-spacing:.5px;box-shadow:0 10px 24px rgba(21,94,239,.25)}.brand strong{display:block;font-size:15px}.brand span{color:var(--muted);font-size:13px}.back{color:var(--navy);text-decoration:none;font-weight:700;font-size:14px}.card{background:rgba(255,255,255,.96);border:1px solid rgba(217,226,239,.9);border-radius:26px;box-shadow:var(--shadow);overflow:hidden}.hero{padding:34px 34px 24px;background:linear-gradient(135deg,#0b2146,#103f8f 64%,#155eef);color:#fff}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:1.8px;font-weight:800;opacity:.78}.hero h1{font-size:clamp(27px,5vw,40px);line-height:1.12;margin:10px 0 12px}.hero p{margin:0;max-width:620px;line-height:1.65;color:#dce9ff}.content{padding:30px 34px 34px}.guide{display:flex;gap:10px;align-items:flex-start;background:var(--sky);border:1px solid #cbdcff;border-radius:14px;padding:13px 15px;margin-bottom:22px;color:#24446f;font-size:14px;line-height:1.55}.guide b{color:var(--navy)}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.field label{display:block;font-weight:800;font-size:14px;margin-bottom:8px}.field input{width:100%;height:50px;border:1px solid var(--line);border-radius:13px;padding:0 14px;font:inherit;color:var(--ink);background:#fff;outline:none;transition:.18s}.field input:focus{border-color:var(--blue);box-shadow:0 0 0 4px rgba(21,94,239,.12)}.hint{font-size:12px;color:var(--muted);margin-top:6px}.submit{width:100%;height:52px;border:0;border-radius:14px;margin-top:20px;background:linear-gradient(135deg,var(--blue),#0c48be);color:#fff;font:inherit;font-weight:900;cursor:pointer;box-shadow:0 12px 24px rgba(21,94,239,.22)}.submit:disabled{opacity:.55;cursor:not-allowed}.alert{border-radius:14px;padding:13px 15px;margin:0 0 20px;font-size:14px;line-height:1.55}.alert.error{background:var(--red-bg);border:1px solid #fecaca;color:var(--red)}.result{margin-top:26px;border:1px solid var(--line);border-radius:20px;overflow:hidden}.result-head{padding:20px 22px;display:flex;align-items:center;justify-content:space-between;gap:12px}.result.good .result-head{background:var(--green-bg)}.result.bad .result-head{background:var(--red-bg)}.result-title{font-weight:900;font-size:17px}.status{padding:7px 12px;border-radius:999px;font-size:12px;font-weight:900;letter-spacing:.5px}.good .status{background:#dcfce7;color:var(--green)}.bad .status{background:#fee2e2;color:var(--red)}.details{padding:20px 22px;display:grid;grid-template-columns:1fr 1fr;gap:16px 26px}.detail small{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.8px;font-weight:800;margin-bottom:5px}.detail strong{font-size:15px;line-height:1.45}.detail.full{grid-column:1/-1}.extra{margin:0 22px 22px;background:var(--amber-bg);border:1px solid #fde68a;border-left:5px solid #f59e0b;border-radius:14px;padding:16px 17px;color:var(--amber);line-height:1.6}.extra b{display:block;margin-bottom:5px;color:#78350f}.privacy{margin-top:20px;text-align:center;color:var(--muted);font-size:12px;line-height:1.55}.updated{text-align:center;color:var(--muted);font-size:12px;margin-top:16px}.footer{text-align:center;color:#718096;font-size:12px;margin-top:18px}.footer a{color:var(--navy);font-weight:700;text-decoration:none}@media(max-width:650px){body{padding:16px 10px}.topbar{align-items:flex-start}.back{margin-top:8px}.hero{padding:27px 22px 21px}.content{padding:24px 20px 26px}.form-grid,.details{grid-template-columns:1fr}.detail.full{grid-column:auto}.result-head{align-items:flex-start;flex-direction:column}.crest{width:46px;height:46px;border-radius:14px}}
</style>
</head>
<body>
<main class="shell">
    <div class="topbar">
        <div class="brand">
            <div class="crest" aria-hidden="true">KMP</div>
            <div><strong><?= h($institution) ?></strong><span>Portal Semakan Pelajar</span></div>
        </div>
        <a class="back" href="/zurie/">← Kembali</a>
    </div>

    <section class="card">
        <header class="hero">
            <div class="eyebrow">Semakan Individu</div>
            <h1><?= h($title) ?></h1>
            <p>Masukkan No. Matrik dan No. Kad Pengenalan untuk mendapatkan keputusan kelayakan.</p>
        </header>

        <div class="content">
            <div class="guide"><span aria-hidden="true">🔒</span><div><b>Pengesahan dua maklumat.</b> Kedua-dua nombor mesti sepadan dengan rekod rasmi. No. Kad Pengenalan tidak akan dipaparkan sepenuhnya.</div></div>

            <?php if ($error !== ''): ?>
                <div class="alert error" role="alert"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" action="" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <div class="form-grid">
                    <div class="field">
                        <label for="matrik">No. Matrik</label>
                        <input id="matrik" name="matrik" value="<?= h($matrikInput) ?>" maxlength="16" inputmode="text" autocapitalize="characters" placeholder="Contoh: MA2614110000" required <?= $lockedRemaining > 0 ? 'disabled' : '' ?>>
                        <div class="hint">Huruf besar atau kecil diterima.</div>
                    </div>
                    <div class="field">
                        <label for="nokp">No. Kad Pengenalan</label>
                        <input id="nokp" name="nokp" value="" maxlength="14" inputmode="numeric" placeholder="12 digit tanpa sengkang" required <?= $lockedRemaining > 0 ? 'disabled' : '' ?>>
                        <div class="hint">Contoh: 080101020304</div>
                    </div>
                </div>
                <button class="submit" type="submit" <?= $lockedRemaining > 0 ? 'disabled' : '' ?>>Semak Keputusan</button>
            </form>

            <?php if (is_array($result)): ?>
                <section class="result <?= $isEligible ? 'good' : 'bad' ?>" aria-live="polite">
                    <div class="result-head">
                        <div class="result-title">Keputusan Semakan</div>
                        <div class="status"><?= h((string)$result['status']) ?></div>
                    </div>
                    <div class="details">
                        <div class="detail full"><small>Nama Pelajar</small><strong><?= h((string)$result['nama']) ?></strong></div>
                        <div class="detail"><small>No. Matrik</small><strong><?= h((string)$result['matrik']) ?></strong></div>
                        <div class="detail"><small>No. Kad Pengenalan</small><strong><?= h((string)$result['nokp']) ?></strong></div>
                    </div>
                    <?php if (!$isEligible && (string)$result['maklumat'] !== ''): ?>
                        <div class="extra"><b>Maklumat Tambahan</b><?= h((string)$result['maklumat']) ?></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <div class="privacy">Keputusan ini adalah untuk semakan individu. Jangan kongsikan No. Kad Pengenalan atau tangkap layar yang mengandungi maklumat peribadi.</div>
            <?php if ($updatedAt !== ''): ?><div class="updated">Data dikemas kini: <?= h($updatedAt) ?></div><?php endif; ?>
        </div>
    </section>
    <div class="footer">Sistem ZURIE · <a href="/zurie/">Dashboard Utama</a></div>
</main>
</body>
</html>

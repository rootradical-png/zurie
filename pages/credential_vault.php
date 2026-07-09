<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/security.php';
zurie_security_protect_sensitive_page();
require_once dirname(__DIR__) . '/lib/vault.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$message = '';
$error = '';
$pdo = null;
$settings = null;
$devices = vault_load_devices();
$config = null;
$cryptoReady = vault_crypto_ready();

try {
    $config = vault_config();
    $pdo = vault_pdo();
    vault_ensure_schema($pdo);
    $settings = vault_settings($pdo);
} catch (Throwable $e) {
    $error = 'Credential Vault belum dapat menyambung ke MySQL/MariaDB. Edit config/vault_config.php dan pastikan database wujud.';
}

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        if (!vault_verify_csrf($_POST['csrf'] ?? null)) {
            $error = 'Token keselamatan tidak sah. Sila refresh halaman.';
        } elseif (!$cryptoReady) {
            $error = vault_crypto_error_message();
        } elseif ($action === 'setup_master' && !$settings) {
            $master = (string)($_POST['master_password'] ?? '');
            $confirm = (string)($_POST['master_confirm'] ?? '');
            if (strlen($master) < 12) {
                $error = 'Master Password mesti sekurang-kurangnya 12 aksara.';
            } elseif (!hash_equals($master, $confirm)) {
                $error = 'Pengesahan Master Password tidak sepadan.';
            } else {
                // Derive key BEFORE writing vault_settings to avoid partial setup.
                $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
                $saltEncoded = sodium_bin2base64($salt, SODIUM_BASE64_VARIANT_ORIGINAL);
                $key = vault_derive_key($master, $saltEncoded);
                $hash = password_hash($master, vault_password_algorithm());
                if (!is_string($hash) || $hash === '') {
                    throw new RuntimeException('Master Password gagal di-hash.');
                }
                $minutes = max(1, (int)($config['unlock_minutes'] ?? 10));

                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare('INSERT INTO vault_settings (id, master_hash, kdf_salt, unlock_minutes) VALUES (1, ?, ?, ?)');
                    $stmt->execute([$hash, $saltEncoded, $minutes]);
                    $pdo->commit();
                } catch (Throwable $dbError) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $dbError;
                }

                vault_store_session_key($key, $minutes);
                vault_audit($pdo, 'VAULT_SETUP');
                $settings = vault_settings($pdo);
                $message = 'Credential Vault berjaya disediakan dan telah dibuka.';
            }
        } elseif ($action === 'unlock' && $settings) {
            [$allowed, $wait] = vault_unlock_attempt_allowed();
            if (!$allowed) {
                $error = 'Terlalu banyak percubaan. Cuba semula dalam ' . $wait . ' saat.';
            } else {
                $master = (string)($_POST['master_password'] ?? '');
                if (!password_verify($master, (string)$settings['master_hash'])) {
                    vault_register_failed_unlock();
                    vault_audit($pdo, 'UNLOCK_FAILED');
                    $error = 'Master Password tidak tepat.';
                } else {
                    vault_clear_failed_unlocks();
                    $minutes = max(1, (int)($settings['unlock_minutes'] ?? $config['unlock_minutes'] ?? 10));
                    $key = vault_derive_key($master, (string)$settings['kdf_salt']);
                    vault_store_session_key($key, $minutes);
                    vault_audit($pdo, 'VAULT_UNLOCKED');
                    $message = 'Vault telah dibuka.';
                }
            }
        } elseif ($action === 'lock') {
            vault_audit($pdo, 'VAULT_LOCKED');
            vault_lock();
            $message = 'Vault telah dikunci.';
        } elseif ($action === 'save_credential') {
            if (!vault_is_unlocked()) {
                $error = 'Vault telah dikunci. Masukkan Master Password semula.';
            } else {
                $deviceId = trim((string)($_POST['device_id'] ?? ''));
                $username = trim((string)($_POST['device_username'] ?? ''));
                $password = (string)($_POST['device_password'] ?? '');
                $notes = trim((string)($_POST['device_notes'] ?? ''));
                if ($deviceId === '' || !isset($devices[$deviceId])) {
                    $error = 'Sila pilih peranti yang sah.';
                } elseif ($username === '' && $password === '') {
                    $error = 'Masukkan sekurang-kurangnya username atau password.';
                } else {
                    $enc = vault_encrypt_payload($deviceId, [
                        'username' => $username,
                        'password' => $password,
                        'notes' => $notes,
                    ], vault_session_key());
                    $stmt = $pdo->prepare('INSERT INTO device_credentials (device_id, ciphertext, nonce, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext), nonce=VALUES(nonce), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP');
                    $stmt->execute([$deviceId, $enc['ciphertext'], $enc['nonce'], (string)($config['portal_user'] ?? 'ZURIE')]);
                    vault_touch();
                    vault_audit($pdo, 'CREDENTIAL_SAVED', $deviceId, (string)($devices[$deviceId]['name'] ?? $deviceId));
                    $message = 'Credential untuk ' . (string)($devices[$deviceId]['name'] ?? $deviceId) . ' berjaya disimpan secara encrypted.';
                }
            }
        } elseif ($action === 'delete_credential') {
            if (!vault_is_unlocked()) {
                $error = 'Vault telah dikunci.';
            } else {
                $deviceId = trim((string)($_POST['device_id'] ?? ''));
                if ($deviceId !== '') {
                    $stmt = $pdo->prepare('DELETE FROM device_credentials WHERE device_id = ?');
                    $stmt->execute([$deviceId]);
                    vault_touch();
                    vault_audit($pdo, 'CREDENTIAL_DELETED', $deviceId, (string)($devices[$deviceId]['name'] ?? $deviceId));
                    $message = 'Credential telah dipadam.';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[ZURIE VAULT] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (stripos($e->getMessage(), 'Sodium') !== false || stripos($e->getMessage(), 'XChaCha20') !== false) {
            $error = vault_crypto_error_message();
        } else {
            $error = 'Operasi Credential Vault gagal. Ralat telah direkodkan dalam C:\xampp_baru\php\logs\php_error_log.';
        }
    }
}

$stored = [];
$audit = [];
if ($pdo) {
    try {
        foreach ($pdo->query('SELECT device_id, updated_at FROM device_credentials') as $row) $stored[(string)$row['device_id']] = $row;
        $audit = $pdo->query('SELECT event_type, device_name, actor, ip_address, created_at FROM vault_audit_log ORDER BY id DESC LIMIT 15')->fetchAll();
    } catch (Throwable $ignored) {}
}

$grouped = [];
foreach ($devices as $id => $d) $grouped[(string)($d['type'] ?? 'Other')][$id] = $d;
ksort($grouped);
$csrf = vault_csrf_token();
$unlocked = vault_is_unlocked();
$secondsLeft = $unlocked ? max(0, (int)($_SESSION['vault_unlocked_until'] ?? 0) - time()) : 0;
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Credential Vault | Personal NOC Dashboard</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="page-shell vault-page">
<header class="page-header vault-header">
  <div>
    <a href="../index.php" class="back-link">← Dashboard</a>
    <div class="breadcrumb-mini">🔐 Pentadbiran / Credential Vault</div>
    <h1>Credential Vault</h1>
    <p>Satu Master Password untuk membuka credential Switch, Server, AP dan Network Services.</p>
  </div>
  <div class="header-actions">
    <?php if ($unlocked): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="lock"><button class="danger-mini vault-lock-btn" type="submit">🔒 Lock Vault</button></form>
    <?php endif; ?>
    <a class="ghost-btn" href="device_manager.php">Device Manager</a>
  </div>
</header>

<?php if ($message): ?><div class="notice-box"><?=h($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="vault-error-box"><?=h($error)?></div><?php endif; ?>
<?php if (!$cryptoReady): ?><div class="vault-error-box"><b>Sodium belum aktif.</b><br><?=h(vault_crypto_error_message())?><br><small>Halaman Setup sengaja dinyahaktifkan untuk mengelakkan HTTP 500.</small></div><?php endif; ?>

<?php if (!$pdo): ?>
<section class="section-block compact-panel vault-setup-card">
  <h3>Konfigurasi diperlukan</h3>
  <ol>
    <li>Import <code>sql/credential_vault.sql</code> melalui phpMyAdmin.</li>
    <li>Edit <code>config/vault_config.php</code> dengan nama database, user dan password MySQL.</li>
    <li>Pastikan PHP extension <code>sodium</code> dan <code>pdo_mysql</code> aktif.</li>
  </ol>
</section>
<?php elseif (!$cryptoReady): ?>
<section class="section-block compact-panel vault-setup-card">
  <span class="vault-shield">!</span>
  <h2>Crypto Extension Diperlukan</h2>
  <p><?=h(vault_crypto_error_message())?></p>
  <ol style="text-align:left;max-width:720px;margin:16px auto">
    <li>Buka <code>C:\xampp_baru\php\php.ini</code>.</li>
    <li>Aktifkan satu baris sahaja: <code>extension=sodium</code>.</li>
    <li>Restart Apache melalui XAMPP Control Panel.</li>
    <li>Refresh halaman ini. Setup Master Password akan muncul selepas Sodium aktif.</li>
  </ol>
</section>
<?php elseif (!$settings): ?>
<section class="section-block compact-panel vault-setup-card">
  <span class="vault-shield">◆</span>
  <h2>Setup Master Password</h2>
  <p>Master Password tidak disimpan dalam bentuk asal. Gunakan sekurang-kurangnya 12 aksara dan jangan samakan dengan password login portal.</p>
  <form method="post" class="vault-auth-form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="setup_master">
    <label>Master Password<input type="password" name="master_password" minlength="12" required autocomplete="new-password"></label>
    <label>Ulang Master Password<input type="password" name="master_confirm" minlength="12" required autocomplete="new-password"></label>
    <button class="hero-btn" type="submit">Setup & Unlock Vault</button>
  </form>
</section>
<?php elseif (!$unlocked): ?>
<section class="section-block compact-panel vault-setup-card">
  <span class="vault-shield">◆</span>
  <h2>Vault Locked</h2>
  <p>Masukkan Master Password untuk akses sementara. Vault akan auto-lock selepas <?=h($settings['unlock_minutes'] ?? 10)?> minit tidak aktif.</p>
  <form method="post" class="vault-auth-form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="unlock">
    <label>Master Password<input type="password" name="master_password" required autofocus autocomplete="current-password"></label>
    <button class="hero-btn" type="submit">🔓 Unlock Vault</button>
  </form>
</section>
<?php else: ?>
<section class="vault-summary-grid">
  <div class="vault-summary-card"><span>🔑</span><b><?=count($stored)?></b><small>Credential disimpan</small></div>
  <div class="vault-summary-card"><span>▣</span><b><?=count($devices)?></b><small>Jumlah peranti</small></div>
  <div class="vault-summary-card"><span>◷</span><b id="vaultCountdown" data-seconds="<?=$secondsLeft?>">--:--</b><small>Auto-lock</small></div>
  <div class="vault-summary-card"><span>🛡</span><b>Encrypted</b><small>XChaCha20-Poly1305</small></div>
</section>

<section class="section-block compact-panel">
  <div class="section-title"><div><h3>Simpan / Kemas Kini Credential</h3><p>Pilih device daripada dropdown. Password hanya disimpan dalam bentuk ciphertext.</p></div></div>
  <form method="post" class="vault-editor-form" id="vaultEditorForm" autocomplete="off">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="save_credential">
    <label>Jenis & Device
      <select name="device_id" id="vaultDeviceSelect" required>
        <option value="">-- Pilih peranti --</option>
        <?php foreach ($grouped as $type => $list): ?><optgroup label="<?=h($type)?>">
          <?php foreach ($list as $id => $d): ?><option value="<?=h($id)?>"><?=h(($d['name'] ?? '') . ' — ' . ($d['ip'] ?? ''))?></option><?php endforeach; ?>
        </optgroup><?php endforeach; ?>
      </select>
    </label>
    <label>Username<input name="device_username" id="vaultUsername" autocomplete="off"></label>
    <label>Password<input type="password" name="device_password" id="vaultPassword" autocomplete="new-password"></label>
    <label class="vault-notes-field">Catatan<textarea name="device_notes" id="vaultNotes" rows="2" placeholder="Contoh: Web UI / SSH / lokasi"></textarea></label>
    <div class="vault-form-actions"><button class="hero-btn" type="submit">💾 Simpan Encrypted</button><button class="ghost-btn" type="button" id="vaultClearForm">Kosongkan</button></div>
  </form>
</section>

<section class="section-block compact-panel">
  <div class="section-title row-title"><div><h3>Credential Semua Device</h3><p>Reveal/Copy hanya berlaku selepas permintaan dan direkodkan dalam audit log.</p></div><input id="vaultSearch" class="search-input small-search" placeholder="Cari nama / IP / jenis..."></div>
  <div class="device-table-wrap">
    <table class="device-table vault-table" id="vaultTable">
      <thead><tr><th>Jenis</th><th>Device</th><th>IP</th><th>Status Credential</th><th>Username</th><th>Password</th><th>Tindakan</th></tr></thead>
      <tbody>
      <?php foreach ($devices as $id => $d): $has = isset($stored[$id]); ?>
      <tr data-device-id="<?=h($id)?>">
        <td><span class="device-type-badge type-<?=h(strtolower((string)($d['type'] ?? 'other')))?>"><?=h($d['type'] ?? 'Other')?></span></td>
        <td><b><?=h($d['name'] ?? '')?></b><small><?=h($d['model'] ?? '')?></small></td>
        <td><code><?=h($d['ip'] ?? '')?></code></td>
        <td><span class="vault-state <?=$has?'saved':'empty'?>"><?=$has?'Tersimpan':'Belum ada'?></span></td>
        <td class="vault-username-cell">••••••</td>
        <td class="vault-password-cell">••••••••••</td>
        <td class="vault-actions">
          <?php if ($has): ?>
          <button type="button" class="vault-mini-btn reveal" data-vault-action="reveal">Reveal</button>
          <button type="button" class="vault-mini-btn copy" data-vault-action="copy">Copy</button>
          <button type="button" class="vault-mini-btn edit" data-vault-action="edit">Edit</button>
          <form method="post" class="inline-form" onsubmit="return confirm('Padam credential device ini?')"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="delete_credential"><input type="hidden" name="device_id" value="<?=h($id)?>"><button type="submit" class="vault-mini-btn delete">Padam</button></form>
          <?php else: ?><button type="button" class="vault-mini-btn add" data-vault-action="add">Tambah</button><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="section-block compact-panel">
  <div class="section-title"><div><h3>Audit Log Terkini</h3><p>Aktiviti unlock, reveal, copy, simpan dan padam.</p></div></div>
  <div class="vault-audit-list">
    <?php foreach ($audit as $a): ?><div><time><?=h($a['created_at'] ?? '')?></time><b><?=h($a['event_type'] ?? '')?></b><span><?=h($a['device_name'] ?? 'Vault')?></span><small><?=h($a['ip_address'] ?? '')?></small></div><?php endforeach; ?>
    <?php if (!$audit): ?><p>Belum ada audit log.</p><?php endif; ?>
  </div>
</section>
<script>window.ZURIE_VAULT={csrf:<?=json_encode($csrf)?>,api:'../api/vault_reveal.php'};</script>
<script src="../assets/js/vault.js"></script>
<?php endif; ?>
</div>
</body>
</html>

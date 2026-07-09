<?php
// Shared PostgreSQL session login for export modules.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => false,
        'path' => '/zurie/',
    ]);
    session_start();
}

const EXPORT_PG_SESSION_KEY = 'zurie_export_pg';
const EXPORT_PG_TIMEOUT = 1800; // 30 minit

function export_pg_load_file_defaults(): array
{
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], "/\\") : '';
    $candidates = [
        __DIR__ . '/ilmu_pg_config.php',
        dirname(__DIR__) . '/config/ilmu_pg_config.php',
        $documentRoot !== '' ? $documentRoot . '/zurie/config/ilmu_pg_config.php' : '',
        'C:/xampp_baru/htdocs/zurie/config/ilmu_pg_config.php',
    ];

    $loadedConfig = [];
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            $loaded = require $candidate;
            if (is_array($loaded)) {
                $loadedConfig = $loaded;
                break;
            }
        }
    }

    return [
        'host' => trim((string)($loadedConfig['host'] ?? $loadedConfig['server'] ?? '10.14.48.75')),
        'port' => (int)($loadedConfig['port'] ?? 5432),
        'dbname' => trim((string)($loadedConfig['dbname'] ?? $loadedConfig['database'] ?? $loadedConfig['db'] ?? '')),
        'user' => '',
        'password' => '',
        'sslmode' => trim((string)($loadedConfig['sslmode'] ?? 'disable')),
        'config_path' => 'Login session PostgreSQL',
        'config_found' => true,
    ];
}

function export_pg_clear_session(): void
{
    unset($_SESSION[EXPORT_PG_SESSION_KEY]);
}

function export_pg_session_expired(): bool
{
    $data = $_SESSION[EXPORT_PG_SESSION_KEY] ?? null;
    if (!is_array($data)) {
        return true;
    }

    $lastActivity = (int)($data['last_activity'] ?? 0);
    if ($lastActivity <= 0 || (time() - $lastActivity) > EXPORT_PG_TIMEOUT) {
        export_pg_clear_session();
        return true;
    }

    return false;
}

function export_pg_session_ready(): bool
{
    if (export_pg_session_expired()) {
        return false;
    }

    $data = $_SESSION[EXPORT_PG_SESSION_KEY] ?? [];
    return is_array($data)
        && trim((string)($data['host'] ?? '')) !== ''
        && trim((string)($data['dbname'] ?? '')) !== ''
        && trim((string)($data['user'] ?? '')) !== '';
}

function export_pg_session_config(): array
{
    $defaults = export_pg_load_file_defaults();
    if (!export_pg_session_ready()) {
        return $defaults;
    }

    $data = $_SESSION[EXPORT_PG_SESSION_KEY];
    $_SESSION[EXPORT_PG_SESSION_KEY]['last_activity'] = time();

    return [
        'host' => trim((string)$data['host']),
        'port' => (int)$data['port'],
        'dbname' => trim((string)$data['dbname']),
        'user' => trim((string)$data['user']),
        'password' => (string)$data['password'],
        'sslmode' => trim((string)($data['sslmode'] ?? 'disable')),
        'config_path' => 'Login session PostgreSQL',
        'config_found' => true,
    ];
}

function export_pg_csrf_token(): string
{
    if (empty($_SESSION['export_pg_csrf'])) {
        $_SESSION['export_pg_csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['export_pg_csrf'];
}

function export_pg_verify_csrf(string $token): bool
{
    $stored = (string)($_SESSION['export_pg_csrf'] ?? '');
    return $stored !== '' && $token !== '' && hash_equals($stored, $token);
}

function export_pg_try_connect(array $config): PDO
{
    if (!class_exists('PDO') || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO PostgreSQL belum aktif dalam PHP.');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        $config['host'],
        $config['port'],
        $config['dbname'],
        $config['sslmode'] !== '' ? $config['sslmode'] : 'disable'
    );

    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $pdo->query('SELECT 1');
    return $pdo;
}

function export_pg_handle_request(): array
{
    $result = ['error' => '', 'success' => ''];
    $pgAction = (string)($_POST['pg_action'] ?? '');
    if ($pgAction === '') {
        if (export_pg_session_expired() && isset($_SESSION['export_pg_expired_notice'])) {
            unset($_SESSION['export_pg_expired_notice']);
        }
        return $result;
    }

    $csrf = (string)($_POST['pg_csrf'] ?? '');
    if (!export_pg_verify_csrf($csrf)) {
        $result['error'] = 'Sesi borang tidak sah. Refresh page dan cuba semula.';
        return $result;
    }

    if ($pgAction === 'logout') {
        export_pg_clear_session();
        $result['success'] = 'Sambungan PostgreSQL telah diputuskan.';
        return $result;
    }

    if ($pgAction !== 'login') {
        return $result;
    }

    $defaults = export_pg_load_file_defaults();
    $config = [
        'host' => trim((string)($_POST['pg_host'] ?? $defaults['host'])),
        'port' => max(1, min(65535, (int)($_POST['pg_port'] ?? $defaults['port']))),
        'dbname' => trim((string)($_POST['pg_dbname'] ?? $defaults['dbname'])),
        'user' => trim((string)($_POST['pg_user'] ?? '')),
        'password' => (string)($_POST['pg_password'] ?? ''),
        'sslmode' => trim((string)($_POST['pg_sslmode'] ?? $defaults['sslmode'])),
    ];

    if ($config['host'] === '' || $config['dbname'] === '' || $config['user'] === '') {
        $result['error'] = 'Host, nama database dan User ID wajib diisi.';
        return $result;
    }

    try {
        export_pg_try_connect($config);
        session_regenerate_id(true);
        $_SESSION[EXPORT_PG_SESSION_KEY] = $config + [
            'connected_at' => time(),
            'last_activity' => time(),
        ];
        $result['success'] = 'Login PostgreSQL berjaya. Page extract kini boleh digunakan.';
    } catch (Throwable $exception) {
        $result['error'] = 'Login PostgreSQL gagal: ' . $exception->getMessage();
    }

    return $result;
}

function export_pg_login_defaults(): array
{
    $defaults = export_pg_load_file_defaults();
    return [
        'host' => (string)($_POST['pg_host'] ?? $defaults['host']),
        'port' => (int)($_POST['pg_port'] ?? $defaults['port']),
        'dbname' => (string)($_POST['pg_dbname'] ?? $defaults['dbname']),
        'user' => (string)($_POST['pg_user'] ?? ''),
        'sslmode' => (string)($_POST['pg_sslmode'] ?? $defaults['sslmode']),
    ];
}

function export_pg_render_login(string $actionUrl): void
{
    $v = export_pg_login_defaults();
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    ?>
    <form class="card pg-login-card" method="post" action="<?= $e($actionUrl) ?>" autocomplete="off">
      <div class="pg-login-head">
        <div>
          <h2>Login PostgreSQL MIS</h2>
          <p>Masukkan ID dan password PostgreSQL sebelum menjalankan extract. Maklumat disimpan dalam session sahaja selama 30 minit.</p>
        </div>
        <span>🔒 SESSION ONLY</span>
      </div>
      <input type="hidden" name="pg_action" value="login">
      <input type="hidden" name="pg_csrf" value="<?= $e(export_pg_csrf_token()) ?>">
      <div class="grid pg-login-grid">
        <div class="field"><label>Host PostgreSQL</label><input name="pg_host" value="<?= $e($v['host']) ?>" required></div>
        <div class="field"><label>Port</label><input name="pg_port" type="number" min="1" max="65535" value="<?= (int)$v['port'] ?>" required></div>
        <div class="field"><label>Nama Database</label><input name="pg_dbname" value="<?= $e($v['dbname']) ?>" placeholder="Nama database MIS" required></div>
        <div class="field"><label>User ID PostgreSQL</label><input name="pg_user" value="<?= $e($v['user']) ?>" autocomplete="username" required></div>
        <div class="field"><label>Password PostgreSQL</label><input name="pg_password" type="password" autocomplete="current-password" required></div>
        <div class="field"><label>SSL Mode</label><input name="pg_sslmode" value="<?= $e($v['sslmode']) ?>" placeholder="disable"></div>
      </div>
      <div class="actions">
        <button class="btn primary" type="submit">Login & Sambung PostgreSQL</button>
      </div>
    </form>
    <?php
}

function export_pg_render_connected(string $actionUrl): void
{
    $config = export_pg_session_config();
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    ?>
    <form class="pg-connected" method="post" action="<?= $e($actionUrl) ?>">
      <input type="hidden" name="pg_action" value="logout">
      <input type="hidden" name="pg_csrf" value="<?= $e(export_pg_csrf_token()) ?>">
      <span><i></i> PostgreSQL Connected</span>
      <b><?= $e($config['user']) ?> @ <?= $e($config['host']) ?> / <?= $e($config['dbname']) ?></b>
      <button class="btn" type="submit">Putus Sambungan</button>
    </form>
    <?php
}

<?php
declare(strict_types=1);

/**
 * Temporary PostgreSQL authentication for export pages.
 *
 * - Host/database stay in config/ilmu_pg_config.php.
 * - Username/password from that config are intentionally ignored.
 * - Credentials are accepted only through the login form and kept temporarily
 *   in the PHP session for the current export page scope.
 */

require_once __DIR__ . '/security.php';

function zurie_pg_runtime_base_config(): array
{
    $path = dirname(__DIR__) . '/config/ilmu_pg_config.php';
    $loaded = [];
    if (is_file($path)) {
        $value = require $path;
        if (is_array($value)) {
            $loaded = $value;
        }
    }

    return [
        'host' => trim((string)($loaded['host'] ?? getenv('ILMU_PG_HOST') ?: '')),
        'port' => (int)($loaded['port'] ?? getenv('ILMU_PG_PORT') ?: 5432),
        'dbname' => trim((string)($loaded['dbname'] ?? getenv('ILMU_PG_DBNAME') ?: '')),
        // Never read saved database credentials from disk or environment.
        'user' => '',
        'password' => '',
        'sslmode' => trim((string)($loaded['sslmode'] ?? getenv('ILMU_PG_SSLMODE') ?: 'prefer')),
        'config_path' => $path,
    ];
}

function zurie_pg_runtime_scope_key(string $scope): string
{
    return 'zurie_pg_runtime_' . preg_replace('/[^a-z0-9_\-]/i', '_', $scope);
}

function zurie_pg_runtime_timeout(): int
{
    return 600; // 10 minutes
}

function zurie_pg_runtime_credentials(string $scope): ?array
{
    zurie_security_start_session();
    $key = zurie_pg_runtime_scope_key($scope);
    $entry = $_SESSION[$key] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    $expires = (int)($entry['expires'] ?? 0);
    $user = trim((string)($entry['user'] ?? ''));
    $password = (string)($entry['password'] ?? '');
    if ($expires <= time() || $user === '' || $password === '') {
        unset($_SESSION[$key]);
        return null;
    }

    // Sliding expiry while the page is actively used.
    $_SESSION[$key]['expires'] = time() + zurie_pg_runtime_timeout();
    return ['user' => $user, 'password' => $password, 'expires' => $expires];
}

function zurie_pg_runtime_config(string $scope): array
{
    $config = zurie_pg_runtime_base_config();
    $credentials = zurie_pg_runtime_credentials($scope);
    if ($credentials !== null) {
        $config['user'] = $credentials['user'];
        $config['password'] = $credentials['password'];
    }
    return $config;
}

function zurie_pg_runtime_connect_with(array $config, string $user, string $password): PDO
{
    if (!class_exists('PDO') || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO PostgreSQL belum aktif dalam PHP.');
    }
    if ($config['host'] === '' || $config['dbname'] === '') {
        throw new RuntimeException('Host atau nama database PostgreSQL belum ditetapkan.');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=8',
        $config['host'],
        (int)$config['port'],
        $config['dbname'],
        $config['sslmode'] !== '' ? $config['sslmode'] : 'prefer'
    );

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function zurie_pg_runtime_attempt_file(): string
{
    return dirname(__DIR__) . '/data/pg_login_attempts.json';
}

function zurie_pg_runtime_attempt_id(string $scope): string
{
    return hash('sha256', zurie_security_client_ip() . '|' . $scope);
}

function zurie_pg_runtime_read_attempts(): array
{
    $path = zurie_pg_runtime_attempt_file();
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function zurie_pg_runtime_write_attempts(array $attempts): void
{
    $path = zurie_pg_runtime_attempt_file();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $now = time();
    foreach ($attempts as $key => $entry) {
        if (!is_array($entry) || (int)($entry['updated'] ?? 0) < ($now - 86400)) {
            unset($attempts[$key]);
        }
    }
    @file_put_contents($path, json_encode($attempts, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function zurie_pg_runtime_lock_remaining(string $scope): int
{
    $attempts = zurie_pg_runtime_read_attempts();
    $entry = $attempts[zurie_pg_runtime_attempt_id($scope)] ?? [];
    return max(0, (int)($entry['locked_until'] ?? 0) - time());
}

function zurie_pg_runtime_record_failure(string $scope): void
{
    $attempts = zurie_pg_runtime_read_attempts();
    $id = zurie_pg_runtime_attempt_id($scope);
    $entry = is_array($attempts[$id] ?? null) ? $attempts[$id] : [];
    $first = (int)($entry['first'] ?? 0);
    if ($first === 0 || $first < time() - 900) {
        $entry = ['count' => 0, 'first' => time(), 'locked_until' => 0];
    }
    $entry['count'] = (int)($entry['count'] ?? 0) + 1;
    $entry['updated'] = time();
    if ($entry['count'] >= 5) {
        $entry['locked_until'] = time() + 900;
    }
    $attempts[$id] = $entry;
    zurie_pg_runtime_write_attempts($attempts);
}

function zurie_pg_runtime_clear_failures(string $scope): void
{
    $attempts = zurie_pg_runtime_read_attempts();
    unset($attempts[zurie_pg_runtime_attempt_id($scope)]);
    zurie_pg_runtime_write_attempts($attempts);
}

function zurie_pg_runtime_logout(string $scope): void
{
    zurie_security_start_session();
    unset($_SESSION[zurie_pg_runtime_scope_key($scope)]);
}

function zurie_pg_runtime_current_path(): string
{
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    return $path !== '' ? $path : '/zurie/';
}

function zurie_pg_runtime_render_login(string $scope, string $title, string $error = ''): never
{
    $config = zurie_pg_runtime_base_config();
    zurie_security_headers();
    http_response_code($error === '' ? 200 : 401);
    header('Content-Type: text/html; charset=UTF-8');
    $csrf = zurie_security_csrf_token();
    $https = zurie_security_is_https();
    $lock = zurie_pg_runtime_lock_remaining($scope);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeError = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
    $host = htmlspecialchars($config['host'], ENT_QUOTES, 'UTF-8');
    $db = htmlspecialchars($config['dbname'], ENT_QUOTES, 'UTF-8');
    $path = htmlspecialchars(zurie_pg_runtime_current_path(), ENT_QUOTES, 'UTF-8');

    echo '<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Log Masuk PostgreSQL</title><style>';
    echo 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#07111f;color:#eaf4ff;font-family:Segoe UI,Arial,sans-serif;padding:20px;box-sizing:border-box}';
    echo '.box{width:min(440px,100%);padding:26px;border:1px solid rgba(120,170,215,.22);border-radius:18px;background:linear-gradient(145deg,#0e2035,#0a1727);box-shadow:0 24px 80px rgba(0,0,0,.35)}';
    echo 'h1{font-size:21px;margin:0 0 5px}.sub{color:#89a0b7;font-size:13px;margin:0 0 20px}.target{padding:10px 12px;border-radius:10px;background:rgba(255,255,255,.035);font-size:12px;color:#a9bfd3;margin-bottom:16px}';
    echo 'label{display:block;margin:12px 0 5px;color:#9eb2c5;font-size:12px;font-weight:700}input{width:100%;box-sizing:border-box;padding:12px;border-radius:10px;border:1px solid rgba(120,170,215,.22);background:#071421;color:#fff;font-size:14px}';
    echo 'button{width:100%;margin-top:18px;padding:12px;border:0;border-radius:10px;background:#2388e8;color:#fff;font-weight:800;cursor:pointer}.err,.warn{padding:10px 12px;border-radius:10px;margin:0 0 14px;font-size:12px}.err{background:rgba(255,92,111,.12);color:#ff9cab;border:1px solid rgba(255,92,111,.2)}.warn{background:rgba(255,190,70,.1);color:#ffd37d;border:1px solid rgba(255,190,70,.18)}';
    echo '.foot{margin-top:15px;color:#70879d;font-size:11px;line-height:1.5}.back{display:inline-block;margin-top:14px;color:#8ed7ff;text-decoration:none}</style></head><body><main class="box">';
    echo '<h1>' . $safeTitle . '</h1><p class="sub">Masukkan ID dan kata laluan PostgreSQL untuk sambungan sementara.</p>';
    echo '<div class="target">Server: <b>' . $host . '</b><br>Database: <b>' . $db . '</b><br>Tempoh sambungan: <b>10 minit</b></div>';
    if (!$https) {
        echo '<div class="warn">Amaran: portal masih menggunakan HTTP. Gunakan hanya melalui rangkaian dalaman yang dipercayai kerana kata laluan boleh dipintas pada rangkaian tidak selamat.</div>';
    }
    if ($lock > 0) {
        echo '<div class="err">Terlalu banyak cubaan gagal. Cuba semula dalam ' . (int)ceil($lock / 60) . ' minit.</div>';
    } elseif ($safeError !== '') {
        echo '<div class="err">' . $safeError . '</div>';
    }
    echo '<form method="post" action="' . $path . '" autocomplete="off">';
    echo '<input type="hidden" name="_csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">';
    echo '<input type="hidden" name="pg_runtime_action" value="login">';
    echo '<label for="pg_user">ID PostgreSQL</label><input id="pg_user" name="pg_user" autocomplete="username" required' . ($lock > 0 ? ' disabled' : '') . '>';
    echo '<label for="pg_password">Kata Laluan PostgreSQL</label><input id="pg_password" name="pg_password" type="password" autocomplete="current-password" required' . ($lock > 0 ? ' disabled' : '') . '>';
    echo '<button type="submit"' . ($lock > 0 ? ' disabled' : '') . '>Sambung ke PostgreSQL</button></form>';
    echo '<p class="foot">ID dan kata laluan tidak dibaca daripada fail config. Ia disimpan sementara dalam sesi PHP untuk page ini sahaja dan dibuang apabila tamat tempoh atau diputuskan.</p>';
    echo '<a class="back" href="/zurie/">← Kembali ke Dashboard</a></main></body></html>';
    exit;
}

function zurie_pg_runtime_gate(string $scope, string $title): void
{
    zurie_security_protect_sensitive_page();
    zurie_security_start_session();

    $runtimeAction = (string)($_POST['pg_runtime_action'] ?? '');
    if ($runtimeAction === 'logout') {
        zurie_security_require_valid_csrf();
        zurie_pg_runtime_logout($scope);
        header('Location: ' . zurie_pg_runtime_current_path());
        exit;
    }

    if ($runtimeAction === 'login') {
        zurie_security_require_valid_csrf();
        $remaining = zurie_pg_runtime_lock_remaining($scope);
        if ($remaining > 0) {
            zurie_pg_runtime_render_login($scope, $title, 'Cubaan log masuk dikunci sementara.');
        }

        $user = trim((string)($_POST['pg_user'] ?? ''));
        $password = (string)($_POST['pg_password'] ?? '');
        if ($user === '' || $password === '') {
            zurie_pg_runtime_render_login($scope, $title, 'ID dan kata laluan diperlukan.');
        }

        try {
            $pdo = zurie_pg_runtime_connect_with(zurie_pg_runtime_base_config(), $user, $password);
            $pdo->query('SELECT 1')->fetchColumn();
            $pdo = null;
            session_regenerate_id(true);
            $_SESSION[zurie_pg_runtime_scope_key($scope)] = [
                'user' => $user,
                'password' => $password,
                'expires' => time() + zurie_pg_runtime_timeout(),
            ];
            zurie_pg_runtime_clear_failures($scope);
            header('Location: ' . zurie_pg_runtime_current_path());
            exit;
        } catch (Throwable $e) {
            zurie_pg_runtime_record_failure($scope);
            error_log('[ZURIE PG LOGIN][' . $scope . '] ' . $e->getMessage());
            zurie_pg_runtime_render_login($scope, $title, 'ID atau kata laluan PostgreSQL tidak sah, atau server tidak dapat dicapai.');
        }
    }

    if (zurie_pg_runtime_credentials($scope) === null) {
        zurie_pg_runtime_render_login($scope, $title);
    }
}

function zurie_pg_runtime_widget(string $scope): void
{
    $credentials = zurie_pg_runtime_credentials($scope);
    if ($credentials === null) {
        return;
    }
    $user = htmlspecialchars($credentials['user'], ENT_QUOTES, 'UTF-8');
    $csrf = htmlspecialchars(zurie_security_csrf_token(), ENT_QUOTES, 'UTF-8');
    echo '<div style="position:fixed;right:16px;bottom:16px;z-index:9999;display:flex;align-items:center;gap:9px;padding:8px 10px;border:1px solid rgba(81,227,164,.25);border-radius:11px;background:rgba(6,20,33,.96);box-shadow:0 12px 35px rgba(0,0,0,.35);font:11px Segoe UI,Arial;color:#a8bfd2">';
    echo '<span><b style="color:#58e3aa">● PostgreSQL</b> ' . $user . '</span>';
    echo '<form method="post" style="margin:0"><input type="hidden" name="_csrf" value="' . $csrf . '"><input type="hidden" name="pg_runtime_action" value="logout"><button type="submit" style="border:1px solid rgba(255,113,131,.3);border-radius:7px;background:rgba(255,113,131,.09);color:#ff9aaa;padding:5px 8px;cursor:pointer;font-size:10px">Putuskan</button></form></div>';
}

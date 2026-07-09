<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

/**
 * Shared portal authentication helpers.
 * This file now uses the same config and PHP session as /zurie/login.php.
 */

function zurie_portal_config_path(): string
{
    return dirname(__DIR__) . '/config/portal_auth.php';
}

function zurie_portal_auth_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $defaults = [
        'username' => '',
        'display_name' => '',
        'password_hash' => '',
        'idle_timeout_seconds' => 3600,
        'absolute_timeout_seconds' => 43200,
        'max_failed_attempts' => 5,
        'lock_seconds' => 900,
    ];

    $path = zurie_portal_config_path();
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $defaults = array_replace($defaults, $loaded);
            $defaults['idle_timeout_seconds'] = (int)($loaded['idle_timeout_seconds'] ?? $loaded['idle_timeout'] ?? $defaults['idle_timeout_seconds']);
            $defaults['absolute_timeout_seconds'] = (int)($loaded['absolute_timeout_seconds'] ?? $loaded['absolute_timeout'] ?? $defaults['absolute_timeout_seconds']);
        }
    }

    $config = $defaults;
    return $config;
}

function zurie_portal_auth_configured(): bool
{
    $config = zurie_portal_auth_config();
    $info = password_get_info((string)$config['password_hash']);
    return trim((string)$config['username']) !== ''
        && (($info['algoName'] ?? 'unknown') !== 'unknown');
}

function zurie_portal_safe_next(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '/zurie/index.php';
    }

    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return '/zurie/index.php';
    }

    $path = (string)($parts['path'] ?? '');
    if (!str_starts_with($path, '/zurie/')) {
        return '/zurie/index.php';
    }

    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $path . $query;
}

function zurie_portal_current_uri(): string
{
    return zurie_portal_safe_next((string)($_SERVER['REQUEST_URI'] ?? '/zurie/index.php'));
}

function zurie_portal_login_url(string $next = '/zurie/index.php'): string
{
    return '/zurie/login.php?next=' . rawurlencode(zurie_portal_safe_next($next));
}

function zurie_portal_user_agent_hash(): string
{
    return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
}

function zurie_portal_logout(): void
{
    zurie_security_start_session();

    unset(
        $_SESSION['portal_authenticated'],
        $_SESSION['portal_username'],
        $_SESSION['portal_display_name'],
        $_SESSION['portal_login_time'],
        $_SESSION['portal_last_activity'],
        $_SESSION['portal_user_agent_hash'],
        $_SESSION['zurie_portal_auth']
    );

    foreach (array_keys($_SESSION) as $key) {
        if (str_starts_with((string)$key, 'zurie_pg_runtime_')) {
            unset($_SESSION[$key]);
        }
    }
}

function zurie_portal_is_authenticated(): bool
{
    zurie_security_start_session();
    $config = zurie_portal_auth_config();

    // Migrate a valid legacy session to the single current session format.
    if (empty($_SESSION['portal_authenticated']) && is_array($_SESSION['zurie_portal_auth'] ?? null)) {
        $legacy = $_SESSION['zurie_portal_auth'];
        $_SESSION['portal_authenticated'] = true;
        $_SESSION['portal_username'] = (string)($legacy['username'] ?? '');
        $_SESSION['portal_display_name'] = (string)($config['display_name'] ?? $legacy['username'] ?? '');
        $_SESSION['portal_login_time'] = (int)($legacy['login_at'] ?? 0);
        $_SESSION['portal_last_activity'] = (int)($legacy['last_activity'] ?? 0);
        $_SESSION['portal_user_agent_hash'] = (string)($legacy['ua_hash'] ?? '');
    }

    $now = time();
    $loginAt = (int)($_SESSION['portal_login_time'] ?? 0);
    $lastActivity = (int)($_SESSION['portal_last_activity'] ?? 0);
    $username = (string)($_SESSION['portal_username'] ?? '');
    $uaHash = (string)($_SESSION['portal_user_agent_hash'] ?? '');

    $idle = max(300, (int)$config['idle_timeout_seconds']);
    $absolute = max($idle, (int)$config['absolute_timeout_seconds']);

    $valid = !empty($_SESSION['portal_authenticated'])
        && $username !== ''
        && hash_equals((string)$config['username'], $username)
        && $loginAt > 0
        && $lastActivity > 0
        && ($now - $lastActivity) <= $idle
        && ($now - $loginAt) <= $absolute
        && $uaHash !== ''
        && hash_equals($uaHash, zurie_portal_user_agent_hash());

    if (!$valid) {
        zurie_portal_logout();
        return false;
    }

    $_SESSION['portal_last_activity'] = $now;
    if (isset($_SESSION['zurie_portal_auth']) && is_array($_SESSION['zurie_portal_auth'])) {
        $_SESSION['zurie_portal_auth']['last_activity'] = $now;
    }
    return true;
}

function zurie_portal_login_success(string $username): void
{
    zurie_security_start_session();
    session_regenerate_id(true);
    $now = time();
    $uaHash = zurie_portal_user_agent_hash();
    $config = zurie_portal_auth_config();

    $_SESSION['portal_authenticated'] = true;
    $_SESSION['portal_username'] = $username;
    $_SESSION['portal_display_name'] = (string)($config['display_name'] ?: $username);
    $_SESSION['portal_login_time'] = $now;
    $_SESSION['portal_last_activity'] = $now;
    $_SESSION['portal_user_agent_hash'] = $uaHash;
    $_SESSION['zurie_portal_auth'] = [
        'username' => $username,
        'login_at' => $now,
        'last_activity' => $now,
        'ua_hash' => $uaHash,
    ];
}

function zurie_portal_attempt_file(): string
{
    return dirname(__DIR__) . '/data/portal_login_attempts.json';
}

function zurie_portal_attempt_id(): string
{
    return hash('sha256', zurie_security_client_ip());
}

function zurie_portal_read_attempts(): array
{
    $path = zurie_portal_attempt_file();
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function zurie_portal_write_attempts(array $attempts): void
{
    $path = zurie_portal_attempt_file();
    $now = time();
    foreach ($attempts as $key => $entry) {
        if (!is_array($entry) || (int)($entry['updated'] ?? 0) < ($now - 86400)) {
            unset($attempts[$key]);
        }
    }
    @file_put_contents($path, json_encode($attempts, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function zurie_portal_lock_remaining(): int
{
    $attempts = zurie_portal_read_attempts();
    $entry = $attempts[zurie_portal_attempt_id()] ?? [];
    return max(0, (int)($entry['locked_until'] ?? 0) - time());
}

function zurie_portal_record_failure(): void
{
    $config = zurie_portal_auth_config();
    $attempts = zurie_portal_read_attempts();
    $id = zurie_portal_attempt_id();
    $entry = is_array($attempts[$id] ?? null) ? $attempts[$id] : [];
    $first = (int)($entry['first'] ?? 0);
    if ($first === 0 || $first < time() - 900) {
        $entry = ['count' => 0, 'first' => time(), 'locked_until' => 0];
    }
    $entry['count'] = (int)($entry['count'] ?? 0) + 1;
    $entry['updated'] = time();
    if ($entry['count'] >= max(3, (int)$config['max_failed_attempts'])) {
        $entry['locked_until'] = time() + max(300, (int)$config['lock_seconds']);
    }
    $attempts[$id] = $entry;
    zurie_portal_write_attempts($attempts);
}

function zurie_portal_clear_failures(): void
{
    $attempts = zurie_portal_read_attempts();
    unset($attempts[zurie_portal_attempt_id()]);
    zurie_portal_write_attempts($attempts);
}

function zurie_portal_verify_login(string $username, string $password): bool
{
    $config = zurie_portal_auth_config();
    if (!hash_equals((string)$config['username'], $username)) {
        password_verify($password, '$2y$10$2b2WZf6HlbmQ7f7PpN1hXeCQtiJ7lR/bcbLFXjHc4Qq9pN0pQ8fTa');
        return false;
    }
    return password_verify($password, (string)$config['password_hash']);
}

function zurie_portal_require_login(): void
{
    zurie_security_start_session();
    zurie_security_headers();

    if (!zurie_portal_auth_configured()) {
        header('Location: /zurie/setup_login.php');
        exit;
    }

    if (!zurie_portal_is_authenticated()) {
        $_SESSION['portal_return_to'] = zurie_portal_current_uri();
        header('Location: /zurie/login.php');
        exit;
    }
}

function zurie_portal_require_extract_access(): void
{
    zurie_portal_require_login();
    zurie_security_require_extract_network();
}

function zurie_portal_current_username(): string
{
    zurie_security_start_session();
    return (string)($_SESSION['portal_username'] ?? '');
}

function zurie_portal_logout_form(): void
{
    $csrf = htmlspecialchars(zurie_security_csrf_token(), ENT_QUOTES, 'UTF-8');
    $user = htmlspecialchars(zurie_portal_current_username(), ENT_QUOTES, 'UTF-8');
    echo '<form method="post" action="/zurie/logout.php" style="margin:0;display:flex;align-items:center;gap:6px" title="Log keluar ' . $user . '">';
    echo '<input type="hidden" name="_csrf" value="' . $csrf . '">';
    echo '<button class="top-icon-button" type="submit" aria-label="Log keluar" title="Log keluar">⎋</button>';
    echo '</form>';
}

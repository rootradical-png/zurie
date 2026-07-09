<?php
declare(strict_types=1);

/**
 * Shared security helpers for Personal NOC Dashboard.
 * PHP 8.2 compatible.
 */

function zurie_security_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $defaults = [
        'enforce_private_network' => true,
        'allowed_cidrs' => [
            '127.0.0.1/32',
            '::1/128',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            'fc00::/7',
            'fe80::/10',
        ],
        // Rangkaian khusus yang dibenarkan menggunakan modul ekstrak data.
        // Default: subnet KMP 10.14.0.0/16. Tambah CIDR VPN KPM dalam config/security_config.php.
        'extract_allowed_cidrs' => [
            '127.0.0.1/32',
            '::1/128',
            '10.14.0.0/16',
        ],
        'trust_proxy_headers' => false,
        'trusted_proxy_cidrs' => [],
        'session_name' => 'ZURIESEC',
        'csrf_ttl_seconds' => 7200,
    ];

    $path = dirname(__DIR__) . '/config/security_config.php';
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $defaults = array_replace($defaults, $loaded);
        }
    }

    $config = $defaults;
    return $config;
}

function zurie_security_is_https(): bool
{
    return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off';
}

function zurie_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header_remove('X-Powered-By');
}

function zurie_security_is_trusted_proxy_ip(string $ip): bool
{
    $config = zurie_security_config();
    if (empty($config['trust_proxy_headers'])) {
        return false;
    }

    foreach ((array)($config['trusted_proxy_cidrs'] ?? []) as $cidr) {
        if (zurie_security_ip_in_cidr($ip, (string)$cidr)) {
            return true;
        }
    }
    return false;
}

function zurie_security_client_ip(): string
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    // Header asal pengguna hanya dipercayai apabila sambungan terus datang
    // daripada reverse proxy yang disenaraikan dalam trusted_proxy_cidrs.
    if (!zurie_security_is_trusted_proxy_ip($remote)) {
        return $remote;
    }

    // X-Real-IP biasanya ditetapkan terus oleh reverse proxy dan paling jelas.
    $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if (filter_var($realIp, FILTER_VALIDATE_IP)) {
        return $realIp;
    }

    // Parse rantaian X-Forwarded-For dari kanan. Abaikan hop proxy dipercayai
    // dan pulangkan hop pertama yang bukan proxy. Ini mengurangkan spoofing.
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $chain = [];
        foreach (explode(',', $forwarded) as $part) {
            $candidate = trim($part);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $chain[] = $candidate;
            }
        }

        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!zurie_security_is_trusted_proxy_ip($chain[$i])) {
                return $chain[$i];
            }
        }
    }

    // Sokongan header standard RFC Forwarded: for=1.2.3.4
    $forwardedStd = trim((string)($_SERVER['HTTP_FORWARDED'] ?? ''));
    if ($forwardedStd !== '' && preg_match('/(?:^|[,;])\s*for=(?:"?)(\[[0-9a-fA-F:]+\]|[0-9.]+)(?:"?)/', $forwardedStd, $m)) {
        $candidate = trim($m[1], '[]');
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    return $remote;
}

function zurie_security_ip_in_cidr(string $ip, string $cidr): bool
{
    $ip = trim($ip);
    $cidr = trim($cidr);
    if ($ip === '' || $cidr === '') {
        return false;
    }

    if (!str_contains($cidr, '/')) {
        return hash_equals($cidr, $ip);
    }

    [$network, $prefixText] = explode('/', $cidr, 2);
    $prefix = (int)$prefixText;
    $ipBinary = @inet_pton($ip);
    $networkBinary = @inet_pton($network);

    if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
        return false;
    }

    $maxBits = strlen($ipBinary) * 8;
    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }

    $wholeBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;

    if ($wholeBytes > 0 && substr($ipBinary, 0, $wholeBytes) !== substr($networkBinary, 0, $wholeBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($ipBinary[$wholeBytes]) & $mask) === (ord($networkBinary[$wholeBytes]) & $mask);
}

function zurie_security_ip_allowed(string $ip): bool
{
    $config = zurie_security_config();
    foreach ((array)($config['allowed_cidrs'] ?? []) as $cidr) {
        if (zurie_security_ip_in_cidr($ip, (string)$cidr)) {
            return true;
        }
    }
    return false;
}

function zurie_security_extract_ip_allowed(?string $ip = null): bool
{
    $config = zurie_security_config();
    $ip = $ip ?? zurie_security_client_ip();
    foreach ((array)($config['extract_allowed_cidrs'] ?? []) as $cidr) {
        if (zurie_security_ip_in_cidr($ip, (string)$cidr)) {
            return true;
        }
    }
    return false;
}

function zurie_security_require_extract_network(): void
{
    zurie_security_start_session();
    zurie_security_headers();
    if (!zurie_security_extract_ip_allowed()) {
        zurie_security_forbidden(
            'Modul ekstrak data hanya boleh digunakan melalui rangkaian lokal KMP atau VPN KPM. '
            . 'IP semasa belum berada dalam senarai yang dibenarkan.'
        );
    }
}

function zurie_security_forbidden(string $message = 'Akses hanya dibenarkan melalui rangkaian dalaman KMP.'): never
{
    zurie_security_headers();
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ms"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Akses Disekat</title><body style="margin:0;background:#07111f;color:#eaf4ff;font-family:Segoe UI,Arial,sans-serif">';
    echo '<main style="max-width:620px;margin:12vh auto;padding:28px;border:1px solid rgba(130,170,210,.22);border-radius:16px;background:#0d1c2e">';
    echo '<h1 style="font-size:22px">Akses Disekat</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p style="color:#86a0b8">IP: ' . htmlspecialchars(zurie_security_client_ip(), ENT_QUOTES, 'UTF-8') . '</p></main></body></html>';
    exit;
}

function zurie_security_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config = zurie_security_config();

    // Only change the session name before a session starts.
    if (session_status() === PHP_SESSION_NONE) {
        session_name((string)($config['session_name'] ?? 'ZURIESEC'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/zurie/',
            'secure' => zurie_security_is_https(),
            'httponly' => true,
            // Lax is reliable when users arrive from another internal page,
            // while cross-site POST requests still do not receive the cookie.
            'samesite' => 'Lax',
        ]);
    }

    if (!@session_start()) {
        // CSRF still has a signed double-submit cookie fallback below.
        error_log('[ZURIE SECURITY] PHP session could not be started. Check session.save_path permissions.');
    }
}

function zurie_security_protect_sensitive_page(): void
{
    // Start the session before any HTML output. Export pages call this helper
    // at the very top of the file, so the session cookie can be issued safely.
    zurie_security_start_session();
    zurie_security_headers();

    $config = zurie_security_config();
    if (!empty($config['enforce_private_network']) && !zurie_security_ip_allowed(zurie_security_client_ip())) {
        zurie_security_forbidden();
    }
}

function zurie_security_protect_api(): void
{
    zurie_security_headers();
    $config = zurie_security_config();
    if (!empty($config['enforce_private_network']) && !zurie_security_ip_allowed(zurie_security_client_ip())) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Akses rangkaian tidak dibenarkan.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function zurie_security_csrf_cookie_name(): string
{
    return 'ZURIE_CSRF';
}

function zurie_security_set_csrf_cookie(string $token, int $ttl): void
{
    if (headers_sent()) {
        return;
    }

    setcookie(zurie_security_csrf_cookie_name(), $token, [
        'expires' => time() + $ttl,
        'path' => '/zurie/',
        'secure' => zurie_security_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Make the value available during the current request too.
    $_COOKIE[zurie_security_csrf_cookie_name()] = $token;
}

function zurie_security_csrf_token(): string
{
    zurie_security_start_session();
    $config = zurie_security_config();
    $ttl = max(300, (int)($config['csrf_ttl_seconds'] ?? 7200));

    $sessionToken = (string)($_SESSION['zurie_csrf_token'] ?? '');
    $sessionCreated = (int)($_SESSION['zurie_csrf_created'] ?? 0);
    $cookieToken = (string)($_COOKIE[zurie_security_csrf_cookie_name()] ?? '');

    $sessionValid = $sessionToken !== ''
        && $sessionCreated > 0
        && (time() - $sessionCreated) <= $ttl;

    if ($sessionValid) {
        $token = $sessionToken;
    } elseif (preg_match('/^[a-f0-9]{64}$/', $cookieToken) === 1) {
        // Recover gracefully if the PHP session storage was cleared, but the
        // browser still owns the same-site CSRF cookie.
        $token = $cookieToken;
        $_SESSION['zurie_csrf_token'] = $token;
        $_SESSION['zurie_csrf_created'] = time();
    } else {
        $token = bin2hex(random_bytes(32));
        $_SESSION['zurie_csrf_token'] = $token;
        $_SESSION['zurie_csrf_created'] = time();
    }

    if ($cookieToken === '' || !hash_equals($token, $cookieToken)) {
        zurie_security_set_csrf_cookie($token, $ttl);
    }

    return $token;
}

function zurie_security_require_valid_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('POST sahaja.');
    }

    zurie_security_start_session();

    $received = trim((string)($_POST['_csrf'] ?? ''));
    $sessionToken = (string)($_SESSION['zurie_csrf_token'] ?? '');
    $cookieToken = (string)($_COOKIE[zurie_security_csrf_cookie_name()] ?? '');

    $validFormat = preg_match('/^[a-f0-9]{64}$/', $received) === 1;
    $matchesSession = $sessionToken !== '' && hash_equals($sessionToken, $received);
    $matchesCookie = $cookieToken !== '' && hash_equals($cookieToken, $received);

    if (!$validFormat || (!$matchesSession && !$matchesCookie)) {
        http_response_code(419);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="ms"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Sesi Borang Tamat</title><body style="margin:0;background:#07111f;color:#eaf4ff;font-family:Segoe UI,Arial,sans-serif">';
        echo '<main style="max-width:650px;margin:12vh auto;padding:28px;border:1px solid rgba(130,170,210,.22);border-radius:16px;background:#0d1c2e">';
        echo '<h1 style="font-size:22px">Sesi borang tamat</h1><p>Token keselamatan tidak sepadan. Buka semula halaman borang dan cuba sekali lagi.</p>';
        echo '<p><a href="javascript:history.back()" style="color:#8fdcff">Kembali ke halaman sebelumnya</a></p></main></body></html>';
        exit;
    }

    // Restore the session copy when validation succeeded through the cookie.
    if ($sessionToken === '') {
        $_SESSION['zurie_csrf_token'] = $received;
        $_SESSION['zurie_csrf_created'] = time();
    }
}

function zurie_security_csv_cell(mixed $value): string
{
    $text = str_replace("\0", '', (string)$value);
    if ($text !== '' && preg_match('/^[=+\-@\t\r]/u', $text) === 1) {
        return "'" . $text;
    }
    return $text;
}

function zurie_security_csv_row(array $row): array
{
    return array_map(static fn($value): string => zurie_security_csv_cell($value), $row);
}

function zurie_security_log_exception(string $context, Throwable $exception): void
{
    error_log(sprintf('[ZURIE SECURITY][%s] %s in %s:%d', $context, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
}

function zurie_security_public_error(Throwable $exception, string $fallback = 'Operasi gagal. Semak log server atau hubungi pentadbir.'): string
{
    $message = trim($exception->getMessage());
    $allowedPrefixes = [
        'Konfigurasi PostgreSQL belum lengkap',
        'PDO PostgreSQL belum aktif',
        'Tiada rekod untuk dieksport',
        'PHP ZipArchive belum aktif',
        'Nombor batch melebihi jumlah batch',
        'Tidak dapat membuka output CSV',
        'Tidak dapat membina CSV sementara',
    ];

    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($message, $prefix)) {
            return $message;
        }
    }

    return $fallback;
}

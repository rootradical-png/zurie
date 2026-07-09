<?php
declare(strict_types=1);

/**
 * Personal NOC Credential Vault
 * Crypto backend: OpenSSL AES-256-GCM
 * Key derivation: PBKDF2-HMAC-SHA256
 */

function vault_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    if (!headers_sent()) {
        session_name('ZURIE_VAULT');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/zurie/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    session_start();
}

function vault_config(): array
{
    static $config;
    if (is_array($config)) return $config;
    $file = dirname(__DIR__) . '/config/vault_config.php';
    if (!is_file($file)) throw new RuntimeException('Fail config/vault_config.php tidak ditemui.');
    $config = require $file;
    if (!is_array($config) || empty($config['dsn'])) throw new RuntimeException('Konfigurasi Credential Vault tidak lengkap.');
    return $config;
}

function vault_pdo(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $c = vault_config();
    $pdo = new PDO((string)$c['dsn'], (string)($c['username'] ?? ''), (string)($c['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function vault_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS vault_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        master_hash VARCHAR(255) NOT NULL,
        kdf_salt VARCHAR(128) NOT NULL,
        unlock_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS device_credentials (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(80) NOT NULL,
        ciphertext LONGTEXT NOT NULL,
        nonce VARCHAR(128) NOT NULL,
        updated_by VARCHAR(100) NOT NULL DEFAULT 'ZURIE',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_device_credentials_device (device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vault_audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(40) NOT NULL,
        device_id VARCHAR(80) DEFAULT NULL,
        device_name VARCHAR(180) DEFAULT NULL,
        actor VARCHAR(100) NOT NULL DEFAULT 'ZURIE',
        ip_address VARCHAR(64) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_vault_audit_created (created_at),
        KEY idx_vault_audit_device (device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vault_settings(PDO $pdo): ?array
{
    $row = $pdo->query('SELECT * FROM vault_settings WHERE id = 1')->fetch();
    return $row ?: null;
}

function vault_crypto_ready(): bool
{
    return extension_loaded('openssl')
        && function_exists('openssl_encrypt')
        && function_exists('openssl_decrypt')
        && function_exists('hash_pbkdf2')
        && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
}

function vault_crypto_error_message(): string
{
    return 'PHP OpenSSL AES-256-GCM tidak tersedia. Pastikan extension OpenSSL aktif pada Apache.';
}

function vault_require_crypto(): void
{
    if (!vault_crypto_ready()) throw new RuntimeException(vault_crypto_error_message());
}

function vault_password_algorithm()
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function vault_generate_kdf_salt(): string
{
    return base64_encode(random_bytes(32));
}

function vault_derive_key(string $password, string $saltEncoded): string
{
    vault_require_crypto();
    $salt = base64_decode($saltEncoded, true);
    if ($salt === false || strlen($salt) < 16) throw new RuntimeException('KDF salt tidak sah.');

    // 600k iterations is intentionally expensive enough for a master-password vault,
    // while remaining practical on the current PHP 8.2 server.
    $key = hash_pbkdf2('sha256', $password, $salt, 600000, 32, true);
    if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('Gagal menjana encryption key.');
    return $key;
}

function vault_store_session_key(string $key, int $minutes): void
{
    session_regenerate_id(true);
    $_SESSION['vault_key'] = base64_encode($key);
    $_SESSION['vault_unlocked_until'] = time() + max(1, $minutes) * 60;
    $_SESSION['vault_last_activity'] = time();
}

function vault_lock(): void
{
    unset($_SESSION['vault_key'], $_SESSION['vault_unlocked_until'], $_SESSION['vault_last_activity']);
    session_regenerate_id(true);
}

function vault_is_unlocked(): bool
{
    if (empty($_SESSION['vault_key']) || empty($_SESSION['vault_unlocked_until'])) return false;
    if ((int)$_SESSION['vault_unlocked_until'] < time()) {
        vault_lock();
        return false;
    }
    return true;
}

function vault_touch(): void
{
    if (!vault_is_unlocked()) return;
    $c = vault_config();
    $_SESSION['vault_unlocked_until'] = time() + max(1, (int)($c['unlock_minutes'] ?? 10)) * 60;
    $_SESSION['vault_last_activity'] = time();
}

function vault_session_key(): string
{
    if (!vault_is_unlocked()) throw new RuntimeException('Vault dikunci.');
    $key = base64_decode((string)$_SESSION['vault_key'], true);
    if ($key === false || strlen($key) !== 32) {
        vault_lock();
        throw new RuntimeException('Session key tidak sah. Sila unlock semula.');
    }
    return $key;
}

function vault_csrf_token(): string
{
    if (empty($_SESSION['vault_csrf'])) $_SESSION['vault_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['vault_csrf'];
}

function vault_verify_csrf(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['vault_csrf']) && hash_equals((string)$_SESSION['vault_csrf'], $token);
}

function vault_encrypt_payload(string $deviceId, array $payload, string $key): array
{
    vault_require_crypto();
    $iv = random_bytes(12);
    $tag = '';
    $plain = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $aad = 'zurie-vault-gcm-v1|' . $deviceId;

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        $aad,
        16
    );
    if ($cipher === false || strlen($tag) !== 16) throw new RuntimeException('Credential gagal diencrypt.');

    return [
        'ciphertext' => 'gcm1:' . base64_encode($cipher . $tag),
        'nonce' => base64_encode($iv),
    ];
}

function vault_decrypt_payload(string $deviceId, string $cipherEncoded, string $nonceEncoded, string $key): array
{
    vault_require_crypto();
    if (!str_starts_with($cipherEncoded, 'gcm1:')) {
        throw new RuntimeException('Format credential lama tidak disokong oleh backend OpenSSL. Simpan semula credential tersebut.');
    }

    $packed = base64_decode(substr($cipherEncoded, 5), true);
    $iv = base64_decode($nonceEncoded, true);
    if ($packed === false || $iv === false || strlen($iv) !== 12 || strlen($packed) < 17) {
        throw new RuntimeException('Data credential tidak sah.');
    }

    $tag = substr($packed, -16);
    $cipher = substr($packed, 0, -16);
    $aad = 'zurie-vault-gcm-v1|' . $deviceId;
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
    if ($plain === false) throw new RuntimeException('Credential gagal didecrypt. Master Password mungkin tidak sepadan atau data telah berubah.');

    $data = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : [];
}

function vault_load_devices(): array
{
    $file = dirname(__DIR__) . '/data/noc_devices.json';
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data)) return [];
    $out = [];
    foreach ($data as $device) {
        if (empty($device['id'])) continue;
        $out[(string)$device['id']] = $device;
    }
    return $out;
}

function vault_audit(PDO $pdo, string $event, ?string $deviceId = null, ?string $deviceName = null): void
{
    try {
        $c = vault_config();
        $stmt = $pdo->prepare('INSERT INTO vault_audit_log (event_type, device_id, device_name, actor, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $event,
            $deviceId,
            $deviceName,
            (string)($c['portal_user'] ?? 'ZURIE'),
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $ignored) {
        // Audit failure must not expose credentials or interrupt the vault action.
    }
}

function vault_unlock_attempt_allowed(): array
{
    $until = (int)($_SESSION['vault_lockout_until'] ?? 0);
    if ($until > time()) return [false, $until - time()];
    return [true, 0];
}

function vault_register_failed_unlock(): void
{
    $count = (int)($_SESSION['vault_failed_unlocks'] ?? 0) + 1;
    $_SESSION['vault_failed_unlocks'] = $count;
    if ($count >= 5) {
        $_SESSION['vault_lockout_until'] = time() + 30;
        $_SESSION['vault_failed_unlocks'] = 0;
    }
}

function vault_clear_failed_unlocks(): void
{
    unset($_SESSION['vault_failed_unlocks'], $_SESSION['vault_lockout_until']);
}

vault_start_session();

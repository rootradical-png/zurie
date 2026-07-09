<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/security.php';
zurie_security_protect_api();
require_once dirname(__DIR__) . '/lib/vault.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function session_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        session_json(['ok' => false, 'message' => 'Method tidak dibenarkan.'], 405);
    }
    if (!vault_verify_csrf($_POST['csrf'] ?? null)) {
        session_json(['ok' => false, 'message' => 'Token keselamatan tidak sah.'], 403);
    }

    $action = strtolower(trim((string)($_POST['action'] ?? 'status')));
    $serverNow = time();

    if (!vault_is_unlocked()) {
        session_json([
            'ok' => true,
            'locked' => true,
            'server_now' => $serverNow,
            'expires_at' => $serverNow,
            'seconds_left' => 0,
            'message' => 'Vault telah dikunci.'
        ]);
    }

    if ($action === 'extend') {
        $pdo = vault_pdo();
        vault_ensure_schema($pdo);
        $settings = vault_settings($pdo);
        $config = vault_config();
        $minutes = max(1, (int)($settings['unlock_minutes'] ?? $config['unlock_minutes'] ?? 10));
        $_SESSION['vault_unlocked_until'] = $serverNow + ($minutes * 60);
        $_SESSION['vault_last_activity'] = $serverNow;
        vault_audit($pdo, 'VAULT_EXTENDED');
    } elseif ($action !== 'status') {
        session_json(['ok' => false, 'message' => 'Tindakan sesi tidak sah.'], 400);
    }

    $expiresAt = (int)($_SESSION['vault_unlocked_until'] ?? $serverNow);
    session_json([
        'ok' => true,
        'locked' => false,
        'server_now' => $serverNow,
        'expires_at' => $expiresAt,
        'seconds_left' => max(0, $expiresAt - $serverNow),
        'message' => $action === 'extend' ? 'Tempoh Vault berjaya disambung.' : 'Status sesi dikemas kini.'
    ]);
} catch (Throwable $e) {
    error_log('[ZURIE VAULT SESSION] ' . get_class($e) . ': ' . $e->getMessage());
    session_json(['ok' => false, 'message' => 'Ralat sesi Credential Vault. Semak log PHP.'], 500);
}

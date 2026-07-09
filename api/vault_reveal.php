<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/security.php';
zurie_security_protect_api();
require_once dirname(__DIR__) . '/lib/vault.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function json_out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['ok' => false, 'message' => 'Method tidak dibenarkan.'], 405);
    }
    if (!vault_verify_csrf($_POST['csrf'] ?? null)) {
        json_out(['ok' => false, 'message' => 'Token keselamatan tidak sah.'], 403);
    }
    if (!vault_is_unlocked()) {
        json_out(['ok' => false, 'message' => 'Vault telah dikunci. Masukkan Master Password semula.'], 401);
    }

    $deviceId = trim((string)($_POST['device_id'] ?? ''));
    $purpose = strtoupper(trim((string)($_POST['purpose'] ?? 'REVEAL')));
    $allowedPurposes = ['REVEAL', 'COPY_USERNAME', 'COPY_PASSWORD', 'EDIT'];
    if (!in_array($purpose, $allowedPurposes, true)) {
        json_out(['ok' => false, 'message' => 'Tindakan tidak sah.'], 400);
    }

    $devices = vault_load_devices();
    if ($deviceId === '' || !isset($devices[$deviceId])) {
        json_out(['ok' => false, 'message' => 'Peranti tidak sah.'], 400);
    }

    $pdo = vault_pdo();
    vault_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT ciphertext, nonce FROM device_credentials WHERE device_id = ?');
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_out(['ok' => false, 'message' => 'Credential belum disimpan untuk peranti ini.'], 404);
    }

    $payload = vault_decrypt_payload(
        $deviceId,
        (string)$row['ciphertext'],
        (string)$row['nonce'],
        vault_session_key()
    );

    vault_touch();
    vault_audit($pdo, $purpose, $deviceId, (string)($devices[$deviceId]['name'] ?? $deviceId));

    $response = [
        'ok' => true,
        'device_id' => $deviceId,
        'hide_after_seconds' => 20,
    ];

    // Return only the minimum data required for each action.
    if ($purpose === 'COPY_USERNAME') {
        $response['username'] = (string)($payload['username'] ?? '');
    } elseif ($purpose === 'COPY_PASSWORD') {
        $response['password'] = (string)($payload['password'] ?? '');
    } elseif ($purpose === 'EDIT') {
        $response['username'] = (string)($payload['username'] ?? '');
        $response['password'] = (string)($payload['password'] ?? '');
        $response['notes'] = (string)($payload['notes'] ?? '');
    } else { // REVEAL
        $response['username'] = (string)($payload['username'] ?? '');
        $response['password'] = (string)($payload['password'] ?? '');
    }

    json_out($response);
} catch (Throwable $e) {
    error_log('[ZURIE VAULT API] ' . get_class($e) . ': ' . $e->getMessage());
    json_out(['ok' => false, 'message' => 'Ralat Credential Vault. Semak log PHP.'], 500);
}

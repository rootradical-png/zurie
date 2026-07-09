<?php
// Server Metrics menggunakan sambungan DB yang sama dengan Credential Vault.
$vaultConfigFile = __DIR__ . '/vault_config.php';
$base = is_file($vaultConfigFile) ? require $vaultConfigFile : [];

return [
    'dsn' => $base['dsn'] ?? 'mysql:host=localhost;dbname=zurie_noc;charset=utf8mb4',
    'username' => $base['username'] ?? 'root',
    'password' => $base['password'] ?? '',
    'timezone' => 'Asia/Kuala_Lumpur',
    'stale_seconds' => 180,
    'history_retention_days' => 7,
    'require_https_push' => false,
    'warning' => [
        'cpu' => 75,
        'memory' => 80,
        'disk' => 80,
    ],
    'critical' => [
        'cpu' => 90,
        'memory' => 92,
        'disk' => 90,
    ],
];

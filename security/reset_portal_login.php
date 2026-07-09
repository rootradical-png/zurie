<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$path = dirname(__DIR__) . '/config/portal_auth_config.php';
if (is_file($path)) {
    $backup = $path . '.bak_' . date('Ymd_His');
    if (!rename($path, $backup)) {
        fwrite(STDERR, "Gagal backup config login.\n");
        exit(1);
    }
    echo "Config login telah di-reset. Backup: {$backup}\n";
} else {
    echo "Config login belum wujud.\n";
}
echo "Buka /zurie/security/setup_portal_login.php dari rangkaian lokal/VPN untuk setup semula.\n";

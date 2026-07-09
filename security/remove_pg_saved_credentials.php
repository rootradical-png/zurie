<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

$path = dirname(__DIR__) . '/config/ilmu_pg_config.php';
if (!is_file($path)) {
    fwrite(STDERR, "Config tidak dijumpai: {$path}\n");
    exit(1);
}

$config = require $path;
if (!is_array($config)) {
    fwrite(STDERR, "Format config tidak sah.\n");
    exit(1);
}

$clean = [
    'host' => (string)($config['host'] ?? ''),
    'port' => (int)($config['port'] ?? 5432),
    'dbname' => (string)($config['dbname'] ?? ''),
    'user' => '',
    'password' => '',
    'sslmode' => (string)($config['sslmode'] ?? 'prefer'),
];

$content = "<?php\n";
$content .= "// PostgreSQL connection target only.\n";
$content .= "// Username/password mesti dimasukkan melalui borang setiap kali page eksport dibuka.\n";
$content .= "return " . var_export($clean, true) . ";\n";

$temp = $path . '.tmp';
if (file_put_contents($temp, $content, LOCK_EX) === false || !rename($temp, $path)) {
    @unlink($temp);
    fwrite(STDERR, "Gagal menulis semula config. Jalankan terminal sebagai Administrator.\n");
    exit(1);
}

fwrite(STDOUT, "OK: Username dan password PostgreSQL telah dibuang daripada config.\n");
fwrite(STDOUT, "Host/database dikekalkan.\n");

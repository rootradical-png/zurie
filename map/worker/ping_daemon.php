<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';
$interval = max(5, (int)($config['worker_interval_seconds'] ?? 10));
$workerFile = __DIR__ . '/ping_worker.php';
$lockFile = dirname(__DIR__) . '/data/ping_daemon.lock';
$stopFile = dirname(__DIR__) . '/data/STOP_PING_DAEMON';

$lockHandle = @fopen($lockFile, 'c');
if ($lockHandle === false || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Ping daemon sudah berjalan.\n");
    exit(0);
}

register_shutdown_function(static function () use ($lockHandle): void {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
});

@set_time_limit(0);
ignore_user_abort(true);

echo '[' . date('Y-m-d H:i:s') . "] ZURIE ping daemon bermula. Interval {$interval}s.\n";
echo "Untuk berhenti secara manual, cipta fail data/STOP_PING_DAEMON.\n";

while (true) {
    if (is_file($stopFile)) {
        @unlink($stopFile);
        echo '[' . date('Y-m-d H:i:s') . "] Arahan berhenti diterima.\n";
        break;
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerFile);
    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        error_log('ZURIE ping worker exit code: ' . $exitCode);
    }

    for ($second = 0; $second < $interval; $second++) {
        if (is_file($stopFile)) {
            break 2;
        }
        sleep(1);
    }
}

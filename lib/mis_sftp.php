<?php
/**
 * Zurie Fasa 4 - Sync gambar repaired ke MIS melalui SFTP.
 * Fail remote sentiasa menggunakan format NOMATRIK.jpg.
 */

declare(strict_types=1);

function zurie_mis_sftp_config(): array
{
    $path = dirname(__DIR__) . '/config/mis_sftp_config.php';
    $config = is_file($path) ? require $path : [];
    return is_array($config) ? $config : [];
}

function zurie_mis_sftp_clean_matrik(string $matrik): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $matrik) ?? '');
}

function zurie_mis_sftp_normalize_remote_dir(string $dir): string
{
    $dir = str_replace('\\', '/', trim($dir));
    if ($dir === '') {
        return '';
    }
    return '/' . trim($dir, '/');
}

function zurie_mis_sftp_config_status(?array $config = null): array
{
    $config = $config ?? zurie_mis_sftp_config();
    $driver = strtolower(trim((string)($config['driver'] ?? '')));
    $missing = [];

    if (empty($config['enabled'])) $missing[] = 'enabled=true';
    if (!in_array($driver, ['winscp', 'ssh2'], true)) $missing[] = 'driver';
    if (trim((string)($config['host'] ?? '')) === '') $missing[] = 'host';
    if (trim((string)($config['username'] ?? '')) === '') $missing[] = 'username';
    if (zurie_mis_sftp_normalize_remote_dir((string)($config['remote_dir'] ?? '')) === '') $missing[] = 'remote_dir';
    if (trim((string)($config['host_key'] ?? '')) === '') $missing[] = 'host_key';

    $hasPassword = trim((string)($config['password'] ?? '')) !== '';
    $hasKey = trim((string)($config['private_key'] ?? '')) !== '';
    if (!$hasPassword && !$hasKey) $missing[] = 'password/private_key';

    if ($driver === 'winscp') {
        $winscp = trim((string)($config['winscp_path'] ?? ''));
        if ($winscp === '') {
            $missing[] = 'winscp_path';
        } elseif (!is_file($winscp)) {
            $missing[] = 'WinSCP.com tidak ditemui';
        }
        if (!function_exists('exec')) $missing[] = 'PHP exec() tidak tersedia';
    }

    if ($driver === 'ssh2' && !extension_loaded('ssh2')) {
        $missing[] = 'extension PHP ssh2';
    }

    return [
        'ready' => $missing === [],
        'driver' => $driver,
        'missing' => $missing,
        'host' => (string)($config['host'] ?? ''),
        'port' => (int)($config['port'] ?? 22),
        'remote_dir' => zurie_mis_sftp_normalize_remote_dir((string)($config['remote_dir'] ?? '')),
    ];
}

function zurie_mis_sftp_winscp_quote(string $value): string
{
    if (preg_match('/[\r\n]/', $value)) {
        throw new RuntimeException('Nilai konfigurasi SFTP mengandungi aksara baris yang tidak dibenarkan.');
    }
    return '"' . str_replace('"', '""', $value) . '"';
}

function zurie_mis_sftp_safe_message(string $message, array $config): string
{
    foreach (['password', 'private_key_passphrase'] as $key) {
        $secret = (string)($config[$key] ?? '');
        if ($secret !== '') {
            $message = str_replace($secret, '***', $message);
            $message = str_replace(rawurlencode($secret), '***', $message);
        }
    }
    return trim($message);
}

function zurie_mis_sftp_winscp_open_command(array $config): string
{
    $host = trim((string)$config['host']);
    $port = max(1, (int)($config['port'] ?? 22));
    $username = rawurlencode((string)$config['username']);
    $password = (string)($config['password'] ?? '');
    $privateKey = trim((string)($config['private_key'] ?? ''));
    $hostKey = trim((string)$config['host_key']);
    $timeout = max(5, min(180, (int)($config['timeout'] ?? 30)));

    if ($privateKey !== '') {
        $command = 'open ' . zurie_mis_sftp_winscp_quote("sftp://{$username}@{$host}:{$port}/");
        $command .= ' -privatekey=' . zurie_mis_sftp_winscp_quote($privateKey);
        $passphrase = (string)($config['private_key_passphrase'] ?? '');
        if ($passphrase !== '') {
            $command .= ' -passphrase=' . zurie_mis_sftp_winscp_quote($passphrase);
        }
    } else {
        $command = 'open ' . zurie_mis_sftp_winscp_quote(
            'sftp://' . $username . ':' . rawurlencode($password) . '@' . $host . ':' . $port . '/'
        );
    }

    $command .= ' -hostkey=' . zurie_mis_sftp_winscp_quote($hostKey);
    $command .= ' -timeout=' . $timeout;
    return $command;
}

function zurie_mis_sftp_run_winscp(array $commands, array $config): array
{
    $winscp = (string)$config['winscp_path'];
    if (!is_file($winscp)) {
        return ['ok' => false, 'message' => 'WinSCP.com tidak ditemui pada path konfigurasi.'];
    }
    if (!function_exists('exec')) {
        return ['ok' => false, 'message' => 'Fungsi PHP exec() tidak tersedia.'];
    }

    $tempBase = tempnam(sys_get_temp_dir(), 'zurie_sftp_');
    if ($tempBase === false) {
        return ['ok' => false, 'message' => 'Gagal menyediakan fail sementara SFTP.'];
    }
    $scriptFile = $tempBase . '.txt';
    $logFile = $tempBase . '.log';
    @unlink($tempBase);

    $script = "option batch abort\r\noption confirm off\r\n";
    $script .= zurie_mis_sftp_winscp_open_command($config) . "\r\n";
    foreach ($commands as $command) {
        $script .= $command . "\r\n";
    }
    $script .= "exit\r\n";

    if (@file_put_contents($scriptFile, $script, LOCK_EX) === false) {
        return ['ok' => false, 'message' => 'Gagal menulis skrip sementara WinSCP.'];
    }

    $cmd = zurie_mis_sftp_winscp_quote($winscp)
        . ' /ini=nul /script=' . zurie_mis_sftp_winscp_quote($scriptFile)
        . ' /log=' . zurie_mis_sftp_winscp_quote($logFile)
        . ' /loglevel=1';

    $output = [];
    $exitCode = 1;
    @exec($cmd . ' 2>&1', $output, $exitCode);

    $log = is_file($logFile) ? (string)@file_get_contents($logFile) : '';
    @unlink($scriptFile);
    @unlink($logFile);

    $combined = trim(implode("\n", $output) . "\n" . $log);
    $combined = zurie_mis_sftp_safe_message($combined, $config);
    if (strlen($combined) > 1800) {
        $combined = substr($combined, -1800);
    }

    return [
        'ok' => $exitCode === 0,
        'message' => $exitCode === 0 ? 'Sambungan dan arahan WinSCP berjaya.' : ('WinSCP gagal (kod ' . $exitCode . '): ' . $combined),
    ];
}

function zurie_mis_sftp_ssh2_connect(array $config): array
{
    if (!extension_loaded('ssh2')) {
        return ['ok' => false, 'message' => 'Extension PHP ssh2 belum aktif.'];
    }

    $host = trim((string)$config['host']);
    $port = max(1, (int)($config['port'] ?? 22));
    $connection = @ssh2_connect($host, $port);
    if (!$connection) {
        return ['ok' => false, 'message' => 'Gagal menyambung ke host SFTP MIS.'];
    }

    $expected = trim((string)($config['host_key'] ?? ''));
    $actual = '';
    if (function_exists('ssh2_fingerprint')) {
        $actual = (string)@ssh2_fingerprint($connection, SSH2_FINGERPRINT_SHA256 | SSH2_FINGERPRINT_BASE64);
    }
    $expectedClean = preg_replace('/^.*?(SHA256:)/', '$1', $expected) ?? $expected;
    if ($expected === '' || $actual === '' || !hash_equals($expectedClean, $actual)) {
        return ['ok' => false, 'message' => 'Fingerprint host SFTP tidak sepadan. Actual: ' . ($actual ?: 'tidak dapat dibaca')];
    }

    $username = (string)$config['username'];
    $privateKey = trim((string)($config['private_key'] ?? ''));
    if ($privateKey !== '') {
        $publicKey = $privateKey . '.pub';
        if (!is_file($privateKey) || !is_file($publicKey)) {
            return ['ok' => false, 'message' => 'Private/public key SFTP tidak ditemui.'];
        }
        $auth = @ssh2_auth_pubkey_file(
            $connection,
            $username,
            $publicKey,
            $privateKey,
            (string)($config['private_key_passphrase'] ?? '')
        );
    } else {
        $auth = @ssh2_auth_password($connection, $username, (string)($config['password'] ?? ''));
    }

    if (!$auth) {
        return ['ok' => false, 'message' => 'Pengesahan login SFTP MIS gagal.'];
    }

    $sftp = @ssh2_sftp($connection);
    if (!$sftp) {
        return ['ok' => false, 'message' => 'Gagal membuka subsistem SFTP MIS.'];
    }

    return ['ok' => true, 'connection' => $connection, 'sftp' => $sftp, 'message' => 'SFTP tersambung.'];
}

function zurie_mis_sftp_diagnose(?array $config = null): array
{
    $config = $config ?? zurie_mis_sftp_config();
    $status = zurie_mis_sftp_config_status($config);
    if (!$status['ready']) {
        return ['ok' => false, 'message' => 'Konfigurasi belum lengkap: ' . implode(', ', $status['missing'])];
    }

    $remoteDir = $status['remote_dir'];
    if ($status['driver'] === 'winscp') {
        return zurie_mis_sftp_run_winscp([
            'ls ' . zurie_mis_sftp_winscp_quote($remoteDir),
        ], $config);
    }

    $connected = zurie_mis_sftp_ssh2_connect($config);
    if (!$connected['ok']) {
        return $connected;
    }
    $stat = @ssh2_sftp_stat($connected['sftp'], $remoteDir);
    if ($stat === false) {
        return ['ok' => false, 'message' => 'Login berjaya tetapi folder remote tidak ditemui atau tiada permission.'];
    }
    return ['ok' => true, 'message' => 'SFTP MIS berjaya disambung dan folder remote boleh dibaca.'];
}

/**
 * @return array{ok:bool,message:string,driver?:string,remote_file?:string,bytes?:int}
 */
function zurie_mis_sftp_upload_photo(string $localFile, string $matrik, ?array $config = null): array
{
    $config = $config ?? zurie_mis_sftp_config();
    $status = zurie_mis_sftp_config_status($config);
    if (!$status['ready']) {
        return ['ok' => false, 'message' => 'Konfigurasi SFTP belum lengkap: ' . implode(', ', $status['missing'])];
    }

    $matrik = zurie_mis_sftp_clean_matrik($matrik);
    if ($matrik === '') {
        return ['ok' => false, 'message' => 'No matrik tidak sah.'];
    }
    if (!is_file($localFile) || !is_readable($localFile)) {
        return ['ok' => false, 'message' => 'Fail repaired tidak ditemui atau tidak boleh dibaca.'];
    }

    $info = @getimagesize($localFile);
    if (!$info || (int)$info[0] !== 413 || (int)$info[1] !== 531 || (string)($info['mime'] ?? '') !== 'image/jpeg') {
        return ['ok' => false, 'message' => 'Fail sync mesti JPG 413x531. Jalankan Repair Semula dahulu.'];
    }

    $remoteFile = $status['remote_dir'] . '/' . $matrik . '.jpg';
    $remoteTemp = $status['remote_dir'] . '/.' . $matrik . '.zurie-' . bin2hex(random_bytes(4)) . '.tmp';
    $bytes = (int)filesize($localFile);

    if ($status['driver'] === 'winscp') {
        // WinSCP akan overwrite fail lama dengan nama akhir NOMATRIK.jpg.
        $result = zurie_mis_sftp_run_winscp([
            'put -transfer=binary -resumesupport=off ' . zurie_mis_sftp_winscp_quote($localFile) . ' ' . zurie_mis_sftp_winscp_quote($remoteFile),
        ], $config);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'driver' => 'winscp', 'remote_file' => $remoteFile];
        }
        return [
            'ok' => true,
            'message' => 'Sync MIS berjaya: ' . $matrik . '.jpg',
            'driver' => 'winscp',
            'remote_file' => $remoteFile,
            'bytes' => $bytes,
        ];
    }

    $connected = zurie_mis_sftp_ssh2_connect($config);
    if (!$connected['ok']) {
        return $connected;
    }
    $connection = $connected['connection'];
    $sftp = $connected['sftp'];

    if (!@ssh2_scp_send($connection, $localFile, $remoteTemp, 0644)) {
        return ['ok' => false, 'message' => 'Upload fail sementara ke MIS gagal.', 'driver' => 'ssh2', 'remote_file' => $remoteFile];
    }

    $remoteStat = @ssh2_sftp_stat($sftp, $remoteTemp);
    if ($remoteStat === false || (int)($remoteStat['size'] ?? -1) !== $bytes) {
        @ssh2_sftp_unlink($sftp, $remoteTemp);
        return ['ok' => false, 'message' => 'Pengesahan saiz fail di MIS gagal.', 'driver' => 'ssh2', 'remote_file' => $remoteFile];
    }

    if (@ssh2_sftp_stat($sftp, $remoteFile) !== false) {
        if (!@ssh2_sftp_unlink($sftp, $remoteFile)) {
            @ssh2_sftp_unlink($sftp, $remoteTemp);
            return ['ok' => false, 'message' => 'Gagal menggantikan gambar lama di MIS.', 'driver' => 'ssh2', 'remote_file' => $remoteFile];
        }
    }

    if (!@ssh2_sftp_rename($sftp, $remoteTemp, $remoteFile)) {
        @ssh2_sftp_unlink($sftp, $remoteTemp);
        return ['ok' => false, 'message' => 'Gagal menamakan fail akhir NOMATRIK.jpg di MIS.', 'driver' => 'ssh2', 'remote_file' => $remoteFile];
    }

    return [
        'ok' => true,
        'message' => 'Sync MIS berjaya: ' . $matrik . '.jpg',
        'driver' => 'ssh2',
        'remote_file' => $remoteFile,
        'bytes' => $bytes,
    ];
}

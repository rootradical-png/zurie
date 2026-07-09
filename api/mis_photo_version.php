<?php
/** Secure preview proxy for one MIS SFTP photo version. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth_guard.php';
require_once dirname(__DIR__) . '/lib/mis_sftp.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, max-age=300');

function mpv_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

$filename = trim((string)($_GET['file'] ?? ''));
$version = preg_replace('/[^0-9A-Za-z_:\- ]/', '', (string)($_GET['v'] ?? '')) ?? '';

try {
    $filename = zurie_mis_sftp_validate_remote_filename($filename);
} catch (Throwable $e) {
    mpv_fail(400, 'Nama fail tidak sah.');
}

$extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true)) {
    mpv_fail(415, 'Format gambar tidak disokong.');
}

$cacheDir = dirname(__DIR__) . '/data/photo_version_cache';
if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
    mpv_fail(500, 'Cache gambar tidak dapat disediakan.');
}

// Buang cache lama secara ringan tanpa melambatkan setiap request.
if (random_int(1, 80) === 1) {
    foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*') ?: [] as $old) {
        if (is_file($old) && filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
}

$cacheKey = hash('sha256', $filename . '|' . $version);
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.' . $extension;
if (!is_file($cacheFile) || (int)filesize($cacheFile) < 1) {
    $result = zurie_mis_sftp_download_photo_file($filename, $cacheFile);
    if (!$result['ok']) {
        @unlink($cacheFile);
        mpv_fail(502, 'Preview SFTP gagal dimuat turun.');
    }
}

$size = (int)filesize($cacheFile);
if ($size < 1 || $size > 20 * 1024 * 1024) {
    @unlink($cacheFile);
    mpv_fail(413, 'Saiz fail gambar tidak dibenarkan.');
}

$info = @getimagesize($cacheFile);
$mime = (string)($info['mime'] ?? '');
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/x-ms-bmp'];
if ($mime === '' || !in_array($mime, $allowedMimes, true)) {
    @unlink($cacheFile);
    mpv_fail(415, 'Kandungan fail bukan gambar yang sah.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
readfile($cacheFile);
exit;

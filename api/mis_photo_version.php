<?php
/**
 * Secure preview proxy for one MIS SFTP photo version.
 *
 * mode=thumb: cached lightweight JPEG thumbnail (160x210, quality 58).
 * mode=full : cached original image for explicit click/open only.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth_guard.php';
require_once dirname(__DIR__) . '/lib/mis_sftp.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

function mpv_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

function mpv_prepare_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        mpv_fail(500, 'Cache gambar tidak dapat disediakan.');
    }
}

function mpv_send_file(string $path, string $mime, string $downloadName, int $maxAge): never
{
    clearstatcache(true, $path);
    $size = is_file($path) ? (int)@filesize($path) : 0;
    if ($size < 1) {
        mpv_fail(404, 'Fail cache gambar tidak ditemui.');
    }

    $mtime = (int)@filemtime($path);
    $etag = '"' . hash('sha256', $path . '|' . $size . '|' . $mtime) . '"';
    $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && hash_equals($etag, $ifNoneMatch)) {
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=' . $maxAge);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $downloadName) . '"');
    header('Cache-Control: private, max-age=' . $maxAge);
    header('ETag: ' . $etag);
    if ($mtime > 0) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    }
    readfile($path);
    exit;
}

/** Create a small white-canvas JPEG while preserving aspect ratio. */
function mpv_create_thumbnail(string $sourceFile, string $thumbFile): bool
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return false;
    }

    $info = @getimagesize($sourceFile);
    $sourceWidth = (int)($info[0] ?? 0);
    $sourceHeight = (int)($info[1] ?? 0);
    if ($sourceWidth < 1 || $sourceHeight < 1 || $sourceWidth > 12000 || $sourceHeight > 12000) {
        return false;
    }
    if (($sourceWidth * $sourceHeight) > 40000000) {
        return false;
    }

    $bytes = @file_get_contents($sourceFile);
    if ($bytes === false || $bytes === '') {
        return false;
    }
    $source = @imagecreatefromstring($bytes);
    unset($bytes);
    if (!$source) {
        return false;
    }

    $canvasWidth = 160;
    $canvasHeight = 210;
    $canvas = @imagecreatetruecolor($canvasWidth, $canvasHeight);
    if (!$canvas) {
        imagedestroy($source);
        return false;
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);

    $scale = min($canvasWidth / $sourceWidth, $canvasHeight / $sourceHeight);
    $destWidth = max(1, (int)floor($sourceWidth * $scale));
    $destHeight = max(1, (int)floor($sourceHeight * $scale));
    $destX = (int)floor(($canvasWidth - $destWidth) / 2);
    $destY = (int)floor(($canvasHeight - $destHeight) / 2);

    $copied = @imagecopyresampled(
        $canvas,
        $source,
        $destX,
        $destY,
        0,
        0,
        $destWidth,
        $destHeight,
        $sourceWidth,
        $sourceHeight
    );
    imagedestroy($source);
    if (!$copied) {
        imagedestroy($canvas);
        return false;
    }

    $tempFile = $thumbFile . '.part-' . bin2hex(random_bytes(3));
    $written = @imagejpeg($canvas, $tempFile, 58);
    imagedestroy($canvas);
    if (!$written || !is_file($tempFile) || (int)@filesize($tempFile) < 1) {
        @unlink($tempFile);
        return false;
    }

    if (!@rename($tempFile, $thumbFile)) {
        if (!@copy($tempFile, $thumbFile)) {
            @unlink($tempFile);
            return false;
        }
        @unlink($tempFile);
    }
    @chmod($thumbFile, 0644);
    return true;
}

$filename = trim((string)($_GET['file'] ?? ''));
$version = preg_replace('/[^0-9A-Za-z_:\- .]/', '', (string)($_GET['v'] ?? '')) ?? '';
$mode = strtolower(trim((string)($_GET['mode'] ?? 'thumb')));
if (!in_array($mode, ['thumb', 'full'], true)) {
    $mode = 'thumb';
}

try {
    $filename = zurie_mis_sftp_validate_remote_filename($filename);
} catch (Throwable $e) {
    mpv_fail(400, 'Nama fail tidak sah.');
}

$extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true)) {
    mpv_fail(415, 'Format gambar tidak disokong.');
}

$cacheRoot = dirname(__DIR__) . '/data/photo_version_cache';
$originalDir = $cacheRoot . '/original';
$thumbDir = $cacheRoot . '/thumb';
$lockDir = $cacheRoot . '/locks';
mpv_prepare_dir($originalDir);
mpv_prepare_dir($thumbDir);
mpv_prepare_dir($lockDir);

// Pembersihan ringan cache yang tidak digunakan lebih 14 hari.
if (random_int(1, 100) === 1) {
    $cutoff = time() - (14 * 86400);
    foreach ([$originalDir, $thumbDir, $lockDir] as $cleanupDir) {
        foreach (glob($cleanupDir . DIRECTORY_SEPARATOR . '*') ?: [] as $old) {
            if (is_file($old) && (int)@filemtime($old) < $cutoff) {
                @unlink($old);
            }
        }
    }
    // Bersihkan juga format cache rata daripada versi kod lama.
    foreach (glob($cacheRoot . DIRECTORY_SEPARATOR . '*.*') ?: [] as $old) {
        if (is_file($old) && (int)@filemtime($old) < $cutoff) {
            @unlink($old);
        }
    }
}

$cacheKey = hash('sha256', $filename . '|' . $version);
$originalFile = $originalDir . DIRECTORY_SEPARATOR . $cacheKey . '.' . $extension;
$thumbFile = $thumbDir . DIRECTORY_SEPARATOR . $cacheKey . '.jpg';
$lockFile = $lockDir . DIRECTORY_SEPARATOR . $cacheKey . '.lock';

$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    mpv_fail(500, 'Kunci cache gambar tidak dapat disediakan.');
}

try {
    if (!@flock($lock, LOCK_EX)) {
        mpv_fail(500, 'Cache gambar sedang sibuk.');
    }

    clearstatcache(true, $originalFile);
    if (!is_file($originalFile) || (int)@filesize($originalFile) < 1) {
        @unlink($originalFile);
        $result = zurie_mis_sftp_download_photo_file($filename, $originalFile);
        clearstatcache(true, $originalFile);
        if (!$result['ok'] || !is_file($originalFile) || (int)@filesize($originalFile) < 1) {
            @unlink($originalFile);
            mpv_fail(502, 'Preview SFTP gagal dimuat turun.');
        }
    }

    $size = (int)@filesize($originalFile);
    if ($size < 1 || $size > 20 * 1024 * 1024) {
        @unlink($originalFile);
        mpv_fail(413, 'Saiz fail gambar tidak dibenarkan.');
    }

    $info = @getimagesize($originalFile);
    $mime = (string)($info['mime'] ?? '');
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/x-ms-bmp'];
    if ($mime === '' || !in_array($mime, $allowedMimes, true)) {
        @unlink($originalFile);
        mpv_fail(415, 'Kandungan fail bukan gambar yang sah.');
    }

    if ($mode === 'thumb') {
        clearstatcache(true, $thumbFile);
        if (!is_file($thumbFile) || (int)@filesize($thumbFile) < 1) {
            @unlink($thumbFile);
            if (!mpv_create_thumbnail($originalFile, $thumbFile)) {
                // Fallback selamat jika GD tiada: paparkan fail asal.
                @flock($lock, LOCK_UN);
                fclose($lock);
                mpv_send_file($originalFile, $mime, $filename, 86400);
            }
        }
        @flock($lock, LOCK_UN);
        fclose($lock);
        mpv_send_file($thumbFile, 'image/jpeg', 'thumb-' . pathinfo($filename, PATHINFO_FILENAME) . '.jpg', 604800);
    }

    @flock($lock, LOCK_UN);
    fclose($lock);
    mpv_send_file($originalFile, $mime, $filename, 604800);
} catch (Throwable $e) {
    @flock($lock, LOCK_UN);
    fclose($lock);
    mpv_fail(500, 'Gagal menyediakan preview gambar.');
}

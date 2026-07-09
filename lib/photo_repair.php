<?php
/**
 * Lightweight passport-photo repair helpers for Zurie.
 * Requires PHP GD. No AI/remove-background dependency.
 */

declare(strict_types=1);

function zurie_photo_gd_ready(): bool
{
    return extension_loaded('gd')
        && function_exists('imagecreatefromstring')
        && function_exists('imagecreatetruecolor')
        && function_exists('imagecopyresampled')
        && function_exists('imagejpeg');
}

/**
 * @return array{original:string,repaired:string}
 */
function zurie_photo_ensure_directories(string $filesDir): array
{
    $originalDir = rtrim($filesDir, '/\\') . DIRECTORY_SEPARATOR . 'original';
    $repairedDir = rtrim($filesDir, '/\\') . DIRECTORY_SEPARATOR . 'repaired';

    foreach ([$filesDir, $originalDir, $repairedDir] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Gagal menyediakan folder gambar: ' . $dir);
        }
    }

    return ['original' => $originalDir, 'repaired' => $repairedDir];
}

/**
 * Correct common JPEG EXIF orientation values.
 *
 * @param GdImage|resource $image
 * @return GdImage|resource
 */
function zurie_photo_apply_orientation($image, string $sourcePath, string $mime)
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data') || !function_exists('imagerotate')) {
        return $image;
    }

    $exif = @exif_read_data($sourcePath);
    $orientation = (int)($exif['Orientation'] ?? 1);
    $rotated = false;
    $white = imagecolorallocate($image, 255, 255, 255);

    if ($orientation === 3) {
        $rotated = @imagerotate($image, 180, $white);
    } elseif ($orientation === 6) {
        $rotated = @imagerotate($image, -90, $white);
    } elseif ($orientation === 8) {
        $rotated = @imagerotate($image, 90, $white);
    }

    if ($rotated !== false) {
        imagedestroy($image);
        return $rotated;
    }

    return $image;
}

/**
 * Repair a photo to 413x531 JPG using a top-preserving crop.
 *
 * The crop is intentionally lightweight and does not detect faces. For tall
 * source images, only about 8% of the excess is removed from the top and the
 * rest from the bottom, helping preserve existing space above the head.
 *
 * @return array{ok:bool,message:string,width?:int,height?:int,size?:int}
 */
function zurie_photo_repair(
    string $sourcePath,
    string $destinationPath,
    int $targetWidth = 413,
    int $targetHeight = 531,
    int $quality = 90
): array {
    if (!zurie_photo_gd_ready()) {
        return [
            'ok' => false,
            'message' => 'PHP GD belum aktif. Aktifkan extension=gd dalam php.ini dan restart Apache.',
        ];
    }

    if (!is_file($sourcePath) || !is_readable($sourcePath)) {
        return ['ok' => false, 'message' => 'Fail sumber gambar tidak ditemui atau tidak boleh dibaca.'];
    }

    $bytes = @file_get_contents($sourcePath);
    if ($bytes === false || strlen($bytes) < 500) {
        return ['ok' => false, 'message' => 'Kandungan gambar tidak sah atau terlalu kecil.'];
    }

    $info = @getimagesizefromstring($bytes);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return ['ok' => false, 'message' => 'Gambar rosak atau format tidak disokong.'];
    }

    $mime = (string)($info['mime'] ?? '');
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return ['ok' => false, 'message' => 'Hanya gambar JPEG atau PNG boleh dibaiki.'];
    }

    $source = @imagecreatefromstring($bytes);
    if ($source === false) {
        return ['ok' => false, 'message' => 'GD gagal membaca gambar sumber.'];
    }

    $source = zurie_photo_apply_orientation($source, $sourcePath, $mime);
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);

    if ($sourceWidth < 1 || $sourceHeight < 1) {
        imagedestroy($source);
        return ['ok' => false, 'message' => 'Dimensi gambar sumber tidak sah.'];
    }

    $targetRatio = $targetWidth / $targetHeight;
    $sourceRatio = $sourceWidth / $sourceHeight;

    $srcX = 0;
    $srcY = 0;
    $cropWidth = $sourceWidth;
    $cropHeight = $sourceHeight;

    if ($sourceRatio > $targetRatio) {
        // Gambar terlalu lebar: crop kiri/kanan secara seimbang.
        $cropWidth = max(1, (int)round($sourceHeight * $targetRatio));
        $srcX = max(0, (int)round(($sourceWidth - $cropWidth) / 2));
    } elseif ($sourceRatio < $targetRatio) {
        // Gambar terlalu tinggi: kekalkan bahagian atas, crop lebih banyak di bawah.
        $cropHeight = max(1, (int)round($sourceWidth / $targetRatio));
        $verticalExcess = max(0, $sourceHeight - $cropHeight);
        $srcY = (int)round($verticalExcess * 0.08);
        if ($srcY + $cropHeight > $sourceHeight) {
            $srcY = max(0, $sourceHeight - $cropHeight);
        }
    }

    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($canvas === false) {
        imagedestroy($source);
        return ['ok' => false, 'message' => 'Gagal menyediakan kanvas gambar.'];
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
    imagealphablending($canvas, true);

    $copied = imagecopyresampled(
        $canvas,
        $source,
        0,
        0,
        $srcX,
        $srcY,
        $targetWidth,
        $targetHeight,
        $cropWidth,
        $cropHeight
    );

    imagedestroy($source);

    if (!$copied) {
        imagedestroy($canvas);
        return ['ok' => false, 'message' => 'Proses crop/resize gagal.'];
    }

    // Sharpen ringan sahaja supaya tidak menghasilkan halo pada wajah.
    if (function_exists('imageconvolution')) {
        @imageconvolution($canvas, [
            [0.0, -0.10, 0.0],
            [-0.10, 1.40, -0.10],
            [0.0, -0.10, 0.0],
        ], 1.0, 0.0);
    }

    $destinationDir = dirname($destinationPath);
    if (!is_dir($destinationDir) && !@mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
        imagedestroy($canvas);
        return ['ok' => false, 'message' => 'Folder gambar repaired tidak boleh dicipta.'];
    }

    $saved = @imagejpeg($canvas, $destinationPath, $quality);
    imagedestroy($canvas);

    if (!$saved || !is_file($destinationPath)) {
        return ['ok' => false, 'message' => 'Gagal menyimpan gambar repaired. Semak permission folder.'];
    }

    @chmod($destinationPath, 0644);
    $finalInfo = @getimagesize($destinationPath);
    if (!$finalInfo || (int)$finalInfo[0] !== $targetWidth || (int)$finalInfo[1] !== $targetHeight) {
        return ['ok' => false, 'message' => 'Pengesahan dimensi repaired gagal.'];
    }

    return [
        'ok' => true,
        'message' => 'Siap: 413x531 JPG, quality 90%, crop mengekalkan bahagian atas dan sharpen ringan.',
        'width' => $targetWidth,
        'height' => $targetHeight,
        'size' => (int)filesize($destinationPath),
    ];
}

function zurie_photo_publish_legacy(string $repairedPath, string $legacyPath): bool
{
    if (!is_file($repairedPath)) {
        return false;
    }

    if (is_file($legacyPath)) {
        @unlink($legacyPath);
    }

    $ok = @copy($repairedPath, $legacyPath);
    if ($ok) {
        @chmod($legacyPath, 0644);
    }
    return $ok;
}

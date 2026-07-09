<?php
/**
 * Zurie Photo Background Quality - Fasa 7
 * Analisis ringan menggunakan GD pada kawasan tepi gambar pasport.
 * Tidak memerlukan servis AI luaran dan tidak menghantar gambar keluar server.
 */

declare(strict_types=1);

/**
 * @return array{
 *   ok:bool,status:string,label:string,reason:string,score:float,
 *   white_ratio:float,clean_white_ratio:float,uniformity:float,
 *   brightness:float,color_ratio:float,blue_ratio:float,shadow_ratio:float,
 *   dominant_color:string,dominant_hex:string,width:int,height:int,samples:int
 * }
 */
function zurie_photo_background_analyse(string $imageBytes): array
{
    $failed = static function (string $message): array {
        return [
            'ok' => false,
            'status' => 'gagal',
            'label' => 'ANALISIS GAGAL',
            'reason' => $message,
            'score' => 0.0,
            'white_ratio' => 0.0,
            'clean_white_ratio' => 0.0,
            'uniformity' => 0.0,
            'brightness' => 0.0,
            'color_ratio' => 0.0,
            'blue_ratio' => 0.0,
            'shadow_ratio' => 0.0,
            'dominant_color' => '-',
            'dominant_hex' => '#000000',
            'width' => 0,
            'height' => 0,
            'samples' => 0,
        ];
    };

    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
        return $failed('PHP GD belum aktif. Aktifkan extension=gd dalam php.ini.');
    }

    $src = @imagecreatefromstring($imageBytes);
    if (!$src) {
        return $failed('Fail gambar tidak dapat dibaca oleh GD.');
    }

    $sourceWidth = imagesx($src);
    $sourceHeight = imagesy($src);
    if ($sourceWidth < 60 || $sourceHeight < 60) {
        imagedestroy($src);
        return $failed('Resolusi gambar terlalu kecil untuk analisis background.');
    }

    $maxWidth = 240;
    $maxHeight = 320;
    $scale = min(1.0, $maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
    $width = max(1, (int)round($sourceWidth * $scale));
    $height = max(1, (int)round($sourceHeight * $scale));

    if ($scale < 1.0) {
        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, $width, $height, $white);
        imagecopyresampled($image, $src, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        imagedestroy($src);
    } else {
        $image = $src;
    }

    $step = max(1, (int)floor(max($width, $height) / 180));
    $topBand = max(8, (int)round($height * 0.21));
    $sideBand = max(6, (int)round($width * 0.13));
    $sideLimit = max($topBand + 1, (int)round($height * 0.74));

    $count = 0;
    $sumR = $sumG = $sumB = 0.0;
    $sumR2 = $sumG2 = $sumB2 = 0.0;
    $sumLum = $sumLum2 = 0.0;
    $nearWhite = $cleanWhite = $colored = $blue = $dark = $shadow = 0;

    for ($y = 0; $y < $height; $y += $step) {
        for ($x = 0; $x < $width; $x += $step) {
            $isTop = $y < $topBand;
            $isSide = $y < $sideLimit && ($x < $sideBand || $x >= ($width - $sideBand));
            if (!$isTop && !$isSide) {
                continue;
            }

            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $maxChannel = max($r, $g, $b);
            $minChannel = min($r, $g, $b);
            $spread = $maxChannel - $minChannel;
            $lum = (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);

            $count++;
            $sumR += $r;
            $sumG += $g;
            $sumB += $b;
            $sumR2 += $r * $r;
            $sumG2 += $g * $g;
            $sumB2 += $b * $b;
            $sumLum += $lum;
            $sumLum2 += $lum * $lum;

            if ($lum >= 205 && $spread <= 45 && $minChannel >= 172) {
                $nearWhite++;
            }
            if ($lum >= 225 && $spread <= 28 && $minChannel >= 202) {
                $cleanWhite++;
            }
            if ($spread >= 48 && $lum < 245) {
                $colored++;
            }
            if (($b - $r) >= 24 && ($b - $g) >= 12 && $b >= 120) {
                $blue++;
            }
            if ($lum < 170) {
                $dark++;
            }
            if ($lum >= 150 && $lum < 218 && $spread <= 30) {
                $shadow++;
            }
        }
    }

    imagedestroy($image);

    if ($count < 20) {
        return $failed('Kawasan background tidak mencukupi untuk dianalisis.');
    }

    $avgR = $sumR / $count;
    $avgG = $sumG / $count;
    $avgB = $sumB / $count;
    $avgLum = $sumLum / $count;
    $stdR = sqrt(max(0.0, ($sumR2 / $count) - ($avgR * $avgR)));
    $stdG = sqrt(max(0.0, ($sumG2 / $count) - ($avgG * $avgG)));
    $stdB = sqrt(max(0.0, ($sumB2 / $count) - ($avgB * $avgB)));
    $stdLum = sqrt(max(0.0, ($sumLum2 / $count) - ($avgLum * $avgLum)));
    $stdRgb = ($stdR + $stdG + $stdB) / 3.0;

    $whitePct = ($nearWhite / $count) * 100.0;
    $cleanWhitePct = ($cleanWhite / $count) * 100.0;
    $colorPct = ($colored / $count) * 100.0;
    $bluePct = ($blue / $count) * 100.0;
    $darkPct = ($dark / $count) * 100.0;
    $shadowPct = ($shadow / $count) * 100.0;
    $uniformity = max(0.0, min(100.0, 100.0 - (($stdLum * 1.65) + ($stdRgb * 0.55))));
    $brightnessPct = ($avgLum / 255.0) * 100.0;

    $score = (0.54 * $whitePct)
        + (0.20 * $uniformity)
        + (0.14 * $brightnessPct)
        + (0.12 * $cleanWhitePct)
        - (0.48 * $colorPct)
        - (0.26 * $darkPct);
    $score = max(0.0, min(100.0, $score));

    $dominantHex = sprintf('#%02X%02X%02X', (int)round($avgR), (int)round($avgG), (int)round($avgB));
    $dominantSpread = max($avgR, $avgG, $avgB) - min($avgR, $avgG, $avgB);
    if ($avgLum >= 225 && $dominantSpread <= 24) {
        $dominantColor = 'Putih';
    } elseif ($avgLum >= 198 && $dominantSpread <= 38) {
        $dominantColor = 'Hampir putih';
    } elseif ($avgB > $avgR + 18 && $avgB > $avgG + 9) {
        $dominantColor = 'Biru';
    } elseif ($avgR > $avgG + 18 && $avgR > $avgB + 18) {
        $dominantColor = 'Merah';
    } elseif ($avgG > $avgR + 14 && $avgG > $avgB + 14) {
        $dominantColor = 'Hijau';
    } elseif ($avgLum < 105) {
        $dominantColor = 'Gelap';
    } elseif ($dominantSpread <= 28) {
        $dominantColor = 'Kelabu';
    } else {
        $dominantColor = 'Berwarna';
    }

    $status = 'semak';
    $label = 'SEMAK MANUAL';
    $reason = 'Background hampir sesuai tetapi perlu pengesahan admin.';

    if ($bluePct >= 16.0 || ($dominantColor === 'Biru' && $colorPct >= 18.0)) {
        $status = 'tolak';
        $label = 'BUKAN PUTIH';
        $reason = 'Background dominan biru atau kebiruan.';
    } elseif ($colorPct >= 30.0) {
        $status = 'tolak';
        $label = 'BERWARNA';
        $reason = 'Background mempunyai warna yang jelas dan bukan putih.';
    } elseif ($avgLum < 160.0 || $darkPct >= 42.0) {
        $status = 'tolak';
        $label = 'TERLALU GELAP';
        $reason = 'Background terlalu gelap.';
    } elseif ($whitePct >= 75.0 && $avgLum >= 210.0 && $colorPct <= 9.0 && $uniformity >= 52.0) {
        $status = 'putih';
        $label = 'PUTIH BERSIH';
        $reason = 'Background putih, cerah dan seragam.';
    } elseif ($whitePct >= 50.0 && $avgLum >= 190.0 && $colorPct <= 15.0 && $uniformity >= 40.0) {
        $status = 'hampir_putih';
        $label = 'HAMPIR PUTIH';
        $reason = 'Background hampir putih dan masih boleh diterima.';
    } elseif ($uniformity < 30.0 && $whitePct < 55.0) {
        $status = 'tolak';
        $label = 'TIDAK BERSIH';
        $reason = 'Background tidak seragam, bercorak atau mempunyai objek/bayang kuat.';
    } elseif ($shadowPct >= 28.0) {
        $status = 'semak';
        $label = 'SEMAK BAYANG';
        $reason = 'Background hampir putih tetapi terdapat bayang yang ketara.';
    } elseif ($score < 42.0) {
        $status = 'tolak';
        $label = 'TIDAK SESUAI';
        $reason = 'Background bukan putih atau tidak cukup bersih.';
    } elseif ($colorPct >= 18.0) {
        $status = 'semak';
        $label = 'SEMAK WARNA';
        $reason = 'Terdapat warna atau pencahayaan tidak sekata pada background.';
    } elseif ($uniformity < 46.0) {
        $status = 'semak';
        $label = 'SEMAK KEBERSIHAN';
        $reason = 'Background kurang seragam dan perlu semakan manual.';
    }

    return [
        'ok' => true,
        'status' => $status,
        'label' => $label,
        'reason' => $reason,
        'score' => round($score, 1),
        'white_ratio' => round($whitePct, 1),
        'clean_white_ratio' => round($cleanWhitePct, 1),
        'uniformity' => round($uniformity, 1),
        'brightness' => round($avgLum, 1),
        'color_ratio' => round($colorPct, 1),
        'blue_ratio' => round($bluePct, 1),
        'shadow_ratio' => round($shadowPct, 1),
        'dominant_color' => $dominantColor,
        'dominant_hex' => $dominantHex,
        'width' => $sourceWidth,
        'height' => $sourceHeight,
        'samples' => $count,
    ];
}

/**
 * @return array{label:string,class:string}
 */
function zurie_photo_background_badge(?string $status): array
{
    return match ($status) {
        'putih' => ['label' => 'BG PUTIH', 'class' => 'bg-good'],
        'hampir_putih' => ['label' => 'BG HAMPIR PUTIH', 'class' => 'bg-near'],
        'semak' => ['label' => 'BG SEMAK', 'class' => 'bg-review'],
        'tolak' => ['label' => 'BG TOLAK', 'class' => 'bg-reject'],
        'gagal' => ['label' => 'BG GAGAL', 'class' => 'bg-failed'],
        default => ['label' => 'BG BELUM SEMAK', 'class' => 'bg-pending'],
    };
}

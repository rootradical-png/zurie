<?php
/**
 * Mock preview kad matrik Zurie.
 *
 * MA = tema biru (Akaun)
 * MS = tema oren (Sains)
 * Preview sahaja; bukan fail cetakan rasmi.
 */

declare(strict_types=1);

function zurie_matric_card_theme(string $matrik): string
{
    $prefix = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $matrik) ?? '', 0, 2));
    return $prefix === 'MS' ? 'ms' : 'ma';
}

function zurie_matric_card_format_nokp(string $nokp): string
{
    $digits = preg_replace('/\D+/', '', $nokp) ?? '';
    if (strlen($digits) === 12) {
        return substr($digits, 0, 6) . '-XX-XXXX';
    }
    return $digits !== '' ? $digits : '-';
}

function zurie_matric_card_pick_photo_url(string $matrik, string $filesDir, string $fallbackUrl = ''): string
{
    $safe = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $matrik) ?? '');
    if ($safe === '') {
        return $fallbackUrl;
    }

    $candidates = [
        [
            'path' => rtrim($filesDir, '/\\') . DIRECTORY_SEPARATOR . 'repaired' . DIRECTORY_SEPARATOR . $safe . '.jpg',
            'url' => '/zurie/upload/files/repaired/' . rawurlencode($safe) . '.jpg',
        ],
        [
            'path' => rtrim($filesDir, '/\\') . DIRECTORY_SEPARATOR . $safe . '.jpg',
            'url' => '/zurie/upload/files/' . rawurlencode($safe) . '.jpg',
        ],
        [
            'path' => rtrim($filesDir, '/\\') . DIRECTORY_SEPARATOR . 'original' . DIRECTORY_SEPARATOR . $safe . '.jpg',
            'url' => '/zurie/upload/files/original/' . rawurlencode($safe) . '.jpg',
        ],
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate['path'])) {
            $mtime = @filemtime($candidate['path']);
            return $candidate['url'] . ($mtime ? '?v=' . $mtime : '');
        }
    }

    return $fallbackUrl;
}

function zurie_matric_card_barcode_svg(string $value): string
{
    $patterns = [
        '0'=>'nnnwwnwnn','1'=>'wnnwnnnnw','2'=>'nnwwnnnnw','3'=>'wnwwnnnnn','4'=>'nnnwwnnnw',
        '5'=>'wnnwwnnnn','6'=>'nnwwwnnnn','7'=>'nnnwnnwnw','8'=>'wnnwnnwnn','9'=>'nnwwnnwnn',
        'A'=>'wnnnnwnnw','B'=>'nnwnnwnnw','C'=>'wnwnnwnnn','D'=>'nnnnwwnnw','E'=>'wnnnwwnnn',
        'F'=>'nnwnwwnnn','G'=>'nnnnnwwnw','H'=>'wnnnnwwnn','I'=>'nnwnnwwnn','J'=>'nnnnwwwnn',
        'K'=>'wnnnnnnww','L'=>'nnwnnnnww','M'=>'wnwnnnnwn','N'=>'nnnnwnnww','O'=>'wnnnwnnwn',
        'P'=>'nnwnwnnwn','Q'=>'nnnnnnwww','R'=>'wnnnnnwwn','S'=>'nnwnnnwwn','T'=>'nnnnwnwwn',
        'U'=>'wwnnnnnnw','V'=>'nwwnnnnnw','W'=>'wwwnnnnnn','X'=>'nwnnwnnnw','Y'=>'wwnnwnnnn',
        'Z'=>'nwwnwnnnn','-'=>'nwnnnnwnw','.'=>'wwnnnnwnn',' '=>'nwwnnnwnn',
        '$'=>'nwnwnwnnn','/'=>'nwnwnnnwn','+'=>'nwnnnwnwn','%'=>'nnnwnwnwn','*'=>'nwnnwnwnn',
    ];

    $clean = strtoupper(preg_replace('/[^A-Z0-9.\- $\/+%]/', '', $value) ?? '');
    if ($clean === '') {
        $clean = 'PREVIEW';
    }
    $encoded = '*' . $clean . '*';

    $narrow = 2;
    $wide = 5;
    $gap = 2;
    $height = 64;
    $x = 8;
    $rects = [];

    foreach (str_split($encoded) as $char) {
        $pattern = $patterns[$char] ?? $patterns['-'];
        foreach (str_split($pattern) as $index => $unit) {
            $width = $unit === 'w' ? $wide : $narrow;
            if ($index % 2 === 0) {
                $rects[] = '<rect x="' . $x . '" y="4" width="' . $width . '" height="' . $height . '" />';
            }
            $x += $width;
        }
        $x += $gap;
    }

    $totalWidth = $x + 8;
    $label = htmlspecialchars($clean, ENT_QUOTES, 'UTF-8');

    return '<svg class="zurie-matric-barcode" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $totalWidth . ' 86" role="img" aria-label="Barcode ' . $label . '">' .
        '<rect width="100%" height="100%" fill="#fff" rx="7" />' .
        '<g fill="#000">' . implode('', $rects) . '</g>' .
        '<text x="50%" y="82" text-anchor="middle" font-family="Arial,sans-serif" font-size="10" letter-spacing="1.2" fill="#111">' . $label . '</text>' .
        '</svg>';
}

/**
 * @param array{nama?:mixed,nokp?:mixed,matrik?:mixed,sesi?:mixed} $student
 * @param array{compact?:bool,note?:bool} $options
 */
function zurie_matric_card_preview_html(array $student, string $photoUrl, array $options = []): string
{
    $nama = trim((string)($student['nama'] ?? 'NAMA PELAJAR'));
    $nokp = zurie_matric_card_format_nokp((string)($student['nokp'] ?? ''));
    $matrik = strtoupper(trim((string)($student['matrik'] ?? '')));
    $sesi = trim((string)($student['sesi'] ?? '2026/2027'));
    $theme = zurie_matric_card_theme($matrik);
    $compact = !empty($options['compact']);
    $showNote = !array_key_exists('note', $options) || !empty($options['note']);

    $class = 'zurie-matric-preview theme-' . $theme . ($compact ? ' is-compact' : '');
    $photo = htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8');
    $namaEsc = htmlspecialchars($nama !== '' ? $nama : 'NAMA PELAJAR', ENT_QUOTES, 'UTF-8');
    $nokpEsc = htmlspecialchars($nokp, ENT_QUOTES, 'UTF-8');
    $matrikEsc = htmlspecialchars($matrik !== '' ? $matrik : 'NO MATRIK', ENT_QUOTES, 'UTF-8');
    $sesiEsc = htmlspecialchars($sesi, ENT_QUOTES, 'UTF-8');

    $html = '<div class="' . $class . '">';
    $html .= '<div class="zurie-matric-session">SESI ' . $sesiEsc . '</div>';
    $html .= '<div class="zurie-matric-photo-placeholder" aria-hidden="true"><span>FOTO<br>PELAJAR</span></div>';
    if ($photo !== '') {
        $html .= '<img class="zurie-matric-photo" src="' . $photo . '" alt="Foto ' . $namaEsc . '" onerror="this.style.display=\'none\'">';
    }
    $html .= '<div class="zurie-matric-data">';
    $html .= '<div class="zurie-matric-name">' . $namaEsc . '</div>';
    $html .= '<div class="zurie-matric-nokp">' . $nokpEsc . '</div>';
    $html .= '<div class="zurie-matric-number">' . $matrikEsc . '</div>';
    $html .= '</div>';
    $html .= '<div class="zurie-matric-barcode-wrap">' . zurie_matric_card_barcode_svg($matrik) . '</div>';
    $html .= '<div class="zurie-matric-watermark">PREVIEW SAHAJA</div>';
    $html .= '<div class="zurie-matric-topnote" style="left:0;width:100%;text-align:center;color:#ffffff;">TUJUAN PREVIEW SAHAJA</div>';
    $html .= '</div>';

    return $html;
}

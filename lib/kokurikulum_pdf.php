<?php
/**
 * Small self-contained PDF writer for the Kokurikulum certificate.
 * Supports built-in Times fonts, vector tables and JPG/PNG images (PNG is
 * converted to JPG through GD when available).
 */
declare(strict_types=1);

final class KokurikulumPdf
{
    private float $pageWidthPt = 595.28;
    private float $pageHeightPt = 841.89;
    /** @var array<int,string> */
    private array $pages = [];
    /** @var array<int,array<string,bool>> */
    private array $pageImages = [];
    private int $currentPage = -1;
    /** @var array<string,array{name:string,data:string,width:int,height:int}> */
    private array $images = [];
    private int $imageCounter = 0;
    private array $tempFiles = [];

    public function __destruct()
    {
        foreach ($this->tempFiles as $file) @unlink($file);
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->pageImages[] = [];
        $this->currentPage = count($this->pages) - 1;
    }

    public function pageCount(): int { return count($this->pages); }

    private function mm(float $mm): float { return $mm * 72.0 / 25.4; }

    private function append(string $command): void
    {
        if ($this->currentPage < 0) $this->addPage();
        $this->pages[$this->currentPage] .= $command . "\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.2): void
    {
        $this->append(sprintf('%.3F w %.3F %.3F m %.3F %.3F l S', $this->mm($width), $this->mm($x1), $this->pageHeightPt - $this->mm($y1), $this->mm($x2), $this->pageHeightPt - $this->mm($y2)));
    }

    public function rect(float $x, float $y, float $w, float $h, bool $fill = false, array $fillRgb = [255,255,255], float $lineWidth = 0.2): void
    {
        $ops = [];
        if ($fill) $ops[] = sprintf('%.3F %.3F %.3F rg', $fillRgb[0]/255, $fillRgb[1]/255, $fillRgb[2]/255);
        $ops[] = sprintf('%.3F w', $this->mm($lineWidth));
        $ops[] = sprintf('%.3F %.3F %.3F %.3F re %s', $this->mm($x), $this->pageHeightPt - $this->mm($y + $h), $this->mm($w), $this->mm($h), $fill ? 'B' : 'S');
        $this->append(implode(' ', $ops));
    }

    private function encode(string $text): string
    {
        $text = str_replace(["\u{2013}", "\u{2014}", "\u{2212}"], '-', $text);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) return $converted;
        }
        return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
    }

    private function escape(string $text): string
    {
        return str_replace(["\\", '(', ')', "\n", "\r"], ["\\\\", '\\(', '\\)', ' ', ' '], $text);
    }

    private function fontName(bool $bold, bool $italic): string
    {
        if ($bold && $italic) return 'F4';
        if ($bold) return 'F2';
        if ($italic) return 'F3';
        return 'F1';
    }

    private function estimateWidth(string $text, float $sizePt, bool $bold): float
    {
        $encoded = $this->encode($text);
        $units = 0.0;
        for ($i = 0, $n = strlen($encoded); $i < $n; $i++) {
            $c = $encoded[$i];
            if ($c === ' ') $units += 0.25;
            elseif (str_contains("ilI.,'`:;!|", $c)) $units += 0.23;
            elseif (str_contains('MW@%&#', $c)) $units += 0.85;
            elseif (ctype_upper($c)) $units += 0.66;
            elseif (ctype_digit($c)) $units += 0.50;
            else $units += 0.46;
        }
        if ($bold) $units *= 1.04;
        return $units * $sizePt * 25.4 / 72.0;
    }

    public function text(float $x, float $baselineY, string $text, float $size = 10, bool $bold = false, bool $italic = false, string $align = 'L', float $boxWidth = 0, array $rgb = [0,0,0]): void
    {
        $width = $this->estimateWidth($text, $size, $bold);
        if ($boxWidth > 0) {
            if ($align === 'C') $x += max(0, ($boxWidth - $width) / 2);
            elseif ($align === 'R') $x += max(0, $boxWidth - $width);
        }
        $this->append(sprintf(
            'q BT /%s %.3F Tf %.3F %.3F %.3F rg 1 0 0 1 %.3F %.3F Tm (%s) Tj ET Q',
            $this->fontName($bold, $italic), $size,
            $rgb[0]/255, $rgb[1]/255, $rgb[2]/255,
            $this->mm($x), $this->pageHeightPt - $this->mm($baselineY),
            $this->escape($this->encode($text))
        ));
    }

    /** @return string[] */
    public function wrap(string $text, float $width, float $size = 9, bool $bold = false): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') return [''];
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', $text) ?: [$text] as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->estimateWidth($candidate, $size, $bold) <= $width) {
                $current = $candidate;
            } else {
                if ($current !== '') $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') $lines[] = $current;
        return $lines ?: [''];
    }

    public function multiline(float $x, float $y, float $w, string $text, float $size = 9, float $lineHeight = 5, string $align = 'L', bool $bold = false, array $rgb = [0,0,0]): float
    {
        $lines = $this->wrap($text, $w, $size, $bold);
        foreach ($lines as $index => $line) {
            $this->text($x, $y + ($index * $lineHeight), $line, $size, $bold, false, $align, $w, $rgb);
        }
        return count($lines) * $lineHeight;
    }

    private function jpegReady(string $file): ?string
    {
        $info = @getimagesize($file);
        if (!$info) return null;
        $mime = strtolower((string)($info['mime'] ?? ''));
        if ($mime === 'image/jpeg') return $file;
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return null;
        $data = @file_get_contents($file);
        if (!is_string($data)) return null;
        $src = @imagecreatefromstring($data);
        if (!$src) return null;
        $w = imagesx($src); $h = imagesy($src);
        $canvas = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($canvas, 255,255,255);
        imagefilledrectangle($canvas, 0,0,$w,$h,$white);
        imagealphablending($canvas, true);
        imagecopy($canvas, $src, 0,0,0,0,$w,$h);
        $temp = tempnam(sys_get_temp_dir(), 'koku_img_');
        if ($temp === false) { imagedestroy($src); imagedestroy($canvas); return null; }
        $jpg = $temp . '.jpg'; @unlink($temp);
        $ok = @imagejpeg($canvas, $jpg, 90);
        imagedestroy($src); imagedestroy($canvas);
        if (!$ok) { @unlink($jpg); return null; }
        $this->tempFiles[] = $jpg;
        return $jpg;
    }

    public function image(string $file, float $x, float $y, float $w, float $h = 0): bool
    {
        if (!is_file($file)) return false;
        $jpeg = $this->jpegReady($file);
        if (!$jpeg) return false;
        $hash = hash_file('sha256', $jpeg) ?: md5($jpeg);
        if (!isset($this->images[$hash])) {
            $info = @getimagesize($jpeg);
            $data = @file_get_contents($jpeg);
            if (!$info || !is_string($data)) return false;
            $this->imageCounter++;
            $this->images[$hash] = [
                'name' => 'Im' . $this->imageCounter,
                'data' => $data,
                'width' => (int)$info[0],
                'height' => (int)$info[1],
            ];
        }
        $image = $this->images[$hash];
        if ($h <= 0) $h = $w * $image['height'] / max(1, $image['width']);
        $this->pageImages[$this->currentPage][$image['name']] = true;
        $this->append(sprintf(
            'q %.3F 0 0 %.3F %.3F %.3F cm /%s Do Q',
            $this->mm($w), $this->mm($h), $this->mm($x), $this->pageHeightPt - $this->mm($y + $h), $image['name']
        ));
        return true;
    }

    public function output(?callable $footer = null): string
    {
        if (!$this->pages) $this->addPage();
        if ($footer) {
            $count = count($this->pages);
            foreach (array_keys($this->pages) as $i) {
                $this->currentPage = $i;
                $footer($this, $i + 1, $count);
            }
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Italic /Encoding /WinAnsiEncoding >>';
        $objects[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Times-BoldItalic /Encoding /WinAnsiEncoding >>';

        $nextId = 7;
        $imageObjectIds = [];
        foreach ($this->images as $hash => $image) {
            $id = $nextId++;
            $imageObjectIds[$image['name']] = $id;
            $objects[$id] = '<< /Type /XObject /Subtype /Image /Width ' . $image['width'] . ' /Height ' . $image['height']
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($image['data']) . ">>\nstream\n" . $image['data'] . "\nendstream";
        }

        $pageIds = [];
        $streamIds = [];
        foreach ($this->pages as $_) {
            $pageIds[] = $nextId++;
            $streamIds[] = $nextId++;
        }
        $kids = implode(' ', array_map(static fn($id): string => $id . ' 0 R', $pageIds));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds) . ' >>';

        foreach ($this->pages as $i => $commands) {
            $xobjects = [];
            foreach (array_keys($this->pageImages[$i] ?? []) as $name) {
                if (isset($imageObjectIds[$name])) $xobjects[] = '/' . $name . ' ' . $imageObjectIds[$name] . ' 0 R';
            }
            $resource = '<< /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R /F4 6 0 R >>';
            if ($xobjects) $resource .= ' /XObject << ' . implode(' ', $xobjects) . ' >>';
            $resource .= ' >>';
            $objects[$pageIds[$i]] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.3F %.3F] /Resources %s /Contents %d 0 R >>',
                $this->pageWidthPt, $this->pageHeightPt, $resource, $streamIds[$i]
            );
            $stream = "q\n" . $commands . "\nQ";
            $objects[$streamIds[$i]] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream";
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }
}

final class KokurikulumCertificateRenderer
{
    private KokurikulumPdf $pdf;
    private array $config;
    private array $student;
    private array $activities;
    private string $session;
    private float $y = 10.0;

    public function __construct(array $config, array $student, array $activities, string $session)
    {
        $this->pdf = new KokurikulumPdf();
        $this->config = $config;
        $this->student = $student;
        $this->activities = $activities;
        $this->session = $session;
    }

    private function addPage(): void
    {
        $this->pdf->addPage();
        $this->y = 10;
        $logoKpm = ik_asset_file($this->config, 'logo_kpm');
        $logoKmp = ik_asset_file($this->config, 'logo_kmp');
        if ($logoKpm) $this->pdf->image($logoKpm, 10, 10, 45, 20);
        if ($logoKmp) $this->pdf->image($logoKmp, 180, 10, 20, 20);
        $this->pdf->text(10, 15, $this->config['college_name'], 14, true, false, 'C', 190);
        $this->pdf->text(10, 21, $this->config['ministry_name'], 11, true, false, 'C', 190);
        $this->pdf->text(10, 27, $this->config['college_address'], 10, false, false, 'C', 190);
        $this->pdf->line(10, 34, 200, 34, 0.5);
        $this->y = 42;
    }

    private function ensure(float $height): void
    {
        if ($this->y + $height > 267) $this->addPage();
    }

    private function sectionTitle(string $title): void
    {
        $this->ensure(12);
        $this->pdf->text(10, $this->y + 5, $title, 11, true, false, 'L', 190, [0,51,102]);
        $this->y += 8;
    }

    private function tableHeader(array $labels, array $widths): void
    {
        $height = 8; $x = 7.5;
        foreach ($labels as $i => $label) {
            $this->pdf->rect($x, $this->y, $widths[$i], $height, true, [240,240,240]);
            $this->pdf->text($x, $this->y + 5.2, $label, 8.5, true, false, 'C', $widths[$i]);
            $x += $widths[$i];
        }
        $this->y += $height;
    }

    private function tableRows(array $rows, array $widths, bool $firstStyle): void
    {
        if (!$rows) {
            $this->pdf->rect(7.5, $this->y, array_sum($widths), 10);
            $this->pdf->text(9, $this->y + 6.4, '* Tiada Rekod Penglibatan', 9);
            $this->y += 10;
            return;
        }
        foreach ($rows as $index => $row) {
            $values = array_merge([(string)($index + 1)], array_map('strval', $row));
            $lineSets = [];
            $maxLines = 1;
            foreach ($values as $i => $value) {
                $lineSets[$i] = $this->pdf->wrap($value, max(3, $widths[$i] - 2), 8.5, false);
                $maxLines = max($maxLines, count($lineSets[$i]));
            }
            $height = max(6, $maxLines * 5.2);
            $this->ensure($height + 2);
            $x = 7.5;
            foreach ($values as $i => $value) {
                $this->pdf->rect($x, $this->y, $widths[$i], $height);
                foreach ($lineSets[$i] as $lineIndex => $line) {
                    $align = $i === 1 ? 'L' : 'C';
                    $this->pdf->text($x + ($align === 'L' ? 1 : 0), $this->y + 4.3 + ($lineIndex * 5.2), $line, 8.5, false, false, $align, $widths[$i] - ($align === 'L' ? 2 : 0));
                }
                $x += $widths[$i];
            }
            $this->y += $height;
        }
    }

    public function render(): string
    {
        $this->addPage();
        $this->pdf->text(10, $this->y + 5, 'SIJIL AKUAN KOKURIKULUM', 14, true, false, 'C', 190, [30,30,30]);
        $this->y += 12;

        $program = ik_program_label((string)($this->student['program'] ?? ''));
        $studentPhoto = ik_student_photo_file($this->config, $this->student);
        if ($studentPhoto) $this->pdf->image($studentPhoto, 172, $this->y, 25, 34);
        $infoRows = [
            ['NAMA', (string)$this->student['nama'], '', ''],
            ['NO. KP', (string)$this->student['nokp'], 'NO. MATRIK', (string)$this->student['matrik']],
            ['PROGRAM', $program, 'SESI', $this->session],
            ['JURUSAN', (string)$this->student['jurusan'], '', ''],
        ];
        foreach ($infoRows as $row) {
            $this->pdf->text(10, $this->y + 5, $row[0], 10, true);
            $this->pdf->text(30, $this->y + 5, ': ' . $row[1], 10, false, 'L', 76);
            if ($row[2] !== '') {
                $this->pdf->text(110, $this->y + 5, $row[2], 10, true);
                $this->pdf->text(135, $this->y + 5, ': ' . $row[3], 10, false);
            }
            $this->y += 6;
        }

        $this->y += 5;
        $this->sectionTitle('1. AKTIVITI KOKURIKULUM');
        $this->tableHeader(['Bil','Perkara','Kelab/Persatuan','Peringkat','Jawatan','Pencapaian'], [10,45,35,35,40,30]);
        $this->tableRows((array)$this->activities['section1'], [10,45,35,35,40,30], true);

        $this->y += 6;
        $this->sectionTitle('2. SUMBANGAN');
        $this->tableHeader(['Bil','Perkara','Peringkat','Tahun'], [10,135,35,15]);
        $this->tableRows((array)$this->activities['section2'], [10,135,35,15], false);

        $this->y += 6;
        $this->sectionTitle('3. ANUGERAH');
        $this->tableHeader(['Bil','Perkara','Peringkat','Tahun'], [10,135,35,15]);
        $this->tableRows((array)$this->activities['section3'], [10,135,35,15], false);

        $this->ensure(56);
        $this->y += 15;
        $stamp = ik_asset_file($this->config, 'stamp_kmp');
        $signature = ik_asset_file($this->config, 'director_signature');
        if ($stamp) $this->pdf->image($stamp, 20, $this->y, 35, 28);
        if ($signature) $this->pdf->image($signature, 150, $this->y, 30, 18);
        $this->pdf->text(150, $this->y + 27, '(' . $this->config['director_name'] . ')', 10, true);
        $this->pdf->text(150, $this->y + 32, 'Pengarah', 9);
        $this->pdf->text(150, $this->y + 37, $this->config['college_name'], 9);
        $this->pdf->text(10, $this->y + 45, 'NO. SIRI: ' . ik_serial_number($this->student), 8, true);

        return $this->pdf->output(function(KokurikulumPdf $pdf, int $page, int $total): void {
            $text = 'Dokumen ini dijana secara komputer pada: ' . date('d/m/Y H:i:s') . ' | Muka Surat ' . $page . '/' . $total;
            $pdf->text(10, 287, $text, 7, false, true, 'C', 190, [100,100,100]);
        });
    }
}

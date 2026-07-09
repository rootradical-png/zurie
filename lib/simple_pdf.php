<?php
/**
 * Minimal dependency-free PDF writer for internal Zurie reports.
 * Uses A4 and built-in Helvetica fonts with WinAnsi encoding.
 */
declare(strict_types=1);

final class ZurieSimplePdf
{
    private float $pageWidthPt;
    private float $pageHeightPt;
    /** @var array<int,string> */
    private array $pages = [];
    private int $currentPage = -1;

    public function __construct(string $orientation = 'P')
    {
        $a4Width = 595.28;
        $a4Height = 841.89;
        if (strtoupper($orientation) === 'L') {
            $this->pageWidthPt = $a4Height;
            $this->pageHeightPt = $a4Width;
        } else {
            $this->pageWidthPt = $a4Width;
            $this->pageHeightPt = $a4Height;
        }
    }

    public function addPage(): int
    {
        $this->pages[] = '';
        $this->currentPage = count($this->pages) - 1;
        return $this->currentPage;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function pageWidthMm(): float
    {
        return $this->pageWidthPt / $this->mmToPt(1.0);
    }

    public function pageHeightMm(): float
    {
        return $this->pageHeightPt / $this->mmToPt(1.0);
    }

    public function setStrokeColor(int $r, int $g, int $b): void
    {
        $this->append(sprintf('%.3F %.3F %.3F RG', $r / 255, $g / 255, $b / 255));
    }

    public function setFillColor(int $r, int $g, int $b): void
    {
        $this->append(sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255));
    }

    public function setLineWidth(float $widthMm): void
    {
        $this->append(sprintf('%.3F w', $this->mmToPt($widthMm)));
    }

    public function line(float $x1Mm, float $y1Mm, float $x2Mm, float $y2Mm): void
    {
        $x1 = $this->mmToPt($x1Mm);
        $y1 = $this->pageHeightPt - $this->mmToPt($y1Mm);
        $x2 = $this->mmToPt($x2Mm);
        $y2 = $this->pageHeightPt - $this->mmToPt($y2Mm);
        $this->append(sprintf('%.3F %.3F m %.3F %.3F l S', $x1, $y1, $x2, $y2));
    }

    public function rect(float $xMm, float $yMm, float $widthMm, float $heightMm, bool $fill = false, bool $stroke = true): void
    {
        $x = $this->mmToPt($xMm);
        $y = $this->pageHeightPt - $this->mmToPt($yMm + $heightMm);
        $w = $this->mmToPt($widthMm);
        $h = $this->mmToPt($heightMm);
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $this->append(sprintf('%.3F %.3F %.3F %.3F re %s', $x, $y, $w, $h, $op));
    }

    public function text(
        float $xMm,
        float $baselineFromTopMm,
        string $text,
        float $sizePt = 8.0,
        bool $bold = false,
        string $align = 'left',
        float $boxWidthMm = 0.0
    ): void {
        $encoded = $this->encodeText($text);
        $x = $this->mmToPt($xMm);
        $boxWidthPt = $this->mmToPt($boxWidthMm);
        $textWidthPt = $this->measureEncodedText($encoded, $sizePt, $bold);
        if ($boxWidthMm > 0) {
            if ($align === 'center') {
                $x += max(0.0, ($boxWidthPt - $textWidthPt) / 2.0);
            } elseif ($align === 'right') {
                $x += max(0.0, $boxWidthPt - $textWidthPt);
            }
        }
        $y = $this->pageHeightPt - $this->mmToPt($baselineFromTopMm);
        $font = $bold ? 'F2' : 'F1';
        $this->append(sprintf(
            'q BT /%s %.3F Tf 0 g 1 0 0 1 %.3F %.3F Tm (%s) Tj ET Q',
            $font,
            $sizePt,
            $x,
            $y,
            $this->escapePdfString($encoded)
        ));
    }

    /**
     * @return string[]
     */
    public function wrapText(string $text, float $maxWidthMm, float $sizePt = 8.0, bool $bold = false): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return [''];
        }

        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $paragraph = trim(preg_replace('/\s+/u', ' ', $paragraph) ?? $paragraph);
            if ($paragraph === '') {
                $lines[] = '';
                continue;
            }
            $words = preg_split('/\s+/u', $paragraph) ?: [$paragraph];
            $current = '';
            foreach ($words as $word) {
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if ($this->measureTextMm($candidate, $sizePt, $bold) <= $maxWidthMm) {
                    $current = $candidate;
                    continue;
                }
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                if ($this->measureTextMm($word, $sizePt, $bold) <= $maxWidthMm) {
                    $current = $word;
                    continue;
                }

                $chunk = '';
                $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($word);
                foreach ($chars as $char) {
                    $candidateChunk = $chunk . $char;
                    if ($chunk !== '' && $this->measureTextMm($candidateChunk, $sizePt, $bold) > $maxWidthMm) {
                        $lines[] = $chunk;
                        $chunk = $char;
                    } else {
                        $chunk = $candidateChunk;
                    }
                }
                $current = $chunk;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines === [] ? [''] : $lines;
    }

    public function measureTextMm(string $text, float $sizePt = 8.0, bool $bold = false): float
    {
        return $this->measureEncodedText($this->encodeText($text), $sizePt, $bold) / $this->mmToPt(1.0);
    }

    public function output(?callable $footerCallback = null): string
    {
        if ($this->pages === []) {
            $this->addPage();
        }

        if ($footerCallback !== null) {
            $total = count($this->pages);
            foreach (array_keys($this->pages) as $index) {
                $this->currentPage = $index;
                $footerCallback($this, $index + 1, $total);
            }
        }

        $pageCount = count($this->pages);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $pageObjectIds = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $pageObjectIds[] = 5 + ($i * 2);
        }
        $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjectIds));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . $pageCount . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($this->pages as $i => $commands) {
            $pageId = 5 + ($i * 2);
            $streamId = $pageId + 1;
            $stream = "q\n" . $commands . "\nQ";
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.3F %.3F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->pageWidthPt,
                $this->pageHeightPt,
                $streamId
            );
            $objects[$streamId] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream";
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $maxObject = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxObject; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function append(string $command): void
    {
        if ($this->currentPage < 0) {
            $this->addPage();
        }
        $this->pages[$this->currentPage] .= $command . "\n";
    }

    private function mmToPt(float $mm): float
    {
        return $mm * 72.0 / 25.4;
    }

    private function encodeText(string $text): string
    {
        $text = str_replace(["\u{2013}", "\u{2014}", "\u{2212}"], '-', $text);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                return $converted;
            }
        }
        return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
    }

    private function escapePdfString(string $text): string
    {
        return str_replace(["\\", '(', ')', "\n", "\r"], ["\\\\", '\\(', '\\)', ' ', ' '], $text);
    }

    private function measureEncodedText(string $text, float $sizePt, bool $bold): float
    {
        $units = 0.0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $c = $text[$i];
            if ($c === ' ') {
                $units += 0.278;
            } elseif (str_contains("ilI.,'`:;!|", $c)) {
                $units += 0.260;
            } elseif (str_contains('MW@%&#', $c)) {
                $units += 0.850;
            } elseif (ctype_digit($c)) {
                $units += 0.556;
            } elseif (ctype_upper($c)) {
                $units += 0.667;
            } elseif (ctype_lower($c)) {
                $units += 0.500;
            } else {
                $units += 0.556;
            }
        }
        if ($bold) {
            $units *= 1.035;
        }
        return $units * $sizePt;
    }
}

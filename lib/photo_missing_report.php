<?php
/**
 * PDF report builder for active students whose MIS photo is missing.
 */
declare(strict_types=1);

require_once __DIR__ . '/simple_pdf.php';

/**
 * @param array<int,array<string,mixed>> $rows
 * @param array<string,mixed> $meta
 */
function zurie_build_missing_photo_report(array $rows, array $meta): string
{
    $pdf = new ZurieSimplePdf('L');
    $margin = 8.0;
    $pageWidth = $pdf->pageWidthMm();
    $pageHeight = $pdf->pageHeightMm();
    $contentWidth = $pageWidth - ($margin * 2);
    $reportNo = trim((string)($meta['report_no'] ?? ''));
    $reportDate = trim((string)($meta['report_date'] ?? date('d/m/Y H:i')));
    $actor = trim((string)($meta['actor'] ?? 'Admin'));
    $search = trim((string)($meta['search'] ?? ''));
    $filterSummary = trim((string)($meta['filter_summary'] ?? ''));
    $uploadLink = trim((string)($meta['upload_link'] ?? ''));
    $lastAudit = trim((string)($meta['last_audit'] ?? '-'));

    $columns = [
        ['key' => 'bil', 'label' => 'BIL.', 'width' => 8.0, 'align' => 'center'],
        ['key' => 'matrik', 'label' => 'NO. MATRIK', 'width' => 25.0, 'align' => 'left'],
        ['key' => 'nama', 'label' => 'NAMA PELAJAR', 'width' => 55.0, 'align' => 'left'],
        ['key' => 'nokp', 'label' => 'NO. KP', 'width' => 26.0, 'align' => 'left'],
        ['key' => 'praktikum', 'label' => 'PRAKTIKUM', 'width' => 20.0, 'align' => 'left'],
        ['key' => 'kuliah', 'label' => 'KULIAH', 'width' => 14.0, 'align' => 'center'],
        ['key' => 'jurusan', 'label' => 'JURUSAN', 'width' => 27.0, 'align' => 'left'],
        ['key' => 'stud_intake', 'label' => 'INTAKE', 'width' => 13.0, 'align' => 'center'],
        ['key' => 'nohp', 'label' => 'NO. HP', 'width' => 23.0, 'align' => 'left'],
        ['key' => 'upload_label', 'label' => 'STATUS UPLOAD', 'width' => 20.0, 'align' => 'center'],
        ['key' => 'wa_label', 'label' => 'WHATSAPP', 'width' => 19.0, 'align' => 'center'],
        ['key' => 'audit_label', 'label' => 'TARIKH AUDIT', 'width' => 29.0, 'align' => 'center'],
    ];

    $summary = [
        'belum_upload' => 0,
        'ada_upload' => 0,
        'wa_sent' => 0,
        'wa_pending' => 0,
    ];

    $preparedRows = [];
    foreach ($rows as $index => $row) {
        $uploadStatus = strtolower(trim((string)($row['upload_status'] ?? '')));
        $syncStatus = strtolower(trim((string)($row['sync_status'] ?? '')));
        $hasUpload = $uploadStatus !== '';
        if ($hasUpload) {
            $summary['ada_upload']++;
        } else {
            $summary['belum_upload']++;
        }
        $waSent = (int)($row['whatsapp_sent'] ?? 0) === 1;
        if ($waSent) {
            $summary['wa_sent']++;
        } else {
            $summary['wa_pending']++;
        }

        $uploadLabel = match ($uploadStatus) {
            'baru' => 'MENUNGGU SEMAKAN',
            'lulus' => $syncStatus === 'berjaya' ? 'LULUS / SYNC' : 'LULUS / BELUM SYNC',
            'tolak' => 'DITOLAK',
            default => 'BELUM UPLOAD',
        };
        $waLabel = $waSent ? 'SUDAH' : 'BELUM';
        if ($waSent && !empty($row['whatsapp_sent_at'])) {
            $waLabel .= "\n" . zurie_pdf_short_date((string)$row['whatsapp_sent_at']);
        }

        $preparedRows[] = [
            'bil' => (string)($index + 1),
            'matrik' => trim((string)($row['matrik'] ?? '-')) ?: '-',
            'nama' => trim((string)($row['nama'] ?? '-')) ?: '-',
            'nokp' => trim((string)($row['nokp'] ?? '-')) ?: '-',
            'praktikum' => trim((string)($row['praktikum'] ?? '-')) ?: '-',
            'kuliah' => trim((string)($row['kuliah'] ?? '-')) ?: '-',
            'jurusan' => trim((string)($row['jurusan'] ?? '-')) ?: '-',
            'stud_intake' => trim((string)($row['stud_intake'] ?? '-')) ?: '-',
            'nohp' => trim((string)($row['nohp'] ?? '-')) ?: '-',
            'upload_label' => $uploadLabel,
            'wa_label' => $waLabel,
            'audit_label' => zurie_pdf_date_time((string)($row['checked_at'] ?? '')),
        ];
    }

    $drawPageHeader = static function (ZurieSimplePdf $pdf, bool $firstPage) use (
        $margin,
        $pageWidth,
        $contentWidth,
        $reportNo,
        $reportDate,
        $actor,
        $search,
        $filterSummary,
        $uploadLink,
        $lastAudit,
        $summary,
        $preparedRows
    ): float {
        $pdf->setStrokeColor(148, 163, 184);
        $pdf->setLineWidth(0.2);
        $pdf->text($margin, 9.5, 'KEMENTERIAN PENDIDIKAN MALAYSIA', 8.0, true, 'center', $contentWidth);
        $pdf->text($margin, 14.0, 'KOLEJ MATRIKULASI PERLIS', 10.5, true, 'center', $contentWidth);
        $pdf->text($margin, 19.0, 'LAPORAN PELAJAR TIADA GAMBAR PROFIL DALAM MIS', 13.0, true, 'center', $contentWidth);
        $pdf->text($pageWidth - $margin - 48.0, 8.5, 'SULIT - KEGUNAAN DALAMAN', 6.8, true, 'right', 48.0);
        $pdf->line($margin, 22.0, $pageWidth - $margin, 22.0);

        if (!$firstPage) {
            $pdf->text($margin, 27.0, 'No. Laporan: ' . $reportNo, 7.0, false);
            $pdf->text($pageWidth - $margin - 65.0, 27.0, 'Tarikh: ' . $reportDate, 7.0, false, 'right', 65.0);
            return 31.0;
        }

        $boxY = 25.0;
        $boxH = 29.0;
        $pdf->setFillColor(248, 250, 252);
        $pdf->rect($margin, $boxY, $contentWidth, $boxH, true, true);

        $leftX = $margin + 3.0;
        $midX = $margin + 93.0;
        $rightX = $margin + 188.0;
        $pdf->text($leftX, 30.0, 'No. Laporan:', 7.2, true);
        $pdf->text($leftX + 24.0, 30.0, $reportNo, 7.2, false);
        $pdf->text($leftX, 35.0, 'Tarikh Laporan:', 7.2, true);
        $pdf->text($leftX + 24.0, 35.0, $reportDate, 7.2, false);
        $pdf->text($leftX, 40.0, 'Disediakan Oleh:', 7.2, true);
        $pdf->text($leftX + 24.0, 40.0, $actor, 7.2, false);
        $pdf->text($leftX, 45.0, 'Audit Terkini:', 7.2, true);
        $pdf->text($leftX + 24.0, 45.0, $lastAudit, 7.2, false);

        $pdf->text($midX, 30.0, 'Jumlah Pelajar:', 7.2, true);
        $pdf->text($midX + 26.0, 30.0, (string)count($preparedRows), 7.2, false);
        $pdf->text($midX, 35.0, 'Belum Upload:', 7.2, true);
        $pdf->text($midX + 26.0, 35.0, (string)$summary['belum_upload'], 7.2, false);
        $pdf->text($midX, 40.0, 'Ada Rekod Upload:', 7.2, true);
        $pdf->text($midX + 26.0, 40.0, (string)$summary['ada_upload'], 7.2, false);
        $criteriaText = $filterSummary !== ''
            ? $filterSummary
            : ($search !== '' ? ('Carian: ' . $search) : 'Semua rekod tiada gambar');
        if (strlen($criteriaText) > 125) {
            $criteriaText = substr($criteriaText, 0, 122) . '...';
        }

        $pdf->text($rightX, 30.0, 'Sudah WhatsApp:', 7.2, true);
        $pdf->text($rightX + 29.0, 30.0, (string)$summary['wa_sent'], 7.2, false);
        $pdf->text($rightX, 35.0, 'Belum WhatsApp:', 7.2, true);
        $pdf->text($rightX + 29.0, 35.0, (string)$summary['wa_pending'], 7.2, false);
        $pdf->text($rightX, 40.0, 'Skop:', 7.2, true);
        $pdf->text($rightX + 12.0, 40.0, 'Pelajar aktif, audit siap, tiada gambar MIS', 6.7, false);
        if ($uploadLink !== '') {
            $pdf->text($rightX, 45.0, 'Pautan Upload:', 7.2, true);
            $pdf->text($rightX + 25.0, 45.0, $uploadLink, 6.4, false);
        }

        $pdf->text($leftX, 50.0, 'Penapis:', 7.2, true);
        $pdf->text($leftX + 24.0, 50.0, $criteriaText, 6.5, false, 'left', $contentWidth - 30.0);

        $pdf->text($margin, 58.5, 'Tindakan dicadangkan: HEP membantu menghubungi pelajar berstatus BELUM UPLOAD / BELUM WHATSAPP dan memastikan gambar dimuat naik melalui pautan rasmi.', 7.0, false);
        return 62.0;
    };

    $drawTableHeader = static function (ZurieSimplePdf $pdf, float $topY) use ($margin, $columns): float {
        $height = 9.0;
        $x = $margin;
        $pdf->setStrokeColor(100, 116, 139);
        $pdf->setFillColor(226, 232, 240);
        $pdf->setLineWidth(0.2);
        foreach ($columns as $column) {
            $width = (float)$column['width'];
            $pdf->rect($x, $topY, $width, $height, true, true);
            $labelLines = $pdf->wrapText((string)$column['label'], $width - 2.0, 6.4, true);
            $lineHeight = 3.0;
            $blockHeight = count($labelLines) * $lineHeight;
            $baseline = $topY + (($height - $blockHeight) / 2.0) + 2.5;
            foreach ($labelLines as $line) {
                $pdf->text($x + 1.0, $baseline, $line, 6.4, true, 'center', $width - 2.0);
                $baseline += $lineHeight;
            }
            $x += $width;
        }
        return $topY + $height;
    };

    $startNewPage = static function (bool $firstPage) use ($pdf, $drawPageHeader, $drawTableHeader): float {
        $pdf->addPage();
        $tableTop = $drawPageHeader($pdf, $firstPage);
        return $drawTableHeader($pdf, $tableTop);
    };

    $y = $startNewPage(true);
    $bottomLimit = $pageHeight - 12.0;
    $fontSize = 6.25;
    $lineHeight = 3.05;
    $paddingY = 1.3;

    if ($preparedRows === []) {
        $pdf->setFillColor(254, 242, 242);
        $pdf->setStrokeColor(248, 113, 113);
        $pdf->rect($margin, $y + 4.0, $contentWidth, 16.0, true, true);
        $pdf->text($margin, $y + 13.5, 'Tiada rekod pelajar yang memenuhi kriteria laporan.', 10.0, true, 'center', $contentWidth);
    } else {
        foreach ($preparedRows as $row) {
            $wrapped = [];
            $maxLines = 1;
            foreach ($columns as $column) {
                $key = (string)$column['key'];
                $cellLines = $pdf->wrapText((string)($row[$key] ?? '-'), (float)$column['width'] - 2.0, $fontSize, false);
                $cellLines = array_slice($cellLines, 0, 4);
                $wrapped[$key] = $cellLines;
                $maxLines = max($maxLines, count($cellLines));
            }
            $rowHeight = max(6.5, ($maxLines * $lineHeight) + ($paddingY * 2.0));
            if ($y + $rowHeight > $bottomLimit) {
                $y = $startNewPage(false);
            }

            $x = $margin;
            $pdf->setStrokeColor(148, 163, 184);
            foreach ($columns as $column) {
                $key = (string)$column['key'];
                $width = (float)$column['width'];
                $align = (string)$column['align'];
                $pdf->rect($x, $y, $width, $rowHeight, false, true);
                $lines = $wrapped[$key] ?? [''];
                $blockHeight = count($lines) * $lineHeight;
                $baseline = $y + (($rowHeight - $blockHeight) / 2.0) + 2.45;
                foreach ($lines as $line) {
                    $pdf->text($x + 1.0, $baseline, $line, $fontSize, false, $align, $width - 2.0);
                    $baseline += $lineHeight;
                }
                $x += $width;
            }
            $y += $rowHeight;
        }
    }

    return $pdf->output(static function (ZurieSimplePdf $pdf, int $page, int $total) use ($margin, $pageWidth, $pageHeight, $reportNo): void {
        $pdf->setStrokeColor(203, 213, 225);
        $pdf->line($margin, $pageHeight - 8.0, $pageWidth - $margin, $pageHeight - 8.0);
        $pdf->text($margin, $pageHeight - 4.2, 'Dijana oleh ZURIE | ' . $reportNo, 6.3, false);
        $pdf->text($pageWidth - $margin - 38.0, $pageHeight - 4.2, 'Halaman ' . $page . ' / ' . $total, 6.3, false, 'right', 38.0);
    });
}

function zurie_pdf_short_date(string $value): string
{
    if (trim($value) === '') {
        return '-';
    }
    try {
        return (new DateTimeImmutable($value))->format('d/m/Y');
    } catch (Throwable $e) {
        return $value;
    }
}

function zurie_pdf_date_time(string $value): string
{
    if (trim($value) === '') {
        return '-';
    }
    try {
        return (new DateTimeImmutable($value))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

<?php
/**
 * Branded exports (Excel + PDF) from one neutral report model, so every download in the app
 * and the admin panel looks the same: ExpenseFlow logo and name, who/what/when, tidy tables.
 *
 * $report = [
 *   'title'    => 'Financial Report',
 *   'subtitle' => 'Period: 01 Jan 2026 to 23 Oct 2026',
 *   'meta'     => [['Prepared for', 'Name (email)'], ['Generated', '...']],
 *   'summary'  => [['Total income', 12345.5, 'in'|'ex'|'' , 'PKR']],           // KPI cards
 *   'sections' => [[
 *       'name'    => 'Transactions',
 *       'columns' => [['Date','date'], ['Title','text'], ['Amount','money']],     // label, type, optional width weight
 *       'rows'    => [[ '2026-01-02', 'Lunch', 12.5 ]],
 *       'footer'  => ['Total', '', 12.5],                                         // optional totals row
 *       'tone'    => fn(array $row): string  // optional: 'in' | 'ex' row tint
 *   ]],
 *   'note'     => 'Optional closing line',
 * ];
 */

const EF_EXPORT_BRAND = 'ExpenseFlow';
const EF_EXPORT_TAGLINE = 'Track. Budget. Split.';

function ef_export_logo(): string
{
    return __DIR__ . '/../assets/icons/favicon-192.png';
}

function ef_export_filename(string $stem, string $ext): string
{
    return 'ExpenseFlow-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $stem) . '-' . date('Y-m-d') . '.' . $ext;
}

function ef_export_can_xlsx(): bool
{
    return class_exists('ZipArchive') && is_file(__DIR__ . '/../vendor/autoload.php');
}

function ef_export_can_pdf(): bool
{
    return is_file(__DIR__ . '/../tcpdf/tcpdf.php');
}

function ef_export_fmt($v, string $type): string
{
    if ($type === 'money') {
        return number_format((float) $v, 2);
    }
    if ($type === 'int') {
        return (string) (int) $v;
    }
    return (string) $v;
}

/** Stops spreadsheet apps from running a cell as a formula (e.g. a title starting with "=" or "@"). */
function ef_export_safe($value): string
{
    $value = (string) $value;
    return ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) ? "'" . $value : $value;
}

/* ============================== EXCEL ============================== */

function ef_export_xlsx(array $report, string $filename): void
{
    require_once __DIR__ . '/../vendor/autoload.php';
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ss->getProperties()->setCreator(EF_EXPORT_BRAND)->setTitle(EF_EXPORT_BRAND . ' ' . $report['title'])->setCompany(EF_EXPORT_BRAND);
    $navy = '0B2545';
    $teal = '137A7F';
    $thin = ['borders' => ['bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'D9E2EC']]]];

    // ---- Summary sheet ----
    $ws = $ss->getActiveSheet();
    $ws->setTitle('Summary');
    $ws->setShowGridlines(false);
    $ws->getColumnDimension('A')->setWidth(4);
    $ws->getColumnDimension('B')->setWidth(30);
    $ws->getColumnDimension('C')->setWidth(26);
    $ws->getColumnDimension('D')->setWidth(14);
    $ws->getRowDimension(1)->setRowHeight(8);
    $ws->getRowDimension(2)->setRowHeight(34);
    $ws->getRowDimension(3)->setRowHeight(34);
    if (is_file(ef_export_logo())) {
        $d = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $d->setPath(ef_export_logo())->setCoordinates('B2')->setHeight(56)->setOffsetX(2)->setOffsetY(2)->setWorksheet($ws);
        $ws->getRowDimension(2)->setRowHeight(30);
        $ws->getRowDimension(3)->setRowHeight(30);
    }
    $ws->setCellValue('C2', EF_EXPORT_BRAND)->getStyle('C2')->applyFromArray(['font' => ['bold' => true, 'size' => 22, 'color' => ['rgb' => $teal]]]);
    $ws->setCellValue('C3', EF_EXPORT_TAGLINE)->getStyle('C3')->applyFromArray(['font' => ['size' => 10, 'color' => ['rgb' => '5B6B80']]]);
    $r = 5;
    $ws->mergeCells("B$r:D$r");
    $ws->setCellValue("B$r", $report['title']);
    $ws->getStyle("B$r:D$r")->applyFromArray(['font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $navy]], 'alignment' => ['vertical' => 'center', 'indent' => 1]]);
    $ws->getRowDimension($r)->setRowHeight(28);
    $r++;
    if (!empty($report['subtitle'])) {
        $ws->mergeCells("B$r:D$r");
        $ws->setCellValue("B$r", $report['subtitle'])->getStyle("B$r")->applyFromArray(['font' => ['italic' => true, 'color' => ['rgb' => '5B6B80']]]);
        $r++;
    }
    $r++;
    foreach ($report['meta'] ?? [] as [$k, $v]) {
        $ws->setCellValue("B$r", $k)->getStyle("B$r")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => $navy]]]);
        $ws->setCellValueExplicit("C$r", (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->mergeCells("C$r:D$r");
        $r++;
    }
    if (!empty($report['summary'])) {
        $r++;
        $ws->setCellValue("B$r", 'Key figures')->getStyle("B$r:D$r")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $teal]]]);
        $ws->setCellValue("C$r", '')->setCellValue("D$r", '');
        $r++;
        foreach ($report['summary'] as $s) {
            $label = $s[0];
            $val = $s[1];
            $tone = $s[2] ?? '';
            $ws->setCellValue("B$r", $label);
            if (is_numeric($val)) {
                $ws->setCellValue("C$r", (float) $val);
                $ws->getStyle("C$r")->getNumberFormat()->setFormatCode(!empty($s[4]) ? $s[4] : '#,##0.00');
            } else {
                $ws->setCellValueExplicit("C$r", (string) $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $ws->setCellValue("D$r", $s[3] ?? '');
            $color = $tone === 'in' ? '0D9F6E' : ($tone === 'ex' ? 'EA580C' : $navy);
            $ws->getStyle("C$r")->applyFromArray(['font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => $color]], 'alignment' => ['horizontal' => 'right']]);
            $ws->getStyle("B$r:D$r")->applyFromArray($thin);
            $r++;
        }
    }
    if (!empty($report['note'])) {
        $r++;
        $ws->mergeCells("B$r:D" . ($r + 2));
        $ws->setCellValue("B$r", $report['note']);
        $ws->getStyle("B$r")->applyFromArray(['alignment' => ['wrapText' => true, 'vertical' => 'top'], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FEF9E3']], 'font' => ['color' => ['rgb' => '5B4B00']]]);
        $r += 3;
    }
    $r += 2;
    $ws->setCellValue("B$r", 'Generated by ' . EF_EXPORT_BRAND . ' on ' . date('d M Y, H:i'))->getStyle("B$r")->applyFromArray(['font' => ['size' => 9, 'color' => ['rgb' => '8896A8']]]);

    // ---- One sheet per section ----
    foreach ($report['sections'] as $sec) {
        $sh = $ss->createSheet();
        $title = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', (string) $sec['name']);
        $sh->setTitle(mb_substr($title, 0, 31));
        $cols = $sec['columns'];
        $n = count($cols);
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($n);
        $sh->mergeCells("A1:{$lastCol}1");
        $sh->setCellValue('A1', EF_EXPORT_BRAND . '  |  ' . $report['title'] . '  |  ' . $sec['name']);
        $sh->getStyle("A1:{$lastCol}1")->applyFromArray(['font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $navy]], 'alignment' => ['vertical' => 'center', 'indent' => 1]]);
        $sh->getRowDimension(1)->setRowHeight(26);
        foreach ($cols as $i => $c) {
            $L = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sh->setCellValue($L . '2', $c[0]);
            $sh->getColumnDimension($L)->setWidth(max(12, (int) ($c[2] ?? 1) * 14));
        }
        $sh->getStyle("A2:{$lastCol}2")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $teal]], 'alignment' => ['vertical' => 'center']]);
        $sh->getRowDimension(2)->setRowHeight(22);
        $row = 3;
        foreach ($sec['rows'] as $ri => $data) {
            foreach ($cols as $i => $c) {
                $L = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $v = $data[$i] ?? '';
                if ($c[1] === 'money' || $c[1] === 'int') {
                    $sh->setCellValue($L . $row, (float) $v);
                    $sh->getStyle($L . $row)->getNumberFormat()->setFormatCode($c[1] === 'money' ? '#,##0.00' : '0');
                    $sh->getStyle($L . $row)->getAlignment()->setHorizontal('right');
                } else {
                    $sh->setCellValueExplicit($L . $row, ef_export_safe($v), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
            $tone = isset($sec['tone']) ? ($sec['tone'])($data) : '';
            $fill = $tone === 'in' ? 'EAF7F0' : ($tone === 'ex' ? 'FFF1E8' : ($ri % 2 ? 'F6F9FB' : 'FFFFFF'));
            $sh->getStyle("A$row:$lastCol$row")->applyFromArray(['fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $fill]]] + $thin);
            $row++;
        }
        if (!empty($sec['footer'])) {
            foreach ($cols as $i => $c) {
                $L = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $v = $sec['footer'][$i] ?? '';
                if (($c[1] === 'money') && $v !== '') {
                    $sh->setCellValue($L . $row, (float) $v);
                    $sh->getStyle($L . $row)->getNumberFormat()->setFormatCode('#,##0.00');
                    $sh->getStyle($L . $row)->getAlignment()->setHorizontal('right');
                } else {
                    $sh->setCellValueExplicit($L . $row, (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
            $sh->getStyle("A$row:$lastCol$row")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => $navy]], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E3EDFA']], 'borders' => ['top' => ['borderStyle' => 'medium', 'color' => ['rgb' => $navy]]]]);
        }
        $sh->freezePane('A3');
        if (count($sec['rows']) > 0) {
            $sh->setAutoFilter("A2:{$lastCol}" . (2 + count($sec['rows'])));
        }
        $sh->setShowGridlines(false);
        $sh->getPageSetup()->setOrientation($n > 6 ? 'landscape' : 'portrait')->setFitToWidth(1)->setFitToHeight(0);
        $sh->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 2);
        $sh->getHeaderFooter()->setOddFooter('&L' . EF_EXPORT_BRAND . '&CPage &P of &N&R&D');
    }
    $ss->setActiveSheetIndex(0);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    $ss->disconnectWorksheets();
}

/* ============================== PDF ============================== */

function ef_export_pdf(array $report, string $filename): void
{
    require_once __DIR__ . '/../tcpdf/tcpdf.php';

    $pdf = new class(PDF_PAGE_ORIENTATION, 'mm', 'A4', true, 'UTF-8', false) extends TCPDF {
        public array $rep = [];
        public function Header()
        {
            $logo = ef_export_logo();
            if (is_file($logo)) {
                $this->Image($logo, 12, 8, 13, 13, 'PNG');
            }
            $this->SetFont('dejavusans', 'B', 15);
            $this->SetTextColor(19, 122, 127);
            $this->SetXY(28, 8);
            $this->Cell(80, 7, EF_EXPORT_BRAND, 0, 0, 'L');
            $this->SetFont('dejavusans', '', 7.5);
            $this->SetTextColor(91, 107, 128);
            $this->SetXY(28, 15);
            $this->Cell(80, 5, EF_EXPORT_TAGLINE, 0, 0, 'L');
            $this->SetFont('dejavusans', 'B', 9);
            $this->SetTextColor(11, 37, 69);
            $this->SetXY($this->getPageWidth() - 100, 9);
            $this->Cell(88, 5, $this->rep['title'] ?? '', 0, 0, 'R');
            $this->SetDrawColor(19, 122, 127);
            $this->SetLineWidth(0.6);
            $this->Line(12, 24, $this->getPageWidth() - 12, 24);
        }
        public function Footer()
        {
            $this->SetY(-13);
            $this->SetDrawColor(217, 226, 236);
            $this->SetLineWidth(0.2);
            $this->Line(12, $this->GetY(), $this->getPageWidth() - 12, $this->GetY());
            $this->SetFont('dejavusans', '', 7);
            $this->SetTextColor(136, 150, 168);
            $this->Cell(100, 8, 'Generated by ' . EF_EXPORT_BRAND . ' on ' . date('d M Y, H:i'), 0, 0, 'L');
            $this->Cell(0, 8, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
        }
    };
    $pdf->rep = $report;
    $pdf->SetCreator(EF_EXPORT_BRAND);
    $pdf->SetAuthor(EF_EXPORT_BRAND);
    $pdf->SetTitle(EF_EXPORT_BRAND . ' ' . $report['title']);
    $pdf->SetMargins(12, 30, 12);
    $pdf->SetHeaderMargin(6);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->setFontSubsetting(true);
    $pdf->AddPage();
    $W = $pdf->getPageWidth() - 24;

    // Title block
    $pdf->SetFont('dejavusans', 'B', 17);
    $pdf->SetTextColor(11, 37, 69);
    $pdf->Cell(0, 9, $report['title'], 0, 1, 'L');
    if (!empty($report['subtitle'])) {
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetTextColor(91, 107, 128);
        $pdf->Cell(0, 5, $report['subtitle'], 0, 1, 'L');
    }
    $pdf->Ln(2);
    foreach ($report['meta'] ?? [] as [$k, $v]) {
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->SetTextColor(11, 37, 69);
        $pdf->Cell(32, 5, $k, 0, 0, 'L');
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(60, 72, 90);
        $pdf->Cell(0, 5, (string) $v, 0, 1, 'L');
    }
    $pdf->Ln(3);

    // KPI cards
    $cards = $report['summary'] ?? [];
    if ($cards) {
        $per = min(4, count($cards));
        $gap = 3;
        $cw = ($W - $gap * ($per - 1)) / $per;
        $x0 = 12;
        $y0 = $pdf->GetY();
        foreach ($cards as $i => $s) {
            if ($i && $i % $per === 0) {
                $y0 += 21;
                $x0 = 12;
            }
            $pdf->SetFillColor(240, 247, 248);
            $pdf->SetDrawColor(217, 226, 236);
            $pdf->RoundedRect($x0, $y0, $cw, 18, 2, '1111', 'DF');
            $pdf->SetXY($x0 + 3, $y0 + 2.5);
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetTextColor(91, 107, 128);
            $pdf->Cell($cw - 6, 4, $s[0], 0, 1, 'L');
            $pdf->SetXY($x0 + 3, $y0 + 8);
            $pdf->SetFont('dejavusans', 'B', 11);
            $tone = $s[2] ?? '';
            $tone === 'in' ? $pdf->SetTextColor(13, 159, 110) : ($tone === 'ex' ? $pdf->SetTextColor(234, 88, 12) : $pdf->SetTextColor(11, 37, 69));
            $pct = isset($s[4]) && str_contains((string) $s[4], '%');
            $val = is_numeric($s[1]) ? number_format((float) $s[1], $pct ? 1 : (is_int($s[1]) ? 0 : 2)) . ($pct ? '%' : '') : (string) $s[1];
            $pdf->Cell($cw - 6, 6, $val . (!empty($s[3]) ? ' ' . $s[3] : ''), 0, 1, 'L');
            $x0 += $cw + $gap;
        }
        $pdf->SetY($y0 + 22);
    }
    if (!empty($report['note'])) {
        $pdf->SetFillColor(254, 249, 227);
        $pdf->SetDrawColor(19, 122, 127);
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(91, 75, 0);
        $pdf->MultiCell($W, 8, $report['note'], 0, 'L', true, 1, 12, '', true, 0, false, true, 8, 'M');
        $pdf->Ln(3);
    }

    // Tables
    foreach ($report['sections'] as $sec) {
        $cols = $sec['columns'];
        $weights = array_map(fn($c) => (float) ($c[2] ?? 1), $cols);
        $sum = array_sum($weights);
        $widths = array_map(fn($w) => $W * $w / $sum, $weights);
        if ($pdf->GetY() > $pdf->getPageHeight() - 55) {
            $pdf->AddPage();
        }
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetTextColor(11, 37, 69);
        $pdf->Cell(0, 8, $sec['name'], 0, 1, 'L');
        $head = function () use ($pdf, $cols, $widths) {
            $pdf->SetFillColor(19, 122, 127);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetDrawColor(19, 122, 127);
            $pdf->SetFont('dejavusans', 'B', 7.5);
            foreach ($cols as $i => $c) {
                $pdf->Cell($widths[$i], 7, $c[0], 0, 0, in_array($c[1], ['money', 'int'], true) ? 'R' : 'L', true);
            }
            $pdf->Ln();
        };
        $head();
        $pdf->SetFont('dejavusans', '', 7.5);
        if (!$sec['rows']) {
            $pdf->SetTextColor(91, 107, 128);
            $pdf->Cell($W, 8, 'No records.', 'B', 1, 'L');
        }
        foreach ($sec['rows'] as $ri => $data) {
            if ($pdf->GetY() > $pdf->getPageHeight() - 24) {
                $pdf->AddPage();
                $head();
                $pdf->SetFont('dejavusans', '', 7.5);
            }
            $tone = isset($sec['tone']) ? ($sec['tone'])($data) : '';
            $tone === 'in' ? $pdf->SetFillColor(234, 247, 240) : ($tone === 'ex' ? $pdf->SetFillColor(255, 241, 232) : ($ri % 2 ? $pdf->SetFillColor(246, 249, 251) : $pdf->SetFillColor(255, 255, 255)));
            $pdf->SetTextColor(40, 52, 70);
            foreach ($cols as $i => $c) {
                $txt = ef_export_fmt($data[$i] ?? '', $c[1]);
                $txt = mb_strimwidth($txt, 0, (int) max(8, $widths[$i] * 2.1), '…', 'UTF-8');
                $pdf->Cell($widths[$i], 6.2, $txt, 'B', 0, in_array($c[1], ['money', 'int'], true) ? 'R' : 'L', true);
            }
            $pdf->Ln();
        }
        if (!empty($sec['footer'])) {
            $pdf->SetFillColor(227, 237, 250);
            $pdf->SetTextColor(11, 37, 69);
            $pdf->SetFont('dejavusans', 'B', 7.5);
            foreach ($cols as $i => $c) {
                $pdf->Cell($widths[$i], 7, ef_export_fmt($sec['footer'][$i] ?? '', $c[1]), 'T', 0, in_array($c[1], ['money', 'int'], true) ? 'R' : 'L', true);
            }
            $pdf->Ln();
        }
        $pdf->Ln(5);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $pdf->Output($filename, 'D');
}

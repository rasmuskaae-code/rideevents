<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/tfpdf/tfpdf.php';
require_once __DIR__ . '/vendor/tfpdf/font/unifont/ttfonts.php';

final class HelperReportPdf extends tFPDF
{
    protected $cacheUnicodeFonts = false;
    private const WIDTHS = [32, 34, 26, 26, 31, 43, 14, 13, 22, 32];

    public function __construct(private string $eventName, private string $meta, private array $columns)
    {
        parent::__construct('L', 'mm', 'A4');
        $this->SetMargins(12, 11, 12);
        $this->SetAutoPageBreak(false);
        $this->fontpath = __DIR__ . '/vendor/tfpdf/font/';
        $this->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
        $this->SetTitle(cleanReportText('Hjælperoversigt · ' . $eventName), true);
    }

    public function Header()
    {
        $this->SetTextColor(29, 56, 43);
        $this->SetFont('DejaVu', '', 16);
        $this->Cell(0, 8, 'Hjælperoversigt', 0, 1);
        $this->SetFont('DejaVu', '', 12);
        $this->MultiCell(0, 6, cleanReportText($this->eventName));
        $this->SetFont('DejaVu', '', 8);
        $this->MultiCell(0, 5, cleanReportText($this->meta));
        $this->Ln(4);
        $this->tableRow($this->columns, true);
    }

    public function Footer()
    {
        $this->SetY(-12);
        $this->SetTextColor(83, 99, 90);
        $this->SetFont('DejaVu', '', 8);
        $this->Cell(0, 5, 'Side ' . $this->PageNo(), 0, 0, 'R');
    }

    public function tableRow(array $values, bool $header = false): void
    {
        $this->SetFont('DejaVu', '', 7.5);
        $columns = [];
        foreach (self::WIDTHS as $index => $width) {
            $columns[] = $this->wrap(cleanReportText((string) ($values[$index] ?? '')), $width - 4);
        }
        $height = 2 + max(array_map('count', $columns)) * 4;
        if (!$header && $this->GetY() + $height > 194) {
            $this->AddPage();
        }

        $x = 12;
        $y = $this->GetY();
        $this->SetDrawColor(219, 228, 220);
        $this->SetFillColor(32, 68, 50);
        $this->SetTextColor(...($header ? [255,255,255] : [30,39,34]));
        foreach ($columns as $index => $lines) {
            $width = self::WIDTHS[$index];
            $this->Rect($x, $y, $width, $height, $header ? 'DF' : 'D');
            foreach ($lines as $line => $text) {
                $this->SetXY($x + 1, $y + 1 + 4 * $line);
                $this->Cell($width - 2, 4, $text);
            }
            $x += $width;
        }
        $this->SetXY(12, $y + $height);
    }

    private function wrap(string $text, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = $line === '' ? $word : "$line $word";
            if ($this->GetStringWidth($candidate) <= $width) {
                $line = $candidate;
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = '';
            foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) as $char) {
                if ($this->GetStringWidth($line . $char) > $width) {
                    $lines[] = $line;
                    $line = '';
                }
                $line .= $char;
            }
        }
        $lines[] = $line;
        return $lines;
    }
}

function cleanReportText(string $text): string
{
    return trim(preg_replace('/[\p{Cc}\s]+/u', ' ', $text));
}

function helperReportPdf(string $event, string $meta, array $columns, array $entries): string
{
    $pdf = new HelperReportPdf($event, $meta, $columns);
    $pdf->AddPage();
    if (!$entries) {
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->Cell(0, 10, 'Stævnet har endnu ingen vagtgrupper.');
    }
    foreach ($entries as $entry) {
        foreach ([2,3] as $index) {
            $entry[$index] = $entry[$index] === null ? '' : date('d.m.Y H:i', $entry[$index]);
        }
        $pdf->tableRow($entry);
    }
    return $pdf->Output('S');
}

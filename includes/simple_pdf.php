<?php

/**
 * Minimal PDF generator with multi-page table support (no external dependencies).
 */
class SimplePdf
{
    private array $pages = [];
    private string $title;
    private array $meta = [];
    private int $pageWidth = 842;
    private int $pageHeight = 595;
    private int $marginLeft = 36;
    private int $marginTop = 36;
    private int $marginBottom = 36;
    private int $cursorY = 0;
    private array $currentStream = [];
    private bool $pageOpen = false;
    private bool $wroteReportHeader = false;
    private bool $includeReportHeader = true;

    public function __construct(string $title = 'E-PASSPORT Report', bool $landscape = true)
    {
        $this->title = $title;

        if (!$landscape) {
            $this->pageWidth = 595;
            $this->pageHeight = 842;
        }
    }

    public function setMeta(array $meta): void
    {
        $this->meta = $meta;
    }

    public function setIncludeReportHeader(bool $include): void
    {
        $this->includeReportHeader = $include;
    }

    private function startPage(bool $withReportHeader = false): void
    {
        $this->currentStream = [];
        $this->cursorY = $this->pageHeight - $this->marginTop;
        $this->pageOpen = true;

        if ($withReportHeader && !$this->wroteReportHeader) {
            $this->writeText($this->title, 14, true);
            $this->cursorY -= 6;
            $this->writeText('Generated: ' . date('M j, Y g:i A') . ' (PHT)', 8, false, [95, 103, 145]);

            foreach ($this->meta as $label => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }

                $this->writeText($label . ': ' . $value, 8, false, [31, 47, 136]);
            }

            $this->cursorY -= 8;
            $this->wroteReportHeader = true;
        }
    }

    private function finishPage(): void
    {
        if (!empty($this->currentStream)) {
            $this->pages[] = implode("\n", $this->currentStream);
        }

        $this->currentStream = [];
        $this->pageOpen = false;
    }

    public function addTable(array $headers, array $rows): void
    {
        $labels = [];
        $keys = [];

        foreach ($headers as $key => $label) {
            $keys[] = is_string($key) ? $key : (string) $label;
            $labels[] = is_string($label) ? $label : (string) $label;
        }

        if (!$this->pageOpen) {
            $this->startPage($this->includeReportHeader);
        }

        $usableWidth = $this->pageWidth - ($this->marginLeft * 2);
        $colCount = max(1, count($labels));
        $colWidth = (int) floor($usableWidth / $colCount);
        $rowHeight = 16;
        $fontSize = 7;

        $drawRow = function (array $cells, bool $header = false) use ($colWidth, $rowHeight, $fontSize, $labels, &$drawRow): void {
            if ($this->cursorY - $rowHeight < $this->marginBottom) {
                $this->finishPage();
                $this->startPage(false);
                if (!$header) {
                    $drawRow($labels, true);
                }
            }

            $x = $this->marginLeft;
            $yTop = $this->cursorY;
            $yBottom = $this->cursorY - $rowHeight;

            for ($i = 0; $i < count($labels); $i++) {
                $cellText = $this->sanitize((string) ($cells[$i] ?? ''));
                $fill = $header ? [31, 47, 136] : [255, 255, 255];
                $stroke = [201, 210, 239];
                $textColor = $header ? [255, 255, 255] : [28, 37, 86];

                $this->currentStream[] = sprintf(
                    'q %.3f %.3f %.3f rg %.3f %.3f %.3f w %.3f %.3f %.3f %.3f re B Q',
                    $fill[0] / 255,
                    $fill[1] / 255,
                    $fill[2] / 255,
                    $stroke[0] / 255,
                    $stroke[1] / 255,
                    $stroke[2] / 255,
                    $x,
                    $yBottom,
                    $colWidth,
                    $rowHeight
                );

                $wrapped = $this->wrapText($cellText, $colWidth - 6, $fontSize);
                $textY = $yTop - 11;

                foreach ($wrapped as $line) {
                    $this->currentStream[] = sprintf(
                        'BT /F%s %d Tf %.3f %.3f %.3f rg 1 0 0 1 %.2f %.2f Tm (%s) Tj ET',
                        $header ? '2' : '1',
                        $fontSize,
                        $textColor[0] / 255,
                        $textColor[1] / 255,
                        $textColor[2] / 255,
                        $x + 3,
                        $textY,
                        $line
                    );
                    $textY -= ($fontSize + 1);
                }

                $x += $colWidth;
            }

            $this->cursorY -= $rowHeight;
        };

        $drawRow($labels, true);

        if (empty($rows)) {
            $drawRow(array_fill(0, count($labels), 'No records found.'));

            return;
        }

        foreach ($rows as $row) {
            $cells = [];

            foreach ($keys as $field) {
                $cells[] = (string) ($row[$field] ?? '');
            }

            $drawRow($cells, false);
        }
    }

    private function writeText(string $text, int $size, bool $bold, ?array $rgb = null): void
    {
        $rgb = $rgb ?? [28, 37, 86];
        $this->currentStream[] = sprintf(
            'BT /F%s %d Tf %.3f %.3f %.3f rg 1 0 0 1 %.2f %.2f Tm (%s) Tj ET',
            $bold ? '2' : '1',
            $size,
            $rgb[0] / 255,
            $rgb[1] / 255,
            $rgb[2] / 255,
            $this->marginLeft,
            $this->cursorY,
            $this->sanitize($text)
        );
        $this->cursorY -= ($size + 8);
    }

    private function wrapText(string $text, int $maxWidth, int $fontSize): array
    {
        $text = trim($text);

        if ($text === '') {
            return [''];
        }

        $maxChars = max(8, (int) floor($maxWidth / ($fontSize * 0.45)));
        $words = preg_split('/\s+/', $text) ?: [$text];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if (strlen($candidate) <= $maxChars) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            $current = strlen($word) > $maxChars ? substr($word, 0, $maxChars) : $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [''];
    }

    private function sanitize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text) ?? '';

        return $text;
    }

    public function output(string $filename): void
    {
        $this->finishPage();

        if (empty($this->pages)) {
            $this->startPage($this->includeReportHeader);
            $this->finishPage();
        }

        $pageCount = count($this->pages);
        $fontRegularNum = 3 + ($pageCount * 2);
        $fontBoldNum = $fontRegularNum + 1;
        $objects = [];
        $pageRefs = [];

        $objects[0] = '<< /Type /Catalog /Pages 2 0 R >>';

        for ($i = 0; $i < $pageCount; $i++) {
            $pageNum = 3 + ($i * 2);
            $contentNum = $pageNum + 1;
            $pageRefs[] = $pageNum . ' 0 R';

            $objects[$pageNum - 1] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> >>',
                $this->pageWidth,
                $this->pageHeight,
                $contentNum,
                $fontRegularNum,
                $fontBoldNum
            );

            $stream = $this->pages[$i];
            $objects[$contentNum - 1] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . $pageCount . ' >>';
        $objects[$fontRegularNum - 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[$fontBoldNum - 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefPos . "\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        echo $pdf;
    }
}

function renderReportPdf(string $title, array $rows, array $headers, array $meta = [], bool $includeReportHeader = true): void
{
    $pdf = new SimplePdf($title, true);
    $pdf->setMeta($meta);
    $pdf->setIncludeReportHeader($includeReportHeader);
    $pdf->addTable($headers, $rows);
    $pdf->output($title . '.pdf');
}

<?php

require_once __DIR__ . '/simple_pdf.php';

/**
 * Render a structured PDF report with HTML tables (Dompdf) or built-in table PDF.
 */
function renderTablePdf(string $title, array $rows, array $headers, array $meta = [], bool $includeReportHeader = true): void
{
    $vendorAutoload = __DIR__ . '/../vendor/autoload.php';

    if (is_file($vendorAutoload)) {
        require_once $vendorAutoload;
        renderDompdfTable($title, $rows, $headers, $meta, $includeReportHeader);

        return;
    }

    renderReportPdf($title, $rows, $headers, $meta, $includeReportHeader);
}

function renderDompdfTable(string $title, array $rows, array $headers, array $meta = [], bool $includeReportHeader = true): void
{
    $html = buildReportHtml($title, $rows, $headers, $meta, $includeReportHeader);

    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'defaultFont' => 'Helvetica',
    ]);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $title) . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $dompdf->output();
}

function buildReportHtml(string $title, array $rows, array $headers, array $meta = [], bool $includeReportHeader = true): string
{
    $esc = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $metaHtml = '';

    foreach ($meta as $label => $value) {
        if ($value === '' || $value === null) {
            continue;
        }

        $metaHtml .= '<tr><td class="meta-label">' . $esc($label) . '</td><td>' . $esc($value) . '</td></tr>';
    }

    $headCells = '';

    foreach ($headers as $label) {
        $headCells .= '<th>' . $esc(is_string($label) ? $label : (string) $label) . '</th>';
    }

    $bodyRows = '';

    if (empty($rows)) {
        $colspan = max(1, count($headers));
        $bodyRows = '<tr><td colspan="' . $colspan . '" class="empty">No records found for the selected filters.</td></tr>';
    }

    foreach ($rows as $row) {
        $bodyRows .= '<tr>';

        foreach ($headers as $key => $label) {
            $field = is_string($key) ? $key : $label;
            $bodyRows .= '<td>' . $esc($row[$field] ?? '') . '</td>';
        }

        $bodyRows .= '</tr>';
    }

    return '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 28px 24px; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #1c2556; }
    h1 { font-size: 16px; margin: 0 0 4px; color: #1f2f88; }
    .subtitle { font-size: 9px; color: #5f6791; margin-bottom: 12px; }
    .meta { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .meta td { border: 1px solid #d7def2; padding: 4px 6px; vertical-align: top; }
    .meta-label { width: 120px; font-weight: bold; background: #f6f8ff; }
    table.report { width: 100%; border-collapse: collapse; }
    table.report th, table.report td { border: 1px solid #c9d2ef; padding: 4px 5px; vertical-align: top; word-wrap: break-word; }
    table.report th { background: #1f2f88; color: #fff; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em; }
    table.report tr:nth-child(even) td { background: #f8faff; }
    .empty { text-align: center; color: #5f6791; font-style: italic; }
    .footer { margin-top: 10px; font-size: 8px; color: #5f6791; }
</style>
</head>
<body>
    ' . ($includeReportHeader
        ? '<h1>' . $esc($title) . '</h1>
    <div class="subtitle">Generated: ' . $esc(date('M j, Y g:i A')) . ' (PHT)</div>'
        . ($metaHtml !== '' ? '<table class="meta">' . $metaHtml . '</table>' : '')
        : '')
    . '<table class="report"><thead><tr>' . $headCells . '</tr></thead><tbody>' . $bodyRows . '</tbody></table>'
    . '</body></html>';
}

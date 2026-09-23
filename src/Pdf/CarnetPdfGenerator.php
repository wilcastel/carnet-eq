<?php

declare(strict_types=1);

namespace CarnetEquidad\Pdf;

/**
 * Renders an in-memory, non-editable carnet from the approved visual template.
 * No generated file is written to disk: callers receive the PDF bytes directly.
 */
final class CarnetPdfGenerator
{
    private const PLACEHOLDER = 'PENDIENTE DE CONFIRMACIÓN';

    /** Real card-size dimensions of the approved artwork, in points. */
    private const CARD_WIDTH = 238.512;
    private const CARD_HEIGHT = 141.732;

    /**
     * Output sheet: US Letter portrait, in points. A test print on plain
     * paper (no printer settings touched) showed the previous card-sized,
     * 2-page output landing one card per physical sheet, at (0,0) with no
     * margin — printers/viewers place a PDF page at its native size on
     * whatever paper is loaded, one page per sheet, with no built-in margin.
     * A single Letter-size sheet with both faces placed inside SHEET_MARGIN
     * prints correctly with default settings on any printer loaded with
     * Letter *or* A4 (A4 is only ~6pt narrower/taller either side of Letter —
     * well inside this margin).
     */
    private const SHEET_WIDTH = 612.0;
    private const SHEET_HEIGHT = 792.0;
    private const SHEET_MARGIN_TOP = 60.0;
    private const CARD_GAP = 24.0;

    public function __construct(private readonly string $templatePath)
    {
    }

    /**
     * @param array<string, mixed> $record Fresh, server-side API record.
     */
    public function generate(string $document, array $record): string
    {
        $insured = $this->value($record, 'NOMBRE_ASEGURADO');
        if ($insured === '') {
            throw new \InvalidArgumentException('The selected record does not contain an insured name.');
        }

        if (! is_readable($this->templatePath)) {
            throw new \RuntimeException('Carnet PDF template is unavailable.');
        }

        // The approved artwork is a 2-page, real card-size (238.512 x
        // 141.732pt) design: page 1 is the front cover (static branding, no
        // dynamic data), page 2 is the blank-white back where every field
        // below is printed. Both sides are placed on one Letter-size sheet —
        // cover on top, data below, each ringed by a dashed cut guide — so a
        // plain, unmodified print puts both on the same physical page.
        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'pt', [self::SHEET_WIDTH, self::SHEET_HEIGHT], true, 'UTF-8', false);
        $pdf->SetCreator('Carnet Equidad');
        $pdf->SetTitle('Carnet');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setSourceFile($this->templatePath);
        $pdf->AddPage();

        $cardLeft = (self::SHEET_WIDTH - self::CARD_WIDTH) / 2;
        $coverTop = self::SHEET_MARGIN_TOP;
        $backTop = $coverTop + self::CARD_HEIGHT + self::CARD_GAP;

        $cover = $pdf->importPage(1);
        $pdf->useTemplate($cover, $cardLeft, $coverTop, self::CARD_WIDTH, self::CARD_HEIGHT);
        $this->cutGuide($pdf, $cardLeft, $coverTop);

        $template = $pdf->importPage(2);
        $pdf->useTemplate($template, $cardLeft, $backTop, self::CARD_WIDTH, self::CARD_HEIGHT);
        $this->cutGuide($pdf, $cardLeft, $backTop);

        $pdf->SetTextColor(120, 120, 120);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY($cardLeft, $coverTop - 14);
        $pdf->Cell(self::CARD_WIDTH, 10, 'Recortar por la línea punteada', 0, 0, 'C');

        // Institutional header (static, not from the API record): the company
        // name and La Equidad's own NIT, printed above the green banner in
        // the previously-blank top-left corner. Flagged as missing by the
        // client when comparing against the original two-column mockup.
        // Width capped at 135pt: measured where the artwork's top-right green
        // corner starts (~146.4pt) so the white field box never paints over it.
        $this->field($pdf, $cardLeft + 6, $backTop + 4, 135, 8, 'LA EQUIDAD SEGUROS DE VIDA O.', 6);
        $this->field($pdf, $cardLeft + 6, $backTop + 13, 135, 8, 'NIT. 830.008.686-1', 6);

        // Two-column layout matching the client's original mockup: policy
        // data on the left (wider, since Tomador/Asegurado names run long),
        // the queried document and dates on the right. Deliberately still
        // labeled "Documento" rather than "NIT" for the per-record field —
        // see the note on documentLabel() below.
        $this->field($pdf, $cardLeft + 6, $backTop + 58, 140, 9, 'Póliza: ' . $this->value($record, 'POLIZA'), 6);
        $this->field($pdf, $cardLeft + 6, $backTop + 69, 140, 16, 'Tomador: ' . $this->valueOrPlaceholder($record, 'NOMBRE_TOMADOR'), 6);
        $this->field($pdf, $cardLeft + 6, $backTop + 87, 140, 9, 'Asegurado: ' . $insured, 6);
        $this->field($pdf, $cardLeft + 6, $backTop + 98, 140, 16, 'V/r asegurado por gastos médicos: ' . $this->currency($record['VAL_GASTOS_MED'] ?? null), 6);

        $this->field($pdf, $cardLeft + 150, $backTop + 58, 78, 9, $this->documentLabel() . ': ' . $document, 6);
        $this->field($pdf, $cardLeft + 150, $backTop + 69, 78, 9, 'Orden: ' . $this->value($record, 'ORDEN'), 6);
        $this->field($pdf, $cardLeft + 150, $backTop + 80, 78, 9, 'Vigencia: ' . $this->date($record['FECHA_INICIO'] ?? null), 6);
        $this->field($pdf, $cardLeft + 150, $backTop + 91, 78, 9, 'Hasta: ' . $this->date($record['FECHA_FIN'] ?? null), 6);

        return $pdf->Output('', 'S');
    }

    /** Dashed outline around a card so it can be trimmed from the printed sheet. */
    private function cutGuide(\setasign\Fpdi\Tcpdf\Fpdi $pdf, float $x, float $y): void
    {
        $pdf->SetLineStyle(['width' => 0.5, 'dash' => '2,2', 'color' => [150, 150, 150]]);
        $pdf->Rect($x, $y, self::CARD_WIDTH, self::CARD_HEIGHT);
        $pdf->SetLineStyle(['width' => 0.5, 'dash' => 0, 'color' => [0, 0, 0]]);
    }

    private function field(\setasign\Fpdi\Tcpdf\Fpdi $pdf, float $x, float $y, float $width, float $height, string $value, float $fontSize): void
    {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $width, $height, 'F');
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('helvetica', 'B', $fontSize);
        $pdf->SetXY($x, $y + 2);
        // Plain MultiCell only: the maxh+valign auto-centering variant relies on
        // TCPDF's transaction (clone/rollback) machinery, which does not survive
        // a template imported via FPDI on this small a page — it silently drops
        // every field drawn before the last one. See CarnetPdfGeneratorTest.
        $pdf->MultiCell($width, $height - 2, $this->text($value), 0, 'L');
    }

    /**
     * The original design mockup labeled the queried document "NIT", but the
     * API never indicates whether a given document is a NIT (tax ID, legal
     * entities) or a CC (cédula de ciudadanía, natural persons) — there is no
     * type field in the response. Printing "NIT" on every carnet would
     * misrepresent a personal cédula, so this stays the generic "Documento"
     * label until the client can supply a document-type field to key off of.
     */
    private function documentLabel(): string
    {
        return 'Documento';
    }

    /** @param array<string, mixed> $record */
    private function value(array $record, string $key): string
    {
        $value = $record[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $matches) !== 1) {
            return self::PLACEHOLDER;
        }

        return $matches[1];
    }

    /**
     * @param array<string, mixed> $record
     * Same fallback precedent as {@see date()}: a newly-added field that the
     * API does not yet guarantee for every record degrades to the pending
     * placeholder instead of failing the whole carnet.
     */
    private function valueOrPlaceholder(array $record, string $key): string
    {
        $value = $this->value($record, $key);
        return $value === '' ? self::PLACEHOLDER : $value;
    }

    /** Formats a JSON float amount as whole-peso Colombian currency, e.g. "$6.000.000". */
    private function currency(mixed $value): string
    {
        if (! is_numeric($value)) {
            return self::PLACEHOLDER;
        }

        return '$' . number_format((float) $value, 0, ',', '.');
    }

    private function text(string $value): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
    }
}

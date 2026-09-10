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

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('L', 'pt', [1044, 320], true, 'UTF-8', false);
        $pdf->SetCreator('Carnet Equidad');
        $pdf->SetTitle('Carnet');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->setSourceFile($this->templatePath);
        $template = $pdf->importPage(1);
        $pdf->useTemplate($template, 0, 0, 1044, 954);

        // Cover only variable source values. The surrounding approved artwork
        // stays intact, including the product-specific wording pending policy.
        $this->field($pdf, 82, 143, 165, 20, $this->value($record, 'POLIZA'), 11);
        $this->field($pdf, 98, 167, 210, 35, self::PLACEHOLDER, 7);
        $this->field($pdf, 110, 215, 205, 20, $insured, 10);
        // The model's NIT field belongs to an institution. This is an insured
        // person's document, so replace the label rather than misrepresent it.
        $this->field($pdf, 346, 143, 150, 20, 'DOCUMENTO: ' . $document, 8);
        $this->field($pdf, 390, 167, 110, 20, $this->value($record, 'ORDEN'), 11);
        $this->field($pdf, 405, 191, 100, 20, $this->date($record['FECHA_INICIO'] ?? null), 8);
        $this->field($pdf, 388, 215, 115, 20, $this->date($record['FECHA_FIN'] ?? null), 8);
        $this->field($pdf, 242, 257, 195, 20, self::PLACEHOLDER, 6);

        return $pdf->Output('', 'S');
    }

    private function field(\setasign\Fpdi\Tcpdf\Fpdi $pdf, float $x, float $y, float $width, float $height, string $value, float $fontSize): void
    {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $width, $height, 'F');
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('helvetica', 'B', $fontSize);
        $pdf->SetXY($x, $y + 2);
        $pdf->MultiCell($width, $height - 2, $this->text($value), 0, 'L', false, 0, '', '', true, 0, false, true, $height - 2, 'M');
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

    private function text(string $value): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
    }
}

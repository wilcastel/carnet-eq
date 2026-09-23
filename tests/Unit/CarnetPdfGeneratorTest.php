<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Unit;

use CarnetEquidad\Pdf\CarnetPdfGenerator;
use PHPUnit\Framework\TestCase;

final class CarnetPdfGeneratorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function sampleRecord(): array
    {
        // Live sample returned by the upstream API for cédula 1094290592.
        return [
            'POLIZA' => 'AA011951',
            'ORDEN' => 1514,
            'CERTIFICADO' => 'AA200162',
            'SUCURSAL' => '300002',
            'FECHA_INICIO' => '2026-02-01T00:00:00',
            'FECHA_FIN' => '2027-02-01T00:00:00',
            'PRIMA' => null,
            'DOCUMENTO_ASEGURADO' => '1094290592',
            'NOMBRE_ASEGURADO' => 'VILLAMIZAR ACEVEDO EMILIANO',
            'DOCUMENTO_BENEFICIARIO' => 'Z0000000Z',
            'NOMBRE_BENEFICIARIO' => ' ',
            'DOCUMENTO_TOMADOR' => '37247531',
            'NOMBRE_TOMADOR' => 'GLORIA INES DUARTE DE ACEVEDO',
            'DETALLE_COBERTURA' => null,
            'VAL_GASTOS_MED' => 6000000.0,
        ];
    }

    private function generator(): CarnetPdfGenerator
    {
        return new CarnetPdfGenerator(__DIR__ . '/../../assets/templates/carnet-template.pdf');
    }

    /**
     * The generated content stream is compressed, so printed values are
     * verified through real text extraction (poppler's pdftotext) rather than
     * a raw byte search.
     */
    private function extractText(string $pdf): string
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext === '') {
            self::markTestSkipped('pdftotext (poppler-utils) is not available in this environment.');
        }

        $path = tempnam(sys_get_temp_dir(), 'carnet-pdf-') . '.pdf';
        file_put_contents($path, $pdf);

        $text = shell_exec(sprintf('%s -layout %s -', escapeshellcmd($pdftotext), escapeshellarg($path)));
        unlink($path);

        return (string) $text;
    }

    /**
     * @return int the page count reported by poppler's pdfinfo.
     */
    private function pageCount(string $pdf): int
    {
        return (int) $this->pdfinfo($pdf)['Pages'];
    }

    /**
     * @return array{0: float, 1: float} sheet width/height in pt, as reported by poppler's pdfinfo.
     */
    private function pageSizePt(string $pdf): array
    {
        $size = $this->pdfinfo($pdf)['Page size'];

        if (preg_match('/^([\d.]+) x ([\d.]+) pts/', $size, $matches) !== 1) {
            self::fail('pdfinfo did not report a parseable page size.');
        }

        return [(float) $matches[1], (float) $matches[2]];
    }

    /**
     * @return array<string, string> pdfinfo's "Key: value" output, keyed by field name.
     */
    private function pdfinfo(string $pdf): array
    {
        $pdfinfo = trim((string) shell_exec('command -v pdfinfo'));
        if ($pdfinfo === '') {
            self::markTestSkipped('pdfinfo (poppler-utils) is not available in this environment.');
        }

        $path = tempnam(sys_get_temp_dir(), 'carnet-pdf-') . '.pdf';
        file_put_contents($path, $pdf);

        $output = (string) shell_exec(sprintf('%s %s 2>/dev/null', escapeshellcmd($pdfinfo), escapeshellarg($path)));
        unlink($path);

        $fields = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^([A-Za-z ]+?):\s+(.*)$/', $line, $matches) === 1) {
                $fields[$matches[1]] = $matches[2];
            }
        }

        if (! isset($fields['Pages'])) {
            self::fail('pdfinfo did not report a page count.');
        }

        return $fields;
    }

    public function testItGeneratesPdfBytesFromTheFreshRecord(): void
    {
        $pdf = $this->generator()->generate('1094290592', $this->sampleRecord());

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(50000, strlen($pdf));
    }

    /**
     * A test print on plain paper (no printer settings changed) showed each
     * card face landing on its own physical sheet, at (0,0) with no margin —
     * because the output was 2 pages sized to the card itself. The output is
     * now one Letter-size (612x792pt / 215.9x279.4mm) sheet with both faces
     * placed inside a safe margin, so a normal print puts both on one page.
     */
    public function testItOutputsBothCardFacesOnASingleLetterSheet(): void
    {
        $pdf = $this->generator()->generate('1094290592', $this->sampleRecord());

        self::assertSame(1, $this->pageCount($pdf));
        [$width, $height] = $this->pageSizePt($pdf);
        self::assertEqualsWithDelta(612.0, $width, 0.5, 'Sheet width should be US Letter.');
        self::assertEqualsWithDelta(792.0, $height, 0.5, 'Sheet height should be US Letter.');
    }

    public function testItRejectsARecordWithoutAnInsuredName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->generator()->generate('1094290592', ['POLIZA' => 'AA005616']);
    }

    public function testItPrintsThePolicyOrderAndDatesFromTheRecord(): void
    {
        $text = $this->extractText($this->generator()->generate('1094290592', $this->sampleRecord()));

        self::assertStringContainsString('AA011951', $text);
        self::assertStringContainsString('1514', $text);
        self::assertStringContainsString('2026-02-01', $text);
        self::assertStringContainsString('2027-02-01', $text);
    }

    public function testItPrintsTheQueriedDocumentAndInsuredName(): void
    {
        $text = $this->extractText($this->generator()->generate('1094290592', $this->sampleRecord()));

        self::assertStringContainsString('1094290592', $text);
        self::assertStringContainsString('VILLAMIZAR ACEVEDO EMILIANO', $text);
    }

    /**
     * Split into two checks rather than one contiguous substring: the
     * two-column layout wraps this name onto a second line, and pdftotext
     * -layout interleaves same-row text from the right column in between —
     * asserting word presence, not exact line reconstruction, is what
     * actually matters here.
     */
    public function testItPrintsTheHolderNameFromTheNewApiField(): void
    {
        $text = $this->extractText($this->generator()->generate('1094290592', $this->sampleRecord()));

        self::assertStringContainsString('GLORIA INES DUARTE', $text);
        self::assertStringContainsString('ACEVEDO', $text);
    }

    public function testItDoesNotPrintTheHolderDocument(): void
    {
        $text = $this->extractText($this->generator()->generate('1094290592', $this->sampleRecord()));

        self::assertStringNotContainsString('37247531', $text);
    }

    public function testItPrintsTheMedicalExpensesCoverageAsColombianCurrency(): void
    {
        $text = $this->extractText($this->generator()->generate('1094290592', $this->sampleRecord()));

        self::assertStringContainsString('$6.000.000', $text);
    }

    public function testItFallsBackToThePlaceholderWhenTheHolderNameIsMissing(): void
    {
        $record = $this->sampleRecord();
        unset($record['NOMBRE_TOMADOR']);

        $text = $this->extractText($this->generator()->generate('1094290592', $record));

        self::assertStringContainsString('PENDIENTE DE CONFIRMACIÓN', $text);
    }

    public function testItFallsBackToThePlaceholderWhenTheMedicalExpensesAmountIsMissing(): void
    {
        $record = $this->sampleRecord();
        unset($record['VAL_GASTOS_MED']);

        $text = $this->extractText($this->generator()->generate('1094290592', $record));

        self::assertStringContainsString('PENDIENTE DE CONFIRMACIÓN', $text);
        self::assertStringNotContainsString('$6.000.000', $text);
    }

    /**
     * The client compared the output against the original two-column mockup
     * and flagged the missing institutional header (company name + La
     * Equidad's own NIT — a static line, unrelated to the insured's data).
     */
    public function testItPrintsTheCompanyHeader(): void
    {
        $text = $this->extractText($this->generator()->generate('1094290592', $this->sampleRecord()));

        self::assertStringContainsString('LA EQUIDAD SEGUROS DE VIDA O.', $text);
        self::assertStringContainsString('NIT. 830.008.686-1', $text);
    }

    /**
     * The Tomador box must be tall enough for a long name to wrap onto a
     * second line instead of being clipped by TCPDF (no auto page break on
     * this fixed-size card). Regression guard for the two-column relayout.
     */
    public function testItAccommodatesALongHolderNameAcrossTwoLinesWithoutClipping(): void
    {
        $record = $this->sampleRecord();
        $record['NOMBRE_TOMADOR'] = 'MARIA FERNANDA DEL SOCORRO RODRIGUEZ VILLAMIZAR';

        $text = $this->extractText($this->generator()->generate('1094290592', $record));

        self::assertStringContainsString('MARIA FERNANDA', $text);
        self::assertStringContainsString('RODRIGUEZ VILLAMIZAR', $text);
    }
}

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
        $pdfinfo = trim((string) shell_exec('command -v pdfinfo'));
        if ($pdfinfo === '') {
            self::markTestSkipped('pdfinfo (poppler-utils) is not available in this environment.');
        }

        $path = tempnam(sys_get_temp_dir(), 'carnet-pdf-') . '.pdf';
        file_put_contents($path, $pdf);

        $output = (string) shell_exec(sprintf('%s %s 2>/dev/null', escapeshellcmd($pdfinfo), escapeshellarg($path)));
        unlink($path);

        if (preg_match('/^Pages:\s*(\d+)/m', $output, $matches) !== 1) {
            self::fail('pdfinfo did not report a page count.');
        }

        return (int) $matches[1];
    }

    public function testItGeneratesPdfBytesFromTheFreshRecord(): void
    {
        $pdf = $this->generator()->generate('1094290592', $this->sampleRecord());

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(50000, strlen($pdf));
    }

    /**
     * The output must keep both sides of the approved artwork: the front
     * cover (page 1 of the template, untouched) followed by the data-filled
     * back (page 2). Downloading only the back was flagged as incomplete
     * after the client reviewed a single-page carnet.
     */
    public function testItOutputsTheCoverPageFollowedByTheDataPage(): void
    {
        $pdf = $this->generator()->generate('1094290592', $this->sampleRecord());

        self::assertSame(2, $this->pageCount($pdf));
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

    public function testItPrintsTheHolderNameFromTheNewApiField(): void
    {
        $text = $this->extractText($this->generator()->generate('1094290592', $this->sampleRecord()));

        self::assertStringContainsString('GLORIA INES DUARTE DE ACEVEDO', $text);
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
}

<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Unit;

use CarnetEquidad\Pdf\CarnetPdfGenerator;
use PHPUnit\Framework\TestCase;

final class CarnetPdfGeneratorTest extends TestCase
{
    public function testItGeneratesPdfBytesFromTheFreshRecord(): void
    {
        $generator = new CarnetPdfGenerator(__DIR__ . '/../../assets/templates/carnet-template.pdf');

        $pdf = $generator->generate('1094290592', [
            'POLIZA' => 'AA005616',
            'ORDEN' => '178',
            'NOMBRE_ASEGURADO' => 'DANIELA POSSO',
            'FECHA_INICIO' => '2025-11-01T00:00:00',
            'FECHA_FIN' => '2026-11-01T00:00:00',
        ]);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(500000, strlen($pdf));
    }

    public function testItRejectsARecordWithoutAnInsuredName(): void
    {
        $generator = new CarnetPdfGenerator(__DIR__ . '/../../assets/templates/carnet-template.pdf');

        $this->expectException(\InvalidArgumentException::class);
        $generator->generate('1094290592', ['POLIZA' => 'AA005616']);
    }
}

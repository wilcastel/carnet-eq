<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Unit;

use CarnetEquidad\Audit\AuditCsvExporter;
use PHPUnit\Framework\TestCase;

final class AuditCsvExporterTest extends TestCase
{
    public function testExportProducesUtf8BomCsvWithTheApprovedColumns(): void
    {
        $csv = (new AuditCsvExporter())->export([
            [
                'created_at' => '2026-09-10 10:00:00',
                'request_id' => 'req-1',
                'ip' => '10.0.0.1',
                'cedula' => '1094290592',
                'cod_pla' => '1821',
                'result' => 'found',
                'api_http' => 200,
                'event_type' => 'query',
                'detail' => 'ok',
            ],
        ]);

        self::assertSame(
            "\xEF\xBB\xBF\"Fecha (UTC)\",Referencia,IP,Cédula,\"Cod. plan\",Resultado,\"HTTP API\",\"Tipo de evento\",Detalle\n"
            . "\"2026-09-10 10:00:00\",req-1,10.0.0.1,1094290592,1821,found,200,query,ok\n",
            $csv,
        );
    }

    public function testExportNeutralizesSpreadsheetFormulaCells(): void
    {
        $csv = (new AuditCsvExporter())->export([
            [
                'created_at' => '=NOW()',
                'request_id' => '+123',
                'ip' => '-10.0.0.1',
                'cedula' => '@formula',
                'cod_pla' => '1821',
                'result' => 'found',
                'api_http' => null,
                'event_type' => 'query',
                'detail' => "safe\ntext",
            ],
        ]);

        self::assertStringContainsString("'=NOW(),'+123,'-10.0.0.1,'@formula,1821,found,,query,\"safe\ntext\"", $csv);
    }

    public function testExportNeverIncludesUnapprovedColumns(): void
    {
        $csv = (new AuditCsvExporter())->export([
            [
                'created_at' => '2026-09-10 10:00:00',
                'request_id' => 'req-1',
                'ip' => '10.0.0.1',
                'cedula' => '1094290592',
                'cod_pla' => '1821',
                'result' => 'found',
                'api_http' => 200,
                'event_type' => 'query',
                'detail' => null,
                'token' => 'must-not-export',
                'nombre' => 'must-not-export',
            ],
        ]);

        self::assertStringNotContainsString('must-not-export', $csv);
    }
}

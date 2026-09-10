<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Unit;

use CarnetEquidad\Api\PolicyOptionMapper;
use PHPUnit\Framework\TestCase;

final class PolicyOptionMapperTest extends TestCase
{
    private PolicyOptionMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new PolicyOptionMapper();
    }

    /** @return array<string, mixed> */
    private static function rawRecord(): array
    {
        return [
            'POLIZA' => '  AAA011951 ',
            'ORDEN' => ' 42 ',
            'CERTIFICADO' => '7',
            'SUCURSAL' => 'BOGOTA',
            'FECHA_INICIO' => '2026-02-01T00:00:00',
            'FECHA_FIN' => '2027-02-01T00:00:00',
            'PRIMA' => 150000,
            'DOCUMENTO_ASEGURADO' => '1094290592',
            'NOMBRE_ASEGURADO' => 'VILLAMIZAR ACEVEDO EMILIANO',
            'DOCUMENTO_BENEFICIARIO' => 'Z0000000Z',
            'NOMBRE_BENEFICIARIO' => '',
            'DETALLE_COBERTURA' => ['x' => 1],
        ];
    }

    public function testMapRecordReturnsOnlyTheTrimmedPublicShape(): void
    {
        $option = $this->mapper->mapRecord(self::rawRecord(), 0);

        self::assertSame(
            ['id', 'poliza', 'orden', 'certificado', 'sucursal', 'vigencia_desde', 'vigencia_hasta'],
            array_keys($option)
        );
        self::assertSame(0, $option['id']);
        self::assertSame('AAA011951', $option['poliza']);
        self::assertSame('42', $option['orden']);
        self::assertSame('7', $option['certificado']);
        self::assertSame('BOGOTA', $option['sucursal']);
    }

    public function testMapRecordDropsBeneficiaryPrimaAndCoverageFields(): void
    {
        $option = $this->mapper->mapRecord(self::rawRecord(), 3);

        foreach (['PRIMA', 'prima', 'DOCUMENTO_BENEFICIARIO', 'NOMBRE_BENEFICIARIO', 'DETALLE_COBERTURA', 'NOMBRE_ASEGURADO'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $option);
        }
    }

    public function testNormalizesIsoDateTimeStringsToYmd(): void
    {
        $option = $this->mapper->mapRecord(self::rawRecord(), 0);

        self::assertSame('2026-02-01', $option['vigencia_desde']);
        self::assertSame('2027-02-01', $option['vigencia_hasta']);
    }

    public function testAcceptsAlreadyCleanDates(): void
    {
        $record = self::rawRecord();
        $record['FECHA_INICIO'] = '2025-12-31';

        $option = $this->mapper->mapRecord($record, 0);

        self::assertSame('2025-12-31', $option['vigencia_desde']);
    }

    public function testMissingOrEmptyOrUnparseableDatesBecomeNull(): void
    {
        $record = self::rawRecord();
        $record['FECHA_INICIO'] = '';
        $record['FECHA_FIN'] = 'not a date';
        unset($record['FECHA_FIN']);

        $option = $this->mapper->mapRecord($record, 0);

        self::assertNull($option['vigencia_desde']);
        self::assertNull($option['vigencia_hasta']);
    }

    public function testMapAllAssignsSequentialIds(): void
    {
        $options = $this->mapper->mapAll([self::rawRecord(), self::rawRecord(), self::rawRecord()]);

        self::assertCount(3, $options);
        self::assertSame([0, 1, 2], array_column($options, 'id'));
    }

    public function testAseguradoNameReadsTrimmedNameFromFirstRecord(): void
    {
        $records = [
            ['NOMBRE_ASEGURADO' => '  VILLAMIZAR ACEVEDO EMILIANO  '],
            ['NOMBRE_ASEGURADO' => 'OTHER PERSON'],
        ];

        self::assertSame('VILLAMIZAR ACEVEDO EMILIANO', $this->mapper->aseguradoName($records));
    }

    public function testAseguradoNameReturnsEmptyStringWhenAbsent(): void
    {
        self::assertSame('', $this->mapper->aseguradoName([[]]));
        self::assertSame('', $this->mapper->aseguradoName([]));
    }
}

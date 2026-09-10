<?php

declare(strict_types=1);

namespace CarnetEquidad\Api;

/**
 * Turns raw /dataAsegurado records into the trimmed, non-personal shape the
 * public REST endpoint is allowed to expose.
 *
 * Deliberately dropped: PRIMA, DOCUMENTO_BENEFICIARIO, NOMBRE_BENEFICIARIO,
 * DETALLE_COBERTURA and the raw asegurado document/name. Dates like
 * "2026-02-01T00:00:00" are normalized to "YYYY-MM-DD".
 *
 * Pure class: no WordPress dependency.
 */
final class PolicyOptionMapper
{
    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<array{id:int, poliza:string, orden:string, certificado:string, sucursal:string, vigencia_desde:?string, vigencia_hasta:?string}>
     */
    public function mapAll(array $records): array
    {
        $options = [];
        foreach (array_values($records) as $index => $record) {
            $options[] = $this->mapRecord($record, $index);
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array{id:int, poliza:string, orden:string, certificado:string, sucursal:string, vigencia_desde:?string, vigencia_hasta:?string}
     */
    public function mapRecord(array $record, int $id): array
    {
        return [
            'id' => $id,
            'poliza' => $this->str($record, 'POLIZA'),
            'orden' => $this->str($record, 'ORDEN'),
            'certificado' => $this->str($record, 'CERTIFICADO'),
            'sucursal' => $this->str($record, 'SUCURSAL'),
            'vigencia_desde' => $this->date($record['FECHA_INICIO'] ?? null),
            'vigencia_hasta' => $this->date($record['FECHA_FIN'] ?? null),
        ];
    }

    /**
     * Trimmed NOMBRE_ASEGURADO of the first record, or an empty string.
     *
     * @param list<array<string, mixed>> $records
     */
    public function aseguradoName(array $records): string
    {
        $first = array_values($records)[0] ?? [];

        return $this->str($first, 'NOMBRE_ASEGURADO');
    }

    /**
     * @param array<string, mixed> $record
     */
    private function str(array $record, string $key): string
    {
        $value = $record[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Normalize an upstream date string to "YYYY-MM-DD"; null when absent or unparseable.
     * Time zone handling is unconfirmed — question #21 in the working document.
     */
    private function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?$/', $value, $m) === 1) {
            return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }

        return null;
    }
}

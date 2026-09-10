<?php

declare(strict_types=1);

namespace CarnetEquidad\Audit;

/**
 * Converts the allow-listed audit fields into a spreadsheet-safe CSV download.
 */
final class AuditCsvExporter
{
    /** @var array<string, string> */
    private const COLUMNS = [
        'created_at' => 'Fecha (UTC)',
        'request_id' => 'Referencia',
        'ip' => 'IP',
        'cedula' => 'Cédula',
        'cod_pla' => 'Cod. plan',
        'result' => 'Resultado',
        'api_http' => 'HTTP API',
        'event_type' => 'Tipo de evento',
        'detail' => 'Detalle',
    ];

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function export(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create CSV stream.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_values(self::COLUMNS), ',', '"', '\\', "\n");

        foreach ($rows as $row) {
            $cells = [];
            foreach (array_keys(self::COLUMNS) as $column) {
                $cells[] = $this->safeCell($row[$column] ?? '');
            }

            fputcsv($stream, $cells, ',', '"', '\\', "\n");
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new \RuntimeException('Unable to read CSV stream.');
        }

        return $csv;
    }

    private function safeCell(mixed $value): string
    {
        $cell = (string) $value;

        return preg_match('/^\s*[=+\-@]/', $cell) === 1 ? "'" . $cell : $cell;
    }
}

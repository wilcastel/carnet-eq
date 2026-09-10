<?php

declare(strict_types=1);

namespace CarnetEquidad\Admin;

use CarnetEquidad\Audit\WpdbAuditStore;

/**
 * Read-only audit viewer under Tools -> "Carnet — Auditoría".
 *
 * §6 of the working document requires "acceso restringido" to the audit trail:
 * the page is gated behind the {@code manage_options} capability and shows only
 * the most recent rows. No filters/pagination this increment. Not unit-tested
 * (WordPress admin + $wpdb only).
 */
final class AuditPage
{
    private const MENU_SLUG = 'carnet-equidad-auditoria';
    private const CAPABILITY = 'manage_options';
    private const ROW_LIMIT = 100;

    public function register(): void
    {
        \add_management_page(
            \esc_html__('Carnet — Auditoría', 'carnet-equidad'),
            \esc_html__('Carnet — Auditoría', 'carnet-equidad'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render'],
        );
    }

    public function render(): void
    {
        if (! \current_user_can(self::CAPABILITY)) {
            return;
        }

        $rows = $this->recentRows();

        echo '<div class="wrap">';
        echo '<h1>' . \esc_html__('Carnet — Auditoría', 'carnet-equidad') . '</h1>';
        echo '<p>' . \esc_html(
            \sprintf(
                /* translators: %d: number of rows shown. */
                \__('Últimos %d eventos registrados (orden descendente por fecha).', 'carnet-equidad'),
                self::ROW_LIMIT
            )
        ) . '</p>';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        foreach (
            [
                \__('Fecha (UTC)', 'carnet-equidad'),
                \__('Referencia', 'carnet-equidad'),
                \__('IP', 'carnet-equidad'),
                \__('Cédula', 'carnet-equidad'),
                \__('Cod. plan', 'carnet-equidad'),
                \__('Resultado', 'carnet-equidad'),
                \__('HTTP API', 'carnet-equidad'),
                \__('Tipo de evento', 'carnet-equidad'),
                \__('Detalle', 'carnet-equidad'),
            ] as $heading
        ) {
            echo '<th>' . \esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="9">' . \esc_html__('No hay eventos registrados.', 'carnet-equidad') . '</td></tr>';
        }

        foreach ($rows as $row) {
            echo '<tr>';
            foreach (
                ['created_at', 'request_id', 'ip', 'cedula', 'cod_pla', 'result', 'api_http', 'event_type', 'detail'] as $column
            ) {
                echo '<td>' . \esc_html((string) ($row[$column] ?? '')) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentRows(): array
    {
        global $wpdb;

        $table = WpdbAuditStore::tableName();

        /** @var list<array<string, mixed>>|null $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, request_id, ip, cedula, cod_pla, result, api_http, event_type, detail
                 FROM {$table}
                 ORDER BY created_at DESC
                 LIMIT %d",
                self::ROW_LIMIT
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }
}

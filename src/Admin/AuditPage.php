<?php

declare(strict_types=1);

namespace CarnetEquidad\Admin;

use CarnetEquidad\Audit\AuditCsvExporter;
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
    private const EXPORT_ACTION = 'carnet_equidad_export_audit';

    /** Register the audit viewer in the WordPress Tools menu. */
    public function registerMenu(): void
    {
        \add_management_page(
            \esc_html__('Carnet — Auditoría', 'carnet-equidad'),
            \esc_html__('Carnet — Auditoría', 'carnet-equidad'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render'],
        );
    }

    /**
     * Register the CSV action during admin initialization.
     *
     * admin-post.php does not execute admin_menu, so registering this callback
     * alongside the menu leaves the export endpoint unavailable.
     */
    public function registerExport(): void
    {
        \add_action('admin_post_' . self::EXPORT_ACTION, [$this, 'export']);
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

        echo '<form method="get" action="' . \esc_url(\admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . \esc_attr(self::EXPORT_ACTION) . '">';
        \wp_nonce_field(self::EXPORT_ACTION);
        echo '<label for="carnet-audit-from">' . \esc_html__('Desde (UTC)', 'carnet-equidad') . '</label> ';
        echo '<input id="carnet-audit-from" type="date" name="from"> ';
        echo '<label for="carnet-audit-to">' . \esc_html__('Hasta (UTC)', 'carnet-equidad') . '</label> ';
        echo '<input id="carnet-audit-to" type="date" name="to"> ';
        echo '<button type="submit" class="button">' . \esc_html__('Descargar CSV', 'carnet-equidad') . '</button>';
        echo '</form>';

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

    /** Download all retained audit events, optionally constrained to an inclusive UTC date range. */
    public function export(): void
    {
        if (! \current_user_can(self::CAPABILITY)) {
            \wp_die(\esc_html__('No tienes permiso para descargar la auditoría.', 'carnet-equidad'), '', ['response' => 403]);
        }

        \check_admin_referer(self::EXPORT_ACTION);

        $from = $this->dateParameter('from');
        $to = $this->dateParameter('to');
        $csv = (new AuditCsvExporter())->export($this->exportRows($from, $to));

        \nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="carnet-auditoria-' . gmdate('Y-m-d') . '.csv"');
        echo $csv;
        exit;
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

    /**
     * @return list<array<string, mixed>>
     */
    private function exportRows(?string $from, ?string $to): array
    {
        global $wpdb;

        $table = WpdbAuditStore::tableName();
        $where = [];
        $parameters = [];

        if ($from !== null) {
            $where[] = 'created_at >= %s';
            $parameters[] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $where[] = 'created_at <= %s';
            $parameters[] = $to . ' 23:59:59';
        }

        $query = 'SELECT created_at, request_id, ip, cedula, cod_pla, result, api_http, event_type, detail'
            . " FROM {$table}"
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY created_at DESC';

        if ($parameters !== []) {
            $query = $wpdb->prepare($query, ...$parameters);
        }

        /** @var list<array<string, mixed>>|null $rows */
        $rows = $wpdb->get_results($query, ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    private function dateParameter(string $name): ?string
    {
        $value = isset($_GET[$name]) ? (string) $_GET[$name] : '';
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        $isValid = $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
        if (! $isValid || $date->format('Y-m-d') !== $value) {
            \wp_die(\esc_html__('El rango de fechas no es válido.', 'carnet-equidad'), '', ['response' => 400]);
        }

        return $value;
    }
}

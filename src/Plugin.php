<?php

declare(strict_types=1);

namespace CarnetEquidad;

use CarnetEquidad\Admin\AuditPage;
use CarnetEquidad\Api\ApiDataCarnetClient;
use CarnetEquidad\Audit\AuditLogger;
use CarnetEquidad\Audit\AuditSchema;
use CarnetEquidad\Audit\WpdbAuditStore;
use CarnetEquidad\Frontend\ShortcodeRenderer;
use CarnetEquidad\Download\CarnetDownloadController;
use CarnetEquidad\Rest\ConsultaController;

/**
 * Plugin composition root. Wires WordPress hooks to plugin services.
 */
final class Plugin
{
    /** Daily WP-Cron hook that trims the audit table. */
    public const PRUNE_AUDIT_HOOK = 'carnet_equidad_prune_audit';

    /** Default audit retention, in days (overridable via the filter of the same name). */
    public const DEFAULT_AUDIT_RETENTION_DAYS = 180;

    /** Schema revision. Bump when {@see AuditSchema} changes so live sites migrate. */
    public const DB_VERSION = '1';

    /** Option holding the schema revision applied to this site. */
    public const DB_VERSION_OPTION = 'carnet_equidad_db_version';

    public function __construct(
        private readonly string $pluginFile,
    ) {
    }

    public function boot(): void
    {
        $auditPage = new AuditPage();
        $downloadController = new CarnetDownloadController(\plugin_dir_path($this->pluginFile) . 'assets/templates/carnet-template.pdf');

        \add_action('init', [new ShortcodeRenderer($this->pluginFile), 'register']);
        \add_action('rest_api_init', [new ConsultaController(), 'register']);
        \add_action('admin_menu', [$auditPage, 'registerMenu']);
        \add_action('admin_init', [$auditPage, 'registerExport']);
        \add_action('init', [$downloadController, 'register']);
        \add_action(self::PRUNE_AUDIT_HOOK, [self::class, 'pruneAudit']);
        // Self-heal the schema on sites that were already active when a new
        // revision shipped (the activation hook only runs on (re)activation).
        \add_action('admin_init', [self::class, 'maybeUpgrade']);
    }

    /**
     * Activation hook: provision the audit table and schedule the daily prune.
     */
    public static function activate(): void
    {
        AuditSchema::create();
        \update_option(self::DB_VERSION_OPTION, self::DB_VERSION);

        if (! \wp_next_scheduled(self::PRUNE_AUDIT_HOOK)) {
            \wp_schedule_event(\time(), 'daily', self::PRUNE_AUDIT_HOOK);
        }
    }

    /**
     * Run pending schema migrations when the stored revision is behind.
     * {@see dbDelta()} is idempotent, so this is safe on every admin request.
     */
    public static function maybeUpgrade(): void
    {
        if (\get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        AuditSchema::create();
        \update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Deactivation hook. Drop the cached upstream token and unschedule the prune
     * cron. The audit table itself is kept (removed only on uninstall).
     */
    public static function deactivate(): void
    {
        \delete_transient(ApiDataCarnetClient::TOKEN_CACHE_KEY);
        \wp_clear_scheduled_hook(self::PRUNE_AUDIT_HOOK);
    }

    /**
     * WP-Cron callback: delete audit rows older than the configured retention.
     */
    public static function pruneAudit(): void
    {
        $retentionDays = (int) \apply_filters(
            'carnet_equidad_audit_retention_days',
            self::DEFAULT_AUDIT_RETENTION_DAYS
        );

        (new AuditLogger(new WpdbAuditStore()))->prune($retentionDays);
    }
}

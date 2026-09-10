<?php

declare(strict_types=1);

namespace {
    /** @var list<array{hook: string, callback: callable}> */
    $GLOBALS['carnet_equidad_registered_actions'] = [];

    function add_action(string $hook, callable $callback): void
    {
        $GLOBALS['carnet_equidad_registered_actions'][] = [
            'hook' => $hook,
            'callback' => $callback,
        ];
    }

    function plugin_dir_url(string $file): string
    {
        return 'https://example.test/wp-content/plugins/carnet-equidad/';
    }

    function plugin_dir_path(string $file): string
    {
        return '/plugin/';
    }
}

namespace CarnetEquidad\Tests\Unit {
    use CarnetEquidad\Plugin;
    use PHPUnit\Framework\TestCase;

    final class PluginTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['carnet_equidad_registered_actions'] = [];
        }

        public function testBootRegistersAuditExportOnAdminInitAndMenuOnAdminMenu(): void
        {
            (new Plugin('/plugin/carnet-equidad.php'))->boot();

            $auditHooks = array_values(array_filter(
                $GLOBALS['carnet_equidad_registered_actions'],
                static fn (array $action): bool => $action['callback'][0] instanceof \CarnetEquidad\Admin\AuditPage,
            ));

            self::assertSame(['admin_menu', 'admin_init'], array_column($auditHooks, 'hook'));
            self::assertSame(['registerMenu', 'registerExport'], array_map(
                static fn (array $action): string => $action['callback'][1],
                $auditHooks,
            ));
        }
    }
}

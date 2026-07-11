<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests;

use Illuminate\Foundation\Application;
use Vaulter\Tests\Fixtures\Panels\TeamStoragePanel;

/**
 * Registers a second storage panel BEFORE the application boots.
 *
 * Panels are config, and since P4.4 the PanelRegistry singleton is RESOLVED at boot
 * (VaulterServiceProvider::packageBooted) so a broken panel fails loud there rather than on
 * the first live request that happens to touch it (F-P3.2-01). The registry is therefore
 * built from the panel list as it stands AT BOOT: a later `config()->set('vaulter.panels')`
 * writes to a config the registry has already read and is not picked up — which is what a
 * production install looks like anyway (config is loaded, and usually cached, before boot).
 *
 * `defineEnvironment()` runs before Testbench bootstraps the providers, so setting the panel
 * here lands in the same window a host's config file does. A host that must add a panel after
 * boot has the runtime seam for it: `app(PanelRegistry::class)->registerProvider(...)`.
 *
 * See UPGRADE.md ("Panels are read at boot, not on first use").
 */
abstract class PanelTestCase extends TestCase
{
    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.panels', [TeamStoragePanel::class]);
    }
}

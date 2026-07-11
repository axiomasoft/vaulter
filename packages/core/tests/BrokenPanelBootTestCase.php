<?php

declare(strict_types=1);

namespace Vaulter\Tests;

use Illuminate\Foundation\Application;
use Throwable;
use Vaulter\Tests\Fixtures\Panels\BrokenModelStoragePanel;

/**
 * Configures a KNOWN-BROKEN panel (a non-existent model FQCN) BEFORE the
 * application boots, so PanelRegistry's boot-time validation (F-P3.2-01) fires
 * during framework bootstrap itself, not lazily on first use. `defineEnvironment()`
 * runs before `Illuminate\Foundation\Bootstrap\BootProviders` inside Testbench's
 * `createApplication()` (Orchestra\Testbench\Concerns\CreatesApplication), so
 * setting `vaulter.panels` here lands before `VaulterServiceProvider::
 * packageBooted()` runs — exactly the boot-time window the test targets.
 *
 * setUp() wraps the whole boot in a try/catch: BEFORE the F-P3.2-01 fix, boot
 * completed cleanly (the broken panel was never touched) and $bootException stayed
 * null; AFTER the fix, boot itself throws InvalidPanelException, captured here so
 * the test method can assert on it instead of the test erroring out.
 */
class BrokenPanelBootTestCase extends TestCase
{
    public ?Throwable $bootException = null;

    #[\Override]
    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (Throwable $e) {
            $this->bootException = $e;
        }
    }

    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.panels', [BrokenModelStoragePanel::class]);
    }
}

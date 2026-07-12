<?php

declare(strict_types=1);

namespace Vaulter\Tests\P22Repro;

use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/** @var array<int, string> */
$GLOBALS['p22_trace'] = [];

class TraceProbe extends ServiceProvider
{
    private string $label;

    public function __construct($app, string $label = 'probe')
    {
        parent::__construct($app);
        $this->label = $label;
    }

    public function register(): void
    {
        $models = $this->app['config']->get('vaulter.models', []);
        $GLOBALS['p22_trace'][] = $this->label.' = '.json_encode(array_keys(is_array($models) ? $models : []));
    }
}

final class ProbeAfterCore extends TraceProbe
{
    public function __construct($app)
    {
        parent::__construct($app, 'after-core-before-drive');
    }
}

final class ProbeAfterDrive extends TraceProbe
{
    public function __construct($app)
    {
        parent::__construct($app, 'after-drive-before-documents');
    }
}

final class ProbeFinal extends TraceProbe
{
    public function __construct($app)
    {
        parent::__construct($app, 'final-after-all');
    }
}

abstract class TraceOrderBase extends OrchestraTestCase
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
            ProbeAfterCore::class,
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
            ProbeAfterDrive::class,
            ProbeFinal::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $published = require dirname(__DIR__, 5).'/packages/core/config/vaulter.php';
        $app['config']->set('vaulter', $published);
    }
}

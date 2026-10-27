<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Contracts\Security\ProductionInvariant;
use Vaulter\CoreModule;
use Vaulter\Exceptions\Configuration\InsecureProductionConfiguration;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\ExtensionRegistry;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Security\ProductionInvariants;
use Vaulter\Tests\TestCase;

it('guards only canonical long-lived command names', function (string $command, bool $guarded): void {
    expect(ProductionInvariants::guards($command))->toBe($guarded);
})->with([
    ['queue:work', true],
    ['queue:listen', true],
    ['horizon', true],
    ['octane:start', true],
    ['vaulter:relay', true],
    ['schedule:work', true],
    ['schedule:run', true],
    ['vaulter:relay:replay', true],
    ['vaulter:webhooks:redeliver', true],
    ['vaulter:outbox:prune', false],
    ['vaulter:relay:other', false],
    ['package:discover', false],
    ['config:cache', false],
    ['migrate', false],
    ['vaulter:doctor', false],
]);

it('does not guard a missing command name', function (): void {
    expect(ProductionInvariants::guards(null))->toBeFalse();
});

function tenancyInvariants(bool $enabled, array $acceptedRisks): ProductionInvariants
{
    $container = new Container;
    $container->instance(TenancyConfig::class, new TenancyConfig(new Repository(['vaulter' => ['tenancy' => ['enabled' => $enabled, 'resolver' => 'Missing\\Resolver']]])));
    $compiled = (new ModuleCompiler)->compile([new CoreModule], []);
    assert($compiled instanceof CompiledModules);

    return new ProductionInvariants(new ExtensionRegistry($compiled, $container), $acceptedRisks);
}

it('is satisfied when tenancy is disabled', function (): void {
    expect(tenancyInvariants(false, [])->evaluate())->toBe(['violations' => [], 'accepted' => [], 'notices' => []]);
    tenancyInvariants(false, [])->assertSecure();
});

it('fails with one secret-free aggregated exception for an unusable tenancy resolver', function (): void {
    $result = tenancyInvariants(true, [])->evaluate();

    expect(array_keys($result['violations']))->toBe(['core.tenancy'])
        ->and(fn () => tenancyInvariants(true, [])->assertSecure())->toThrow(InsecureProductionConfiguration::class, 'core.tenancy');
});

it('accepts a risk only with a nonempty reason', function (): void {
    $accepted = tenancyInvariants(true, ['core.tenancy' => 'single tenant behind a gateway'])->evaluate();
    $blank = tenancyInvariants(true, ['core.tenancy' => '  '])->evaluate();

    expect($accepted['violations'])->toBe([])->and(array_keys($accepted['accepted']))->toBe(['core.tenancy'])
        ->and(array_keys($blank['violations']))->toBe(['core.tenancy'])->and($blank['notices'][0])->toContain('empty reason');
    tenancyInvariants(true, ['core.tenancy' => 'single tenant behind a gateway'])->assertSecure();
});

it('notes an accepted risk that names no active invariant', function (): void {
    expect(tenancyInvariants(false, ['drive.nothing' => 'stale'])->evaluate()['notices'])->toHaveCount(1);
});

/** Real application boot with fake command handlers: no queue worker, Octane server or relay ever runs. */
class ProductionBootCase extends TestCase
{
    /** @var array{env: string, violate: bool, accepted: array<string, string>} */
    public static array $settings = ['env' => 'production', 'violate' => false, 'accepted' => []]; // the fixture executor is a real ProductionInvariants over core.tenancy

    public static int $handled = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // Testbench pins the environment to `testing` after this hook; the kernel reads it only when a guard runs.
        $app->booting(static function () use ($app): void {
            $app['env'] = ProductionBootCase::$settings['env'];
            // The core registrar rebinds the executor while registering, so the fixture executor is set once registration is over.
            $app->instance(ProductionInvariants::class, tenancyInvariants(ProductionBootCase::$settings['violate'], ProductionBootCase::$settings['accepted']));
        });
        $app['config']->set('app.env', self::$settings['env']);
        $app['config']->set('vaulter.security.accepted_risks', self::$settings['accepted']);
    }

    public static function fake(string $name, array $aliases = []): void
    {
        Artisan::registerCommand(new class($name, $aliases) extends Command
        {
            public function __construct(string $name, array $aliases)
            {
                $this->signature = $name;
                parent::__construct();
                $this->setAliases($aliases);
            }

            public function handle(): int
            {
                ProductionBootCase::$handled++;

                return 0;
            }
        });
    }
}

uses(ProductionBootCase::class);

beforeEach(function (): void {
    ProductionBootCase::$settings = ['env' => 'production', 'violate' => false, 'accepted' => []];
    ProductionBootCase::$handled = 0;
});

function bootWith(array $settings): void
{
    ProductionBootCase::$settings = $settings + ProductionBootCase::$settings;
    test()->refreshApplication();
}

/** Runs a raw command line through the Symfony parser, as `php artisan <line>` does. */
function artisanLine(string $line): int
{
    $artisan = app()->make(Kernel::class);
    $artisan->bootstrap();
    $application = (new ReflectionMethod($artisan, 'getArtisan'))->invoke($artisan);
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);

    return $application->run(new StringInput($line), new NullOutput);
}

it('refuses a guarded command with options before it, an abbreviation or an alias, before handle()', function (string $line): void {
    bootWith(['violate' => true]);
    ProductionBootCase::fake('queue:work', ['qw:run']);

    expect(app()->environment('production'))->toBeTrue()
        ->and(fn () => artisanLine($line))->toThrow(InsecureProductionConfiguration::class, 'core.tenancy')
        ->and(ProductionBootCase::$handled)->toBe(0);
})->with([
    'canonical' => ['queue:work'],
    'no-interaction' => ['--no-interaction queue:work'],
    'env option' => ['--env=production queue:work'],
    'short no-interaction' => ['-n queue:work'],
    'abbreviation' => ['queue:wor'],
    'alias' => ['qw:run'],
]);

it('refuses a guarded command called programmatically after boot', function (): void {
    bootWith(['violate' => true]);
    ProductionBootCase::fake('queue:work');

    expect(app()->environment('production'))->toBeTrue()
        ->and(fn () => Artisan::call('queue:work'))->toThrow(InsecureProductionConfiguration::class)
        ->and(ProductionBootCase::$handled)->toBe(0);
});

it('lets a non-guarded or build command run without the production secrets', function (): void {
    bootWith(['violate' => true]);
    ProductionBootCase::fake('vaulter:doctor');
    ProductionBootCase::fake('config:cache');

    Artisan::call('vaulter:doctor');
    Artisan::call('config:cache');

    expect(ProductionBootCase::$handled)->toBe(2);
});

it('refuses every declared guarded command name before its fake handler', function (string $command): void {
    bootWith(['violate' => true]);
    ProductionBootCase::fake($command);

    expect(fn () => Artisan::call($command))->toThrow(InsecureProductionConfiguration::class, 'core.tenancy')
        ->and(ProductionBootCase::$handled)->toBe(0);
})->with(ProductionInvariants::GUARDED_COMMANDS);

it('runs every declared build or doctor exemption before its fake handler under a violation', function (string $command): void {
    bootWith(['violate' => true]);
    ProductionBootCase::fake($command);

    expect(Artisan::call($command))->toBe(0)
        ->and(ProductionBootCase::$handled)->toBe(1);
})->with(['package:discover', 'config:cache', 'vaulter:doctor', 'vaulter:outbox:prune']);

it('lets a guarded command run for an accepted risk with a reason', function (array $settings): void {
    bootWith(['violate' => true, 'accepted' => $settings['accepted'] ?? []]);
    ProductionBootCase::fake('queue:work');

    Artisan::call('queue:work');

    expect(ProductionBootCase::$handled)->toBe(1);
})->with([
    'accepted risk' => [['accepted' => ['core.tenancy' => 'single tenant behind a gateway']]],
]);

it('boots HTTP in production only without an unaccepted violation', function (): void {
    $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
    try {
        expect(fn () => bootWith(['violate' => true]))->toThrow(InsecureProductionConfiguration::class, 'core.tenancy');
        bootWith(['violate' => true, 'accepted' => ['core.tenancy' => 'single tenant behind a gateway']]);
        bootWith(['violate' => false]);
    } finally {
        unset($_ENV['APP_RUNNING_IN_CONSOLE']);
    }
    expect(true)->toBeTrue();
});

it('logs an accepted risk once per boot with the safe violation and never the free-form reason', function (): void {
    Log::spy();
    $invariants = tenancyInvariants(true, ['core.tenancy' => 'SECRET-REASON-TEXT']);

    $invariants->assertSecure();
    $invariants->assertSecure();

    Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => str_contains($message, 'core.tenancy') && !str_contains($message, 'SECRET-REASON-TEXT'));
});

it('diagnoses a contribution key that differs from the invariant id, whatever the accepted risks', function (): void {
    $wrong = new class implements ProductionInvariant
    {
        public function id(): string
        {
            return 'other.id';
        }

        public function violation(): ?string
        {
            return 'bad';
        }
    };

    ProductionInvariants::assertKeysMatchIds(['other.id' => $wrong]);

    expect(fn () => ProductionInvariants::assertKeysMatchIds(['fixture.key' => $wrong]))->toThrow(InvalidConfigurationException::class, 'fixture.key');
});

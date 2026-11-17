<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use ReflectionFunction;
use Throwable;
use Vaulter\Configuration\VaulterOptions;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;

/**
 * Repeats the validation the packages run at boot and lists every failure instead of the first.
 * A package adds its own validation by tagging a closure binding `vaulter.config_validators`.
 */
#[Internal]
final readonly class ConfigValidCheck implements DoctorCheck
{
    public const string TAG = 'vaulter.config_validators';

    public function __construct(private Application $app, private Repository $config) {}

    #[Override]
    public function key(): string
    {
        return 'core.config.valid';
    }

    #[Override]
    public function title(): string
    {
        return 'Configuration';
    }

    #[Override]
    public function run(DoctorContext $context): array
    {
        $failures = [];
        $steps = ['core' => function (): void {
            $raw = $this->config->get('vaulter', []);
            $options = VaulterOptions::fromArray(is_array($raw) ? $raw : ['__invalid_root' => $raw], 'vaulter');

            if ($options->tenancy->enabled) {
                $options->tenancy->requireResolver();
                if (!self::isTenantResolver($this->app->make(TenantResolver::class))) {
                    throw new TenancyMisconfiguredException('TenantResolver binding returned an invalid object.');
                }
            }
        }];

        foreach ($this->app->tagged(self::TAG) as $step) {
            if ($step instanceof Closure) {
                $name = new ReflectionFunction($step)->getClosureScopeClass()?->getName() ?? 'package';
                // Two validators of one class must both run: later ones get a numbered name.
                $unique = $name;
                $n = 2;
                while (isset($steps[$unique])) {
                    $unique = $name.'#'.$n;
                    $n++;
                }
                $steps[$unique] = $step;
            }
        }

        foreach ($steps as $name => $step) {
            try {
                $step();
            } catch (Throwable $exception) {
                // Only the packages' own configuration errors are quoted: they name keys and classes. A
                // resolver or driver that failed while being built may put a DSN or token in its message.
                $failures[$name] = ($exception instanceof InvalidConfiguration
                    || $exception instanceof InvalidConfigurationException
                    || $exception instanceof TenancyMisconfiguredException)
                    ? $exception->getMessage()
                    : $exception::class;
            }
        }

        if ($failures === []) {
            return [DoctorFinding::ok(
                code: 'core.config.valid',
                message: 'The configuration of every installed package is valid.',
                evidence: ['validators' => count($steps)],
            )];
        }

        return array_map(
            static fn (string $name, string $message): DoctorFinding => DoctorFinding::error(
                code: 'core.config.invalid',
                message: $message,
                remediation: 'fix the key named in the message',
                evidence: ['validator' => $name],
            ),
            array_keys($failures),
            array_values($failures),
        );
    }

    private static function isTenantResolver(mixed $resolved): bool
    {
        return $resolved instanceof TenantResolver;
    }
}

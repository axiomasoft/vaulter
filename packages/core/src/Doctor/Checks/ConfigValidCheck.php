<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use ReflectionFunction;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/**
 * Repeats the validation the packages run at boot and lists every failure instead of the first.
 * A package adds its own validation by tagging a closure binding `vaulter.config_validators`.
 */
#[Internal]
final readonly class ConfigValidCheck implements DoctorCheck
{
    public const string TAG = 'vaulter.config_validators';

    public function __construct(private Application $app, private ConfigValidator $validator, private VaulterConfig $config) {}

    public function key(): string
    {
        return 'core.config.valid';
    }

    public function title(): string
    {
        return 'Configuration';
    }

    public function run(DoctorContext $context): array
    {
        $failures = [];
        $steps = ['core' => function (): void {
            $this->validator->validate($this->config, $this->app);
        }];
        foreach ($this->app->tagged(self::TAG) as $step) {
            if ($step instanceof Closure) {
                $name = (new ReflectionFunction($step))->getClosureScopeClass()?->getName() ?? 'package';
                // Two validators of one class must both run: later ones get a numbered name.
                for ($n = 2, $unique = $name; isset($steps[$unique]); $n++) {
                    $unique = $name.'#'.$n;
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
                $failures[$name] = $exception instanceof InvalidConfigurationException ? $exception->getMessage() : $exception::class;
            }
        }

        if ($failures === []) {
            return [DoctorFinding::ok(code: 'core.config.valid', message: 'The configuration of every installed package is valid.', evidence: ['validators' => count($steps)])];
        }

        return array_map(
            static fn (string $name, string $message): DoctorFinding => DoctorFinding::error(code: 'core.config.invalid', message: $message, hint: 'fix the key named in the message', evidence: ['validator' => $name]),
            array_keys($failures),
            array_values($failures),
        );
    }
}

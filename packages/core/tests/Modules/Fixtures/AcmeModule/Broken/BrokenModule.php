<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule\Broken;

use Illuminate\Support\Facades\DB;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/** A manifest with one flaw a test picks; the contract tests of the module kit must refuse each of them. */
final class BrokenModule implements VaulterModule
{
    public const string QUERIES = 'queries';

    public const string RESOLVES = 'resolves';

    public const string FLAKY = 'flaky';

    public const string BINDS = 'binds';

    public const string LISTENS = 'listens';

    public const string CONFIGURES = 'configures';

    public const string UNKNOWN_DEPENDENCY = 'unknown-dependency';

    public const string BAD_VERSION = 'bad-version';

    public const string BAD_CONSTRAINT = 'bad-constraint';

    private static int $registered = 0;

    public function __construct(private readonly string $flaw) {}

    public function id(): ModuleId
    {
        return new ModuleId('broken');
    }

    public function version(): string
    {
        return $this->flaw === self::BAD_VERSION ? '1' : '1.0';
    }

    public function activation(): Activation
    {
        return Activation::OptIn;
    }

    public function requires(): array
    {
        return match ($this->flaw) {
            self::UNKNOWN_DEPENDENCY => ['nowhere' => '^1.0'],
            self::BAD_CONSTRAINT => ['core' => '>=1.0'],
            default => ['core' => '^1.0'],
        };
    }

    public function register(ModuleBuilder $module): void
    {
        match ($this->flaw) {
            self::QUERIES => DB::select('select 1'),
            self::RESOLVES => app()->make(\stdClass::class),
            self::BINDS => app()->singleton('broken.service', static fn (): int => 1),
            self::LISTENS => app('events')->listen('broken.event', static fn (): null => null),
            self::CONFIGURES => config(['broken.key' => 1]),
            self::FLAKY => $module->commands(...(self::$registered++ % 2 === 0 ? ['A'] : ['B'])),
            default => null,
        };
    }
}

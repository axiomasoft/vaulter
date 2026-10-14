<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

use Closure;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/**
 * A manifest assembled by a test: identity, policy, requirements and the declarations to make.
 * The closure belongs to the fixture only; the builder itself never takes one.
 */
final class FixtureModule implements VaulterModule
{
    public ?ModuleBuilder $builder = null;

    /**
     * @param array<string, string>               $requires
     * @param (Closure(ModuleBuilder): void)|null $declare
     */
    public function __construct(
        private readonly string $id,
        private readonly Activation $activation = Activation::Auto,
        private readonly array $requires = [],
        private readonly ?Closure $declare = null,
        private readonly string $version = '1.0',
    ) {}

    public function id(): ModuleId
    {
        return new ModuleId($this->id);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function activation(): Activation
    {
        return $this->activation;
    }

    public function requires(): array
    {
        return $this->requires;
    }

    public function register(ModuleBuilder $module): void
    {
        $this->builder = $module;
        if ($this->declare !== null) {
            ($this->declare)($module);
        }
    }
}

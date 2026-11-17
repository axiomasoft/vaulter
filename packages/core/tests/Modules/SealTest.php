<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Exceptions\Registry\RegistryFrozenException;
use Vaulter\Kernel;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Capabilities;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\ExtensionRegistry;
use Vaulter\Modules\InvalidModuleGraph;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\ModuleGraphException;
use Vaulter\Tests\Modules\Fixtures\FixtureModule;
use Vaulter\Tests\Modules\Fixtures\Greeter;
use Vaulter\Tests\Modules\Fixtures\HostModule;
use Vaulter\Tests\Modules\Fixtures\PlainGreeter;

// After the seal nothing can be declared, offered or re-sealed; an invalid graph is sealed as such and
// every read of capabilities or slots reports it.

it('freezes every builder once compiled, valid or not', function (bool $valid): void {
    $module = new FixtureModule('host', Activation::Auto);
    $modules = $valid ? [$module] : [$module, new FixtureModule('broken', requires: ['missing' => '^1.0'])];
    $result = (new ModuleCompiler)->compile($modules, []);

    expect($result)->toBeInstanceOf($valid ? CompiledModules::class : InvalidModuleGraph::class)
        ->and(fn (): ?ModuleBuilder => $module->builder?->provides(Greeter::class, PlainGreeter::class))->toThrow(RegistryFrozenException::class)
        ->and(fn (): ?ModuleBuilder => $module->builder?->defineCapability(Greeter::class, Cardinality::Many))->toThrow(RegistryFrozenException::class);
})->with(['valid graph' => [true], 'invalid graph' => [false]]);

it('refuses an offer after the catalogue is sealed and keeps one offer per class', function (): void {
    $catalog = new ModuleCatalog;
    $catalog->offer(HostModule::class);
    $catalog->offer(HostModule::class);
    $catalog->seal();

    expect($catalog->offered())->toBe([HostModule::class])
        ->and(fn () => $catalog->offer(HostModule::class))->toThrow(RegistryFrozenException::class);
});

it('seals the kernel once', function (): void {
    $kernel = new Kernel(new Container);
    $kernel->seal((new ModuleCompiler)->compile([], []));

    expect($kernel->isSealed())->toBeTrue()
        ->and($kernel->isValid())->toBeTrue()
        ->and(fn () => $kernel->seal(new InvalidModuleGraph(['late'])))->toThrow(RegistryFrozenException::class);
});

it('reports an invalid graph on every read', function (): void {
    $kernel = new Kernel(new Container);
    $kernel->seal(new InvalidModuleGraph(['module id "host" is declared twice']));

    expect($kernel->isValid())->toBeFalse()
        ->and($kernel->reasons())->toBe(['module id "host" is declared twice'])
        ->and($kernel->modules())->toBe([])
        ->and($kernel->fingerprint())->toBeNull()
        ->and(fn () => $kernel->assertValid())->toThrow(ModuleGraphException::class, 'module id "host" is declared twice')
        ->and(fn (): Capabilities => $kernel->capabilities())->toThrow(ModuleGraphException::class)
        ->and(fn (): ExtensionRegistry => $kernel->extensions())->toThrow(ModuleGraphException::class);
});

it('refuses reads before the graph is compiled', function (): void {
    $kernel = new Kernel(new Container);

    expect($kernel->isSealed())->toBeFalse()
        ->and(fn (): Capabilities => $kernel->capabilities())->toThrow(ModuleGraphException::class, 'not compiled yet');
});

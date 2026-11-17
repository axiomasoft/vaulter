<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\ExtensionRegistry;
use Vaulter\Modules\MissingExtensionException;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\SlotContribution;
use Vaulter\Tests\Modules\Fixtures\FirstStep;
use Vaulter\Tests\Modules\Fixtures\Greeter;
use Vaulter\Tests\Modules\Fixtures\HostModule;
use Vaulter\Tests\Modules\Fixtures\OptionalModule;
use Vaulter\Tests\Modules\Fixtures\PluginModule;
use Vaulter\Tests\Modules\Fixtures\Step;
use Vaulter\Tests\Modules\Fixtures\ThirdStep;

// The slot registry lists the contributions of active modules in their sealed order and serves a
// handler by its key; nothing else is resolvable.

function extensionRegistry(array $overrides = []): ExtensionRegistry
{
    $compiled = (new ModuleCompiler)->compile([new HostModule, new PluginModule, new OptionalModule], $overrides);
    assert($compiled instanceof CompiledModules);

    return new ExtensionRegistry($compiled, new Container);
}

it('lists the active contributions of a slot in order', function (): void {
    $ids = static fn (ExtensionRegistry $r): array => array_map(static fn (SlotContribution $c): string => $c->id, $r->contributions(Step::class));

    expect($ids(extensionRegistry()))->toBe(['plugin.first', 'host.third'])
        ->and($ids(extensionRegistry(['optional' => true])))->toBe(['plugin.first', 'optional.second', 'host.third'])
        ->and(extensionRegistry()->contributions(Step::class)[0])->toEqual(new SlotContribution(Step::class, 'plugin.first', 'plugin', FirstStep::class, 10, 'first'));
});

it('resolves the contributions in the same order', function (): void {
    expect(array_map(static fn (Step $s): string => $s->name(), extensionRegistry(['optional' => true])->resolve(Step::class)))
        ->toBe(['first', 'second', 'third']);
});

it('serves a handler by key and lists the keys', function (): void {
    $registry = extensionRegistry();

    expect($registry->handlerKeys(Step::class))->toBe(['first', 'third'])
        ->and($registry->handler(Step::class, 'third'))->toBeInstanceOf(ThirdStep::class)
        ->and(fn (): object => $registry->handler(Step::class, 'second'))->toThrow(MissingExtensionException::class, '"second"');
});

it('has no contributions for a slot no active module defines', function (): void {
    expect(extensionRegistry(['host' => false, 'plugin' => false])->contributions(Step::class))->toBe([])
        ->and(extensionRegistry()->contributions(Greeter::class))->toBe([]);
});

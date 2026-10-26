<?php

declare(strict_types=1);

use Vaulter\CoreModule;
use Vaulter\Drive\DriveModule;
use Vaulter\Drive\Operations\DriveOperationPolicies;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmeModule;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmeOperationPolicies;
use Vaulter\Integration\Tests\TestCase;
use Vaulter\Kernel;
use Vaulter\Media\MediaModule;
use Vaulter\Modules\Activation;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\InvalidModuleGraph;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\VaulterModule;
use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationCatalog;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Operations\OperationPolicySource;
use Vaulter\Operations\OperationSources;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Tests\Modules\Fixtures\FixtureModule;
use Vaulter\Tests\Modules\Fixtures\PlainGreeter;
use Vaulter\Tests\Operations\Fixtures\ContractOperationPolicies;

uses(TestCase::class, WithoutDatabaseSchema::class);

// D47, I12: the operation catalogue — the policy of every mutating operation, declared by the active modules with
// ModuleBuilder::operations(); a name has one owner (I5).

/** @param list<VaulterModule> $modules */
function operationCatalogOf(array $modules, array $overrides = []): OperationCatalog
{
    $compiled = (new ModuleCompiler)->compile($modules, $overrides);
    expect($compiled)->toBeInstanceOf(CompiledModules::class);
    assert($compiled instanceof CompiledModules);

    return new OperationCatalog(static fn (): array => OperationSources::compiled($compiled));
}

/** @return list<string> */
function operationGraphReasons(FixtureModule ...$modules): array
{
    $result = (new ModuleCompiler)->compile($modules, []);
    expect($result)->toBeInstanceOf(InvalidModuleGraph::class);

    return $result instanceof InvalidModuleGraph ? $result->reasons : [];
}

function operationModule(string $id, string ...$sources): FixtureModule
{
    return new FixtureModule($id, declare: static function (ModuleBuilder $m) use ($sources): void {
        foreach ($sources as $source) {
            $m->operations($source);
        }
    });
}

it('builds from the active modules of the application, ordered by name', function (): void {
    $catalog = app(OperationCatalog::class);
    $names = array_keys($catalog->all());
    $sorted = $names;
    sort($sorted, SORT_STRING);

    expect($names)->toBe($sorted)
        ->and($catalog->module('drive.folders.create'))->toBe('drive')
        ->and($catalog->policy('drive.folders.create'))->toEqual(new OperationPolicy('drive.node', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['folders.store']))
        ->and($catalog->policy('drive.uploads.append')?->idempotency)->toBe(IdempotencyPolicy::Protocol)
        ->and($catalog->policy('drive.nodes.missing'))->toBeNull()
        ->and($catalog->module('drive.nodes.missing'))->toBeNull()
        ->and(array_keys((new DriveOperationPolicies)->policies()))->each->toBeIn($names);
});

it('keeps the operations of an inactive module out and lists them once it is active', function (): void {
    $modules = [new CoreModule, new MediaModule, new DriveModule, new AcmeModule];

    expect(operationCatalogOf($modules)->policy(AcmeOperationPolicies::RECORD))->toBeNull()
        ->and(operationCatalogOf($modules, ['acme' => true])->module(AcmeOperationPolicies::RECORD))->toBe('acme')
        ->and(operationCatalogOf($modules, ['acme' => true])->policy('drive.folders.create'))->not->toBeNull();
});

it('refuses an operation declared by two installed modules, active or not', function (): void {
    $reasons = operationGraphReasons(
        operationModule('drive', ContractOperationPolicies::class),
        new FixtureModule('optional', Activation::OptIn, declare: static fn (ModuleBuilder $m) => $m->operations(ContractOperationPolicies::class)),
    );

    expect($reasons)->toContain('operation "drive.outer.write" is declared by both "drive" and "optional"')
        ->and($reasons)->toContain('module "optional" declares the operation "drive.outer.write" on the aggregate "drive.contract" of another module');
});

it('refuses an operation a module declares twice, a source that is not one and an aggregate of another module', function (): void {
    $reasons = operationGraphReasons(
        operationModule('drive', ContractOperationPolicies::class, ContractOperationPolicies::class, PlainGreeter::class),
        operationModule('acme', ContractOperationPolicies::class),
    );

    expect($reasons)->toContain(sprintf('module "drive" declares the operation source %s, which does not implement %s', PlainGreeter::class, OperationPolicySource::class))
        ->and($reasons)->toContain('module "acme" declares the operation "drive.inner.write" on the aggregate "drive.contract" of another module');

    // One source declared twice is one declaration; two sources naming the same operation are a duplicate.
    $twice = operationGraphReasons(operationModule('drive', ContractOperationPolicies::class, OperationCatalogTwinPolicies::class));
    expect($twice)->toContain(sprintf('module "drive" declares the operation "drive.outer.write" twice (%s)', OperationCatalogTwinPolicies::class));
});

it('refuses a malformed operation name and an entry without a policy', function (): void {
    $reasons = operationGraphReasons(operationModule('drive', OperationCatalogMalformedPolicies::class));

    expect($reasons)->toContain(sprintf('operation source %s declares the malformed operation "Not.An-Operation"', OperationCatalogMalformedPolicies::class))
        ->and($reasons)->toContain(sprintf('operation source %s declares "drive.raw.write" without an %s', OperationCatalogMalformedPolicies::class, OperationPolicy::class));
});

it('refuses a duplicate in a catalogue built by hand', function (): void {
    $catalog = new OperationCatalog(static fn (): array => ['drive' => [new ContractOperationPolicies], 'other' => [new ContractOperationPolicies]]);

    expect(fn () => $catalog->all())->toThrow(InvalidArgumentException::class, 'The operation "drive.outer.write" is declared by both the module "drive" and the module "other".');
});

it('validates a policy when it is built', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'an empty aggregate' => [static fn () => new OperationPolicy('', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt), 'is not an aggregate'],
    'an aggregate without its module' => [static fn () => new OperationPolicy('node', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt), 'is not an aggregate'],
    'a receipt over a generation' => [static fn () => new OperationPolicy('collaboration.document_log', ConcurrencyPolicy::Generation, IdempotencyPolicy::Receipt), 'cannot take a receipt over a generation-fenced protocol'],
    'a prefixed route' => [static fn () => new OperationPolicy('drive.node', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['vaulter.v1.nodes.update']), 'without the "vaulter.v1." prefix'],
    'a malformed route' => [static fn () => new OperationPolicy('drive.node', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['Nodes Update']), 'is not a route name'],
    'a route twice' => [static fn () => new OperationPolicy('drive.node', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['nodes.update', 'nodes.update']), 'lists a route twice'],
]);

it('names the module of its aggregate and exports its fields', function (): void {
    $policy = new OperationPolicy('webhooks.endpoint', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['webhook-endpoints.rotate-secret']);

    expect($policy->module())->toBe('webhooks')
        ->and($policy->toArray())->toBe(['aggregate' => 'webhooks.endpoint', 'concurrency' => 'if_match', 'idempotency' => 'receipt', 'routes' => ['webhook-endpoints.rotate-secret']]);
});

it('lists no operation while the module graph is invalid', function (): void {
    $kernel = new Kernel(app());
    $kernel->seal(new InvalidModuleGraph(['broken']));

    expect(OperationSources::active($kernel))->toBe([]);
});

/** A second source of the fixture operations: the same names through another class. */
final class OperationCatalogTwinPolicies implements OperationPolicySource
{
    public function policies(): array
    {
        return ['drive.outer.write' => new OperationPolicy('drive.contract', ConcurrencyPolicy::Append, IdempotencyPolicy::Natural)];
    }
}

/** A source whose table a compiler must not trust. */
final class OperationCatalogMalformedPolicies implements OperationPolicySource
{
    /** @phpstan-ignore return.type (the compiler must refuse what static analysis would) */
    public function policies(): array
    {
        return [
            'Not.An-Operation' => new OperationPolicy('drive.contract', ConcurrencyPolicy::Append, IdempotencyPolicy::Natural),
            'drive.raw.write' => 'append',
        ];
    }
}

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vaulter\Integration\Tests\TestCase;
use Vaulter\Kernel;
use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationCatalog;
use Vaulter\Operations\OperationsReference;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Tests\Operations\Fixtures\ContractOperationPolicies;

uses(TestCase::class, WithoutDatabaseSchema::class);

// `vaulter:operations` prints the operation catalogue of every offered module, as Markdown or as one JSON document.

/** @return array<string, array<string, mixed>> the rows of `vaulter:operations --json`, by operation */
function operationsJson(): array
{
    expect(Artisan::call('vaulter:operations', ['--json' => true]))->toBe(0);
    $document = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($document)->toHaveKey('operations');

    return array_column($document['operations'], null, 'operation');
}

it('prints every operation of the offered modules as JSON, an inactive opt-in module included', function (): void {
    $rows = operationsJson();

    expect($rows['drive.nodes.rename'])->toBe([
        'operation' => 'drive.nodes.rename',
        'module' => 'drive',
        'aggregate' => 'drive.node',
        'concurrency' => 'if_match',
        'idempotency' => 'receipt',
        'routes' => ['nodes.update'],
    ])
        ->and(app(Kernel::class)->isActive('webhooks'))->toBeFalse()
        ->and($rows['webhooks.endpoints.rotate_secret'])->toMatchArray(['module' => 'webhooks', 'aggregate' => 'webhooks.endpoint', 'routes' => ['webhook-endpoints.rotate-secret']])
        ->and($rows['drive.access.invalidate']['routes'])->toBe([])
        ->and(array_keys($rows))->toBe(array_column(app(OperationsReference::class)->rows(), 'operation'));

    $names = array_keys($rows);
    $sorted = $names;
    sort($sorted, SORT_STRING);
    expect($names)->toBe($sorted)
        // The runtime catalogue holds the active modules only.
        ->and(app(OperationCatalog::class)->policy('webhooks.endpoints.rotate_secret'))->toBeNull()
        ->and(app(OperationCatalog::class)->policy('drive.nodes.rename'))->not->toBeNull();
});

it('prints a Markdown table, one row per operation', function (): void {
    expect(Artisan::call('vaulter:operations'))->toBe(0);
    $table = trim(Artisan::output());

    expect($table)->toStartWith("| Operation | Module | Aggregate | Concurrency | Idempotency | Routes |\n|---|---|---|---|---|---|")
        ->and($table)->toContain('| `drive.trash.purge` | drive | `drive.node` | `if_match` | `receipt` | `nodes.purge` |')
        ->and($table)->toContain('| `drive.uploads.append` | drive | `drive.upload` | `state_machine` | `protocol` | `uploads.tus.patch`, `uploads.parts.store` |')
        ->and($table)->toContain('| `drive.access.invalidate` | drive | `drive.node_access` | `natural` | `natural` | — |')
        ->and(substr_count($table, "\n| `"))->toBe(count(operationsJson()));
});

it('renders the rows of the catalogue it is given', function (): void {
    $reference = new OperationsReference(new OperationCatalog(static fn (): array => ['drive' => [new ContractOperationPolicies]]));

    expect($reference->rows())->toBe([
        ['operation' => 'drive.inner.write', 'module' => 'drive', 'aggregate' => 'drive.contract', 'concurrency' => ConcurrencyPolicy::Append->value, 'idempotency' => IdempotencyPolicy::Natural->value, 'routes' => []],
        ['operation' => 'drive.outer.write', 'module' => 'drive', 'aggregate' => 'drive.contract', 'concurrency' => ConcurrencyPolicy::Append->value, 'idempotency' => IdempotencyPolicy::Natural->value, 'routes' => []],
    ])
        ->and($reference->markdown())->toBe(implode("\n", [
            '| Operation | Module | Aggregate | Concurrency | Idempotency | Routes |',
            '|---|---|---|---|---|---|',
            '| `drive.inner.write` | drive | `drive.contract` | `append` | `natural` | — |',
            '| `drive.outer.write` | drive | `drive.contract` | `append` | `natural` | — |',
        ]));
});

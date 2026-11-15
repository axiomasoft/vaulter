<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Operations\UnknownOperationException;
use Vaulter\Idempotency\IdempotencyStore;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ReplayAuthorizer;
use Vaulter\Idempotency\ResourceRef;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\ModuleReadiness;
use Vaulter\Operations\OperationCatalog;
use Vaulter\Operations\OperationClaim;
use Vaulter\Operations\OperationContext;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Operations\OperationPolicySource;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Operations\ScopeRef;
use Vaulter\ValueObjects\Operations\TenantRef;

/*
 * ADR-0028: the stages of OperationRunner::write() as a contract, on probe operations the test
 * declares. An undeclared mutation is refused before any claim or transaction; a key on a write without an
 * IdempotentWrite is refused before its attempt; a composed write has the one receipt of its root; a failing
 * after-commit callback surfaces as itself and never repeats the committed attempt, whose receipt answers the retry;
 * a failure that is not a cached refusal rolls the claim back with the attempt, so a retry runs again.
 * Serialization/deadlock retries of the whole attempt need transaction level 0 (drive Concurrency/OutermostRetryRealFailureTest).
 */

uses(TestCase::class, RefreshDatabase::class);

function orcRunner(): OperationRunner
{
    $source = new class implements OperationPolicySource
    {
        public function policies(): array
        {
            return [
                'core.probe.write' => new OperationPolicy('core.probe', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt),
                'core.probe.inner' => new OperationPolicy('core.probe_inner', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt),
            ];
        }
    };

    return new OperationRunner(app(Kernel::class), app(ModuleReadiness::class), app(VaulterDatabase::class), app(OperationClaim::class), new OperationCatalog(static fn (): array => ['core' => [$source]]));
}

function orcContext(string $key): OperationContext
{
    return OperationContext::system(ScopeRef::drive('D1'), TenantRef::none(), 'probe')->withIdempotencyKey($key);
}

function orcPlain(): OperationContext
{
    return OperationContext::system(ScopeRef::drive('D1'), TenantRef::none(), 'probe');
}

function orcWrite(): IdempotentWrite
{
    $codec = new class implements ReceiptCodec
    {
        public function kind(): string
        {
            return 'test.runner';
        }

        public function version(): int
        {
            return 1;
        }

        public function encode(mixed $result): array
        {
            return ['value' => $result];
        }

        public function decode(array $body, int $version): mixed
        {
            return $body['value'];
        }

        public function resource(mixed $result): ?ResourceRef
        {
            return null;
        }
    };
    $authorizer = new class implements ReplayAuthorizer
    {
        public function scope(mixed $result): ?ResourceRef
        {
            return new ResourceRef('drive', 'D1');
        }

        public function authorize(?ResourceRef $resource, ?ResourceRef $scope): void {}
    };

    return new IdempotentWrite($codec, $authorizer, ['input' => 'same']);
}

function orcSpec(string $name = 'core.probe.write'): OperationSpec
{
    return new OperationSpec($name, new ModuleId('core'));
}

function orcRows(): int
{
    return app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(IdempotencyStore::ENTITY))->count();
}

it('refuses a mutating operation no module declares before any claim or attempt', function (): void {
    $runs = 0;

    expect(fn () => orcRunner()->write(orcSpec('core.probe.undeclared'), orcContext('K'), static function () use (&$runs): void {
        $runs++;
    }, orcWrite()))->toThrow(UnknownOperationException::class);
    expect($runs)->toBe(0)->and(orcRows())->toBe(0);
});

it('refuses a key on a write without an IdempotentWrite before its attempt', function (): void {
    $runs = 0;

    expect(fn () => orcRunner()->write(orcSpec(), orcContext('K'), static function () use (&$runs): void {
        $runs++;
    }))->toThrow(InvalidArgumentException::class, 'does not accept an idempotency key');
    expect($runs)->toBe(0)->and(orcRows())->toBe(0);
});

it('runs a composed write in the root transaction with the one receipt of its root', function (): void {
    $runner = orcRunner();
    $inner = 0;

    $result = $runner->write(orcSpec(), orcContext('outer'), static function () use ($runner, &$inner): string {
        // The nested write carries a key of its own, and still claims nothing.
        return $runner->write(orcSpec('core.probe.inner'), orcContext('inner'), static function () use ($runner, &$inner): string {
            $inner++;

            return $runner->inOperation() ? 'nested' : 'root';
        }, orcWrite());
    }, orcWrite());

    expect($result)->toBe('nested')
        ->and($inner)->toBe(1)
        ->and(orcRows())->toBe(1)
        ->and($runner->inOperation())->toBeFalse();
});

it('surfaces a failed after-commit callback as itself and never repeats the committed attempt; the key replays it', function (): void {
    $runner = orcRunner();
    $database = app(VaulterDatabase::class);
    $runs = 0;
    $attempt = static function () use ($database, &$runs): string {
        $runs++;
        $database->afterCommit(static function (): void {
            throw new RuntimeException('after-commit failed');
        });

        return 'committed-'.$runs;
    };

    expect(fn () => $runner->write(orcSpec(), orcContext('K'), $attempt, orcWrite()))->toThrow(RuntimeException::class, 'after-commit failed');
    expect($runs)->toBe(1)->and(orcRows())->toBe(1);

    // The client retries the unknown outcome with the same key: the committed historical result, no second mutation.
    expect($runner->write(orcSpec(), orcContext('K'), $attempt, orcWrite()))->toBe('committed-1')
        ->and($runs)->toBe(1);
});

it('rolls the claim back with a failure that is not a cached refusal, so a retry runs again', function (): void {
    $runner = orcRunner();
    $runs = 0;
    $attempt = static function () use (&$runs): string {
        if (++$runs === 1) {
            throw new RuntimeException('transient');
        }

        return 'second';
    };

    expect(fn () => $runner->write(orcSpec(), orcContext('K'), $attempt, orcWrite()))->toThrow(RuntimeException::class, 'transient');
    expect(orcRows())->toBe(0)
        ->and($runner->write(orcSpec(), orcContext('K'), $attempt, orcWrite()))->toBe('second')
        ->and($runs)->toBe(2)
        ->and(orcRows())->toBe(1);
});

it('runs a write without a key with no claim and no receipt', function (): void {
    $runs = 0;
    $attempt = static function () use (&$runs): int {
        return ++$runs;
    };

    expect(orcRunner()->write(orcSpec(), orcPlain(), $attempt, orcWrite()))->toBe(1)
        ->and(orcRunner()->write(orcSpec(), orcPlain(), $attempt, orcWrite()))->toBe(2)
        ->and(orcRows())->toBe(0);
});

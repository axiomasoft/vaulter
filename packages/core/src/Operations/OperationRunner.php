<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Closure;
use stdClass;
use Throwable;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Support\InvalidExtensionResult;
use Vaulter\Kernel;

/**
 * The one way a write operation runs (ADR-0028, architecture#operation-chains). The stages are a
 * contract and no contribution removes one:
 *
 * 1. admission — a valid module graph, a ready module, a system actor with a reason;
 * 2. the OperationMiddleware chain, each calling `$next` exactly once and returning its result;
 * 3. the root transaction, repeated as a whole attempt after a serialization failure or a deadlock
 *    (never a nested one: a write inside a write runs in the same transaction, with no claim, no
 *    receipt and no own after-commit callbacks);
 * 4. the idempotency claim inside that transaction;
 * 5–8. the owner's attempt: load and lock, current authorization, validators and guards, the
 *    mutation with its events, the snapshot built from the locked state;
 * 9. commit, then the after-commit callbacks, then the result already built.
 *
 * Anything with an external effect (content bytes, network) happens before write() or after commit:
 * a repeated attempt repeats only database work.
 */
final class OperationRunner
{
    /** The writes now inside a root transaction of this runner. */
    private int $depth = 0;

    public function __construct(
        private readonly Kernel $kernel,
        private readonly ModuleReadiness $readiness,
        private readonly VaulterDatabase $database,
        private readonly OperationClaim $claim,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(Operation): T $attempt
     * @return T
     */
    public function write(OperationSpec $spec, OperationContext $context, Closure $attempt): mixed
    {
        $this->kernel->assertValid();
        $this->readiness->assertReady($spec->module);
        $operation = new Operation(spec: $spec, context: $context, nested: $this->depth > 0);

        /** @var list<OperationMiddleware> $middleware */
        $middleware = $this->kernel->extensions()->resolve(OperationMiddleware::class);

        return $this->through($middleware, $operation, fn (Operation $operation): mixed => $this->transact($operation, $attempt));
    }

    /** Whether a write is running in this runner's root transaction now (an operation composed into another). */
    public function inOperation(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @param list<OperationMiddleware> $middleware
     * @param Closure(Operation): mixed $core
     */
    private function through(array $middleware, Operation $operation, Closure $core): mixed
    {
        $next = $core;
        foreach (array_reverse($middleware) as $layer) {
            $next = static function (Operation $given) use ($layer, $next): mixed {
                $calls = 0;
                $produced = $unset = new stdClass;
                $result = $layer->handle($given, static function (Operation $passed) use ($layer, $given, $next, &$calls, &$produced): mixed {
                    if (++$calls > 1) {
                        throw InvalidExtensionResult::nextCalls($layer::class, $given->spec->name, $calls);
                    }
                    if ($passed !== $given) {
                        throw InvalidExtensionResult::foreignOperation($layer::class, $given->spec->name);
                    }

                    return $produced = $next($passed);
                });
                if ($calls !== 1) {
                    throw InvalidExtensionResult::nextCalls($layer::class, $given->spec->name, $calls);
                }
                if ($produced === $unset || $result !== $produced) {
                    throw InvalidExtensionResult::replacedResult($layer::class, $given->spec->name);
                }

                return $result;
            };
        }

        return $next($operation);
    }

    /**
     * @template T
     *
     * @param  Closure(Operation): T $attempt
     * @return T
     */
    private function transact(Operation $operation, Closure $attempt): mixed
    {
        if ($operation->isNested()) {
            return $this->enter(static fn (): mixed => $attempt($operation));
        }

        $this->claim->admit($operation);
        $attemptNo = 0;

        // Laravel never retries here (one attempt per transaction() call): a whole attempt is repeated
        // by retryingOutermost() only, once it has fully rolled back. A failure of an after-commit
        // callback comes after the commit: it leaves the retry loop at once, wrapped, and is rethrown as is.
        try {
            return $this->database->retryingOutermost(function () use ($operation, $attempt, &$attemptNo): mixed {
                $current = $operation->attempt(attemptNo: ++$attemptNo, nested: false);
                $committed = false;

                try {
                    return $this->database->transaction(function () use ($current, $attempt, &$committed): mixed {
                        $this->database->afterCommit(static function () use (&$committed): void {
                            $committed = true;
                        });

                        return $this->enter(
                            fn (): mixed => $this->claim->around($current, static fn (): mixed => $attempt($current)),
                        );
                    }, attempts: 1);
                } catch (Throwable $exception) {
                    throw $committed ? new AfterCommitFailure($exception) : $exception;
                }
            });
        } catch (AfterCommitFailure $failure) {
            throw $failure->original;
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T $callback
     * @return T
     */
    private function enter(Closure $callback): mixed
    {
        $this->depth++;
        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }
}

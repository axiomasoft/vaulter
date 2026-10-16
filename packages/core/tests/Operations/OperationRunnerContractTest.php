<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Nodes\CreateFolderAction;
use Vaulter\Drive\Data\Nodes\CreateFolderInput;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Exceptions\Database\ConcurrentUpdateException;
use Vaulter\Exceptions\Modules\ModuleNotReadyException;
use Vaulter\Exceptions\Support\InvalidExtensionResult;
use Vaulter\Kernel;
use Vaulter\Models\Drive\Node;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\InvalidModuleGraph;
use Vaulter\Modules\ModuleGraphException;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\SlotContribution;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\ModuleReadiness;
use Vaulter\Operations\NoOperationClaim;
use Vaulter\Operations\Operation;
use Vaulter\Operations\OperationMiddleware;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Tests\Operations\Fixtures\OuterMiddleware;
use Vaulter\Tests\Operations\Fixtures\ProbeMiddleware;
use Vaulter\Tests\TestCase;

/**
 * I8, the operation pipeline (ADR-0028): the stages of OperationRunner::write() and the contract of
 * an OperationMiddleware. Committed migrations, not a wrapping test transaction: the root transaction
 * and its retry are only honest at transaction level 0.
 */
uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    ProbeMiddleware::$mode = 'pass';
    ProbeMiddleware::$log = [];
});

/**
 * A runner over the application's compiled modules with `$middleware` as the only middleware.
 *
 * @param list<class-string<OperationMiddleware>> $middleware
 */
function runnerContractRunner(array $middleware, CompiledModules|InvalidModuleGraph|null $state = null): OperationRunner
{
    $compiled = app(Kernel::class)->compiled();
    $kernel = new Kernel(app());
    if ($state === null) {
        $arguments = get_object_vars($compiled);
        $arguments['contributions'][OperationMiddleware::class] = array_map(
            static fn (string $class, int $i): SlotContribution => new SlotContribution(OperationMiddleware::class, 'test.middleware.'.$i, 'core', $class, $i, null),
            $middleware,
            array_keys($middleware),
        );
        $state = new CompiledModules(...$arguments);
    }
    $kernel->seal($state);

    return new OperationRunner($kernel, new ModuleReadiness($kernel, app(VaulterDatabase::class), app(DatabaseConfig::class)), app(VaulterDatabase::class), new NoOperationClaim);
}

function runnerContractSpec(string $name = 'drive.folders.create', string $module = 'drive'): OperationSpec
{
    return new OperationSpec(name: $name, module: new ModuleId($module));
}

function runnerContractFolder(DriveContext $context, string $name): Node
{
    $drive = $context->drive;
    $root = Node::query()->findOrFail($drive->root_node_id);

    return app(CreateFolderAction::class)->execute($context, new CreateFolderInput($drive, $name, $root));
}

function runnerContractCounts(string $name): array
{
    $database = app(VaulterDatabase::class);
    $tables = app(DatabaseConfig::class);

    return [
        'nodes' => Node::query()->where('name', $name)->count(),
        'activity' => $database->connection()->table($tables->table('activity'))->count(),
    ];
}

it('runs the attempt in one root transaction behind the middleware, in contribution order', function (): void {
    $drive = AccessTestHelpers::storage();
    $context = DriveContext::system($drive, 'runner contract');
    $levels = [];

    $result = runnerContractRunner([OuterMiddleware::class, ProbeMiddleware::class])->write(runnerContractSpec(), $context->operation, function (Operation $operation) use ($context, &$levels): Node {
        $levels[] = app(VaulterDatabase::class)->connection()->transactionLevel();
        ProbeMiddleware::$log[] = 'attempt:'.$operation->attemptNo;

        return runnerContractFolder($context, 'pipeline');
    });

    expect($result)->toBeInstanceOf(Node::class)
        ->and($levels)->toBe([1])
        ->and(ProbeMiddleware::$log)->toBe(['outer:before', 'probe:drive.folders.create', 'attempt:1', 'outer:after'])
        ->and(app(VaulterDatabase::class)->connection()->transactionLevel())->toBe(0)
        ->and(runnerContractCounts('pipeline')['nodes'])->toBe(1);
});

it('lets a middleware veto before next without a mutation', function (): void {
    $context = DriveContext::system(AccessTestHelpers::storage(), 'runner contract');
    $before = runnerContractCounts('vetoed');
    ProbeMiddleware::$mode = 'veto';
    $ran = false;

    expect(fn () => runnerContractRunner([ProbeMiddleware::class])->write(runnerContractSpec(), $context->operation, function () use ($context, &$ran): Node {
        $ran = true;

        return runnerContractFolder($context, 'vetoed');
    }))->toThrow(RuntimeException::class, 'vetoed');

    expect($ran)->toBeFalse()->and(runnerContractCounts('vetoed'))->toBe($before);
});

it('fails with extension_failed when a middleware breaks the next contract', function (string $mode, bool $attemptRuns): void {
    $context = DriveContext::system(AccessTestHelpers::storage(), 'runner contract');
    ProbeMiddleware::$mode = $mode;
    $attempts = 0;

    try {
        runnerContractRunner([ProbeMiddleware::class])->write(runnerContractSpec(), $context->operation, function () use (&$attempts): object {
            $attempts++;

            return new stdClass;
        });
        $this->fail('The broken middleware was accepted.');
    } catch (InvalidExtensionResult $failure) {
        expect($failure->problemCode())->toBe('extension_failed')->and($failure->httpStatus())->toBe(500);
    }

    expect($attempts)->toBe($attemptRuns ? 1 : 0);
})->with([
    'next never called' => ['skip', false],
    'next called twice' => ['twice', true],
    'result replaced' => ['replace', true],
    'another operation passed' => ['foreign', false],
]);

it('treats a middleware that swallows the failure of the operation as replacing its result', function (): void {
    $context = DriveContext::system(AccessTestHelpers::storage(), 'runner contract');
    ProbeMiddleware::$mode = 'swallow';

    expect(fn () => runnerContractRunner([ProbeMiddleware::class])->write(runnerContractSpec(), $context->operation, static function (): never {
        throw new RuntimeException('attempt failed');
    }))->toThrow(InvalidExtensionResult::class);
});

it('rolls back the mutation and its events when the attempt throws', function (): void {
    $context = DriveContext::system(AccessTestHelpers::storage(), 'runner contract');
    $before = runnerContractCounts('rolled-back');

    expect(fn () => runnerContractRunner([])->write(runnerContractSpec(), $context->operation, function () use ($context): never {
        runnerContractFolder($context, 'rolled-back');

        throw new RuntimeException('after the mutation');
    }))->toThrow(RuntimeException::class, 'after the mutation');

    expect(runnerContractCounts('rolled-back'))->toBe($before);
});

it('runs a nested write in the outer transaction with one commit and one set of after-commit callbacks', function (): void {
    $context = DriveContext::system(AccessTestHelpers::storage(), 'runner contract');
    $runner = runnerContractRunner([ProbeMiddleware::class]);
    $database = app(VaulterDatabase::class);
    $commits = [];
    $levels = [];

    $runner->write(runnerContractSpec('drive.outer.write'), $context->operation, function (Operation $outer) use ($runner, $context, $database, &$commits, &$levels): void {
        $levels[] = $database->connection()->transactionLevel();
        $database->afterCommit(static function () use (&$commits): void {
            $commits[] = 'outer';
        });
        $runner->write(runnerContractSpec('drive.inner.write'), $context->operation, function (Operation $inner) use ($context, $database, &$commits, &$levels): void {
            expect($inner->isNested())->toBeTrue();
            $levels[] = $database->connection()->transactionLevel();
            runnerContractFolder($context, 'nested');
            $database->afterCommit(static function () use (&$commits): void {
                $commits[] = 'inner';
            });
            expect($commits)->toBe([]);
        });
        expect($outer->isNested())->toBeFalse()->and($commits)->toBe([]);
    });

    expect($commits)->toBe(['outer', 'inner'])
        ->and($levels[0])->toBe(1)
        ->and($levels[1])->toBeGreaterThanOrEqual(1)
        ->and(ProbeMiddleware::$log)->toBe(['probe:drive.outer.write', 'probe:drive.inner.write:nested'])
        ->and(runnerContractCounts('nested')['nodes'])->toBe(1);
});

it('rolls back a nested write together with its outer operation', function (): void {
    $context = DriveContext::system(AccessTestHelpers::storage(), 'runner contract');
    $runner = runnerContractRunner([]);
    $commits = 0;

    expect(fn () => $runner->write(runnerContractSpec('drive.outer.write'), $context->operation, function () use ($runner, $context, &$commits): never {
        $runner->write(runnerContractSpec('drive.inner.write'), $context->operation, function () use ($context, &$commits): void {
            runnerContractFolder($context, 'nested-rolled-back');
            app(VaulterDatabase::class)->afterCommit(static function () use (&$commits): void {
                $commits++;
            });
        });

        throw new RuntimeException('outer failed');
    }))->toThrow(RuntimeException::class, 'outer failed');

    expect($commits)->toBe(0)->and(runnerContractCounts('nested-rolled-back')['nodes'])->toBe(0);
});

it('repeats the whole attempt after a deadlock, with one set of events and after-commit callbacks', function (): void {
    $context = DriveContext::system(AccessTestHelpers::storage(), 'runner contract');
    $before = runnerContractCounts('retried');
    $attempts = [];
    $commits = 0;

    $node = runnerContractRunner([ProbeMiddleware::class])->write(runnerContractSpec(), $context->operation, function (Operation $operation) use ($context, &$attempts, &$commits): Node {
        $attempts[] = $operation->attemptNo;
        $node = runnerContractFolder($context, 'retried');
        app(VaulterDatabase::class)->afterCommit(static function () use (&$commits): void {
            $commits++;
        });
        if ($operation->attemptNo === 1) {
            throw new QueryException('testing', 'update x', [], new PDOException('SQLSTATE[40001]: Serialization failure: injected deadlock'));
        }

        return $node;
    });

    $after = runnerContractCounts('retried');
    expect($attempts)->toBe([1, 2])
        ->and($commits)->toBe(1)
        ->and(ProbeMiddleware::$log)->toBe(['probe:drive.folders.create'])
        ->and($node->exists)->toBeTrue()
        ->and($after['nodes'])->toBe(1)
        ->and($after['activity'] - $before['activity'])->toBe(1);
});

it('never repeats a committed attempt when an after-commit callback fails with a concurrency error', function (): void {
    $context = DriveContext::system(AccessTestHelpers::storage(), 'runner contract');
    $before = runnerContractCounts('committed');
    $attempts = 0;
    $runner = runnerContractRunner([]);

    $write = function () use ($runner, $context, &$attempts): void {
        $runner->write(runnerContractSpec(), $context->operation, function () use ($context, &$attempts): void {
            $attempts++;
            runnerContractFolder($context, 'committed');
            app(VaulterDatabase::class)->afterCommit(static function (): void {
                throw new QueryException('testing', 'insert into jobs', [], new PDOException('SQLSTATE[40001]: Serialization failure: injected after commit'));
            });
        });
    };

    expect($write)->toThrow(QueryException::class);

    expect($attempts)->toBe(1)
        ->and(runnerContractCounts('committed')['nodes'])->toBe(1)
        ->and(runnerContractCounts('committed')['activity'] - $before['activity'])->toBe(1);
});

it('gives up with concurrent_update after three attempts and leaves the runner reusable', function (): void {
    $context = DriveContext::system(AccessTestHelpers::storage(), 'runner contract');
    $runner = runnerContractRunner([]);
    $attempts = 0;

    $exhaust = function () use ($runner, $context, &$attempts): void {
        $runner->write(runnerContractSpec(), $context->operation, function () use (&$attempts): void {
            $attempts++;
            throw new QueryException('testing', 'update x', [], new PDOException('SQLSTATE[40001]: Serialization failure: injected'));
        });
    };
    expect($exhaust)->toThrow(ConcurrentUpdateException::class);
    expect($attempts)->toBe(3)->and($runner->inOperation())->toBeFalse();

    expect(fn () => $runner->write(runnerContractSpec(), $context->operation, static fn () => throw new LogicException('plain')))->toThrow(LogicException::class);
    expect($runner->inOperation())->toBeFalse();
});

it('refuses before any transaction when the module graph is invalid or the module is not ready', function (): void {
    $context = DriveContext::system(AccessTestHelpers::storage(), 'runner contract');
    $ran = false;
    $attempt = function () use (&$ran): void {
        $ran = true;
    };

    expect(fn () => runnerContractRunner([], new InvalidModuleGraph(['broken']))->write(runnerContractSpec(), $context->operation, $attempt))
        ->toThrow(ModuleGraphException::class)
        ->and(fn () => runnerContractRunner([])->write(runnerContractSpec('absent.thing.write', 'absent'), $context->operation, $attempt))
        ->toThrow(ModuleNotReadyException::class)
        ->and($ran)->toBeFalse();
});

it('refuses an idempotency key that no operation accepts yet before the transaction', function (): void {
    $context = DriveContext::system(AccessTestHelpers::storage(), 'runner contract');
    $ran = false;

    expect(fn () => runnerContractRunner([])->write(runnerContractSpec(), $context->operation->withIdempotencyKey('key-1'), function () use (&$ran): void {
        $ran = true;
    }))->toThrow(InvalidArgumentException::class)
        ->and($ran)->toBeFalse();
});

it('contributes the telemetry middleware to the container runner', function (): void {
    $ids = array_map(static fn (SlotContribution $c): string => $c->id, app(Kernel::class)->extensions()->contributions(OperationMiddleware::class));

    expect($ids)->toBe(['core.telemetry'])
        ->and(app(OperationRunner::class))->toBe(app(OperationRunner::class));
});

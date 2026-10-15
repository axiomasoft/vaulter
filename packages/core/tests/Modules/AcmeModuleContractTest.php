<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Content\DownloadGuards;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Contracts\Drive\DownloadGuard;
use Vaulter\Contracts\Drive\NodeSnapshotContributor;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\CoreModule;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Drive\DriveModule;
use Vaulter\Drive\Events\Nodes\NodeRenamed;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\EventSink;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Events\Outbox\OutboxRelay;
use Vaulter\Events\Outbox\OutboxStore;
use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Exceptions\Support\InvalidExtensionResult;
use Vaulter\Kernel;
use Vaulter\Media\MediaModule;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\InvalidModuleGraph;
use Vaulter\Modules\ModuleBindings;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\OffersModules;
use Vaulter\Modules\SlotContribution;
use Vaulter\Modules\SlotKind;
use Vaulter\Modules\VaulterModule;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\ModuleReadiness;
use Vaulter\Operations\NoOperationClaim;
use Vaulter\Operations\Operation;
use Vaulter\Operations\OperationMiddleware;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Testing\Contracts\ModuleContractTests;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeConstraints;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeDownloadGuard;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeEventSink;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeMiddleware;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeModule;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeOutboxConsumer;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeProbe;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeRestriction;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeRestrictionRunner;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeRestrictionViolation;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeSnapshotContributor;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\AcmeWidening;
use Vaulter\Tests\Modules\Fixtures\AcmeModule\Broken\BrokenModule;
use Vaulter\Tests\Modules\Fixtures\FixtureModule;
use Vaulter\Tests\TestCase;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\Webhooks\WebhooksModule;

/**
 * I8, proven on an independent extension: the third-party AcmeModule contributes to every slot kind through the
 * public module surface only, and the kernel treats its contributions exactly like the built-in ones — a veto
 * refuses, a restriction cannot widen, an observer changes no result, a middleware calls `next` once and returns
 * its result, nothing a contribution does skips the transaction, the receipt or the outbox. Committed migrations, so
 * a rollback here is a real one.
 */
uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    AcmeProbe::reset();
    AcmeMiddleware::$mode = 'pass';
    AcmeEventSink::$mode = 'observe';
    AcmeEventSink::$seen = [];
    AcmeDownloadGuard::$mode = 'allow';
    AcmeDownloadGuard::$checks = 0;
    AcmeSnapshotContributor::$calls = 0;
    AcmeOutboxConsumer::$consumed = [];
    AcmeOutboxConsumer::$failing = false;
});

/** @return list<class-string<VaulterModule>> */
function acmeManifests(bool $withAcme): array
{
    return [new CoreModule, new MediaModule, new DriveModule, new WebhooksModule, ...($withAcme ? [new AcmeModule] : [])];
}

/** The application's kernel resealed over the core modules, with the AcmeModule active or not, plus `$extra` contributions. */
function acmeKernel(bool $withAcme = true, SlotContribution ...$extra): Kernel
{
    $compiled = (new ModuleCompiler)->compile(acmeManifests($withAcme), $withAcme ? ['acme' => true] : []);
    expect($compiled)->toBeInstanceOf(CompiledModules::class);
    $arguments = get_object_vars($compiled);
    foreach ($extra as $contribution) {
        $arguments['contributions'][$contribution->slot][] = $contribution;
    }
    $kernel = new Kernel(app());
    $kernel->seal(new CompiledModules(...$arguments));
    app()->instance(Kernel::class, $kernel);
    app()->forgetInstance(OutboxRelay::class);

    return $kernel;
}

it('passes the module contract tests of the kit', function (): void {
    (new ModuleContractTests)->assertModuleContract(new AcmeModule, [new MediaModule, new DriveModule]);

    expect(AcmeProbe::$resolved)->toBe([]);
});

it('makes the module contract tests fail on a manifest that is not declarations only', function (string $flaw, string $message): void {
    $module = new BrokenModule($flaw);

    expect(fn () => (new ModuleContractTests)->assertModuleContract($module))->toThrow(AssertionFailedError::class, $message);
})->with([
    'a query while declaring' => [BrokenModule::QUERIES, 'ran 2 query/queries'],
    'a container resolution while declaring' => [BrokenModule::RESOLVES, 'resolved stdClass'],
    'different declarations on each call' => [BrokenModule::FLAKY, 'different declarations'],
    'a requirement that is not installed' => [BrokenModule::UNKNOWN_DEPENDENCY, 'requires "nowhere", which is not installed'],
    'a version that is not MAJOR.MINOR' => [BrokenModule::BAD_VERSION, 'version must be MAJOR.MINOR'],
    'a constraint that is not ^MAJOR.MINOR' => [BrokenModule::BAD_CONSTRAINT, 'as ^MAJOR.MINOR'],
]);

it('uses only the module surface, the slot contracts and what their signatures name', function (): void {
    // Until the surface is attributed (P3.17) the allowed surface is listed here: the types a manifest and a slot owner
    // use, and every type that appears in the signature of a slot the module contributes to.
    $allowed = [
        Kernel::class, Activation::class, Cardinality::class, ModuleBindings::class,
        ModuleBuilder::class, ModuleId::class, OffersModules::class, SlotKind::class,
        VaulterModule::class,
        // Documented in the contract of the slot: the denial a DownloadGuard throws, the findings a DoctorCheck returns.
        ContentUnavailableException::class,
        DoctorFinding::class,
    ];
    $slots = [
        OperationMiddleware::class, EventSink::class, OutboxConsumer::class,
        DoctorCheck::class, DownloadGuard::class, NodeSnapshotContributor::class,
    ];
    foreach ($slots as $slot) {
        $allowed[] = $slot;
        foreach ((new ReflectionClass($slot))->getMethods() as $method) {
            foreach ([$method->getReturnType(), ...array_map(static fn (ReflectionParameter $p): ?ReflectionType => $p->getType(), $method->getParameters())] as $type) {
                foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $named) {
                    if ($named instanceof ReflectionNamedType && !$named->isBuiltin()) {
                        $allowed[] = $named->getName();
                    }
                }
            }
        }
    }

    $imports = [];
    foreach (glob(__DIR__.'/Fixtures/AcmeModule/*.php') ?: [] as $file) {
        preg_match_all('/^use ([^;]+);$/m', (string) file_get_contents($file), $matches);
        foreach ($matches[1] as $import) {
            if (str_starts_with($import, 'Vaulter\\') && !str_starts_with($import, 'Vaulter\\Tests\\Modules\\Fixtures\\AcmeModule\\')) {
                $imports[$import][] = basename($file);
            }
        }
    }

    expect($imports)->not->toBe([])
        ->and(array_diff(array_keys($imports), $allowed))->toBe([]);
});

it('lets a module define its own Restriction slot, and only narrows with it', function (): void {
    $kernel = acmeKernel();
    $slot = $kernel->compiled()->slots[AcmeRestriction::class];
    $input = new AcmeConstraints(['read', 'write', 'delete']);

    expect($slot->module)->toBe('acme')
        ->and($slot->kind)->toBe(SlotKind::Restriction)
        ->and((new AcmeRestrictionRunner($kernel))->apply($input)->abilities)->toBe(['read', 'write']);

    $widening = new SlotContribution(AcmeRestriction::class, 'test.widening', 'acme', AcmeWidening::class, 5, null);
    expect(fn () => (new AcmeRestrictionRunner(acmeKernel(true, $widening)))->apply($input))
        ->toThrow(AcmeRestrictionViolation::class, 'AcmeWidening widened the constraints');
});

it('refuses a contribution to the slot of a module that the contributor does not require', function (): void {
    $other = new FixtureModule('other', requires: ['core' => '^1.0'], declare: static function (ModuleBuilder $module): void {
        $module->slot(AcmeRestriction::class, AcmeWidening::class, 'other.widening');
    });
    $result = (new ModuleCompiler)->compile([...acmeManifests(true), $other], ['acme' => true, 'other' => true]);

    expect($result)->toBeInstanceOf(InvalidModuleGraph::class)
        ->and($result->reasons)->toContain(sprintf('module "other" contributes "other.widening" to %s but does not require "acme", which defines it', AcmeRestriction::class));
});

it('lists the contributions of the module in the slots of the kernel and the drive, in order', function (): void {
    $kernel = acmeKernel();
    $ids = static fn (string $slot): array => array_map(static fn (SlotContribution $c): string => $c->id, $kernel->extensions()->contributions($slot));

    expect($ids(OperationMiddleware::class))->toBe(['core.telemetry', 'acme.middleware'])
        ->and($ids(EventSink::class))->toBe(['drive.activity', 'drive.changes', 'acme.sink'])
        ->and($ids(OutboxConsumer::class))->toBe(['acme.audit'])
        ->and($ids(DownloadGuard::class))->toBe(['acme.download-guard', 'drive.content-availability'])
        ->and($ids(NodeSnapshotContributor::class))->toBe(['acme.snapshot'])
        ->and($ids(DoctorCheck::class))->toContain('acme.endpoint');
});

/** Calls every download guard of `$kernel` for a node that exists only as a model. */
function acmeDeliver(Kernel $kernel): void
{
    $drive = new Drive;
    $drive->exists = true;
    $drive->setRawAttributes(['id' => 1], true);

    (new DownloadGuards($kernel))->check(DriveContext::system($drive, 'acme contract'), new Node, new FileRevision);
}

it('lets a guard of the module veto a delivery, and treats any other failure of it as a denial too', function (string $mode): void {
    AcmeDownloadGuard::$mode = $mode;

    // The drive's own availability guard runs after the module's and denies the model-only node: the module's verdict is what changes.
    expect(fn () => acmeDeliver(acmeKernel()))->toThrow(ContentUnavailableException::class)
        ->and(AcmeDownloadGuard::$checks)->toBe(1);
})->with(['allow', 'deny', 'explode']);

it('fails closed on a snapshot fragment the snapshot has no place for, and never calls the contributor', function (): void {
    $node = DriveTestHelpers::model(DriveTestHelpers::drive()->folders()->create('alpha')->id);
    $mapper = app(NodeSnapshotMapper::class);
    expect($mapper->map($node)->id)->toBe((string) $node->getKey());

    acmeKernel();

    expect(fn () => $mapper->map($node))->toThrow(InvalidExtensionResult::class, 'reserved fragment key "acme"')
        ->and(AcmeSnapshotContributor::$calls)->toBe(0);
});

/** An operation runner over `$kernel`, as the drive operations use it. */
function acmeRunner(Kernel $kernel): OperationRunner
{
    $database = app(VaulterDatabase::class);

    return new OperationRunner($kernel, new ModuleReadiness($kernel, $database, app(DatabaseConfig::class)), $database, new NoOperationClaim);
}

function acmeWrite(OperationRunner $runner, Closure $attempt): mixed
{
    $context = DriveContext::system(AccessTestHelpers::storage(), 'acme contract');

    return $runner->write(new OperationSpec(name: 'drive.folders.create', module: new ModuleId('drive')), $context->operation, $attempt);
}

it('runs the operation once behind the module middleware, inside the root transaction, and returns its own result', function (): void {
    $result = new stdClass;
    $levels = [];

    $returned = acmeWrite(acmeRunner(acmeKernel()), function (Operation $operation) use ($result, &$levels): stdClass {
        $levels[] = app(VaulterDatabase::class)->connection()->transactionLevel();
        AcmeProbe::$log[] = 'attempt:'.$operation->attemptNo;

        return $result;
    });

    expect($returned)->toBe($result)
        ->and($levels)->toBe([1])
        ->and(AcmeProbe::$log)->toBe(['middleware:drive.folders.create', 'attempt:1']);
});

it('refuses an operation the middleware of the module vetoes, before the operation runs', function (): void {
    AcmeMiddleware::$mode = 'veto';
    $ran = false;

    expect(fn () => acmeWrite(acmeRunner(acmeKernel()), function () use (&$ran): stdClass {
        $ran = true;

        return new stdClass;
    }))->toThrow(RuntimeException::class, 'acme vetoed')
        ->and($ran)->toBeFalse();
});

it('fails with extension_failed when the middleware of the module breaks the next contract', function (string $mode, bool $attemptRuns): void {
    AcmeMiddleware::$mode = $mode;
    $attempts = 0;

    try {
        acmeWrite(acmeRunner(acmeKernel()), function () use (&$attempts): stdClass {
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
]);

/** A rename of `$folder` to be recorded by the sinks of `$kernel`. */
function acmeRecord(Kernel $kernel, Node $folder): void
{
    $recorder = new EventRecorder(app(VaulterDatabase::class), app(), app(MetricsRecorder::class), $kernel);
    $event = new NodeRenamed(
        node: $folder,
        fromName: 'alpha',
        toName: 'renamed',
        actor: Actor::system('acme-contract')->ref(),
        correlationId: (string) Str::ulid(),
    );

    app(VaulterDatabase::class)->transaction(static function () use ($recorder, $folder, $event): void {
        $folder->forceFill(['name' => 'renamed'])->save();
        $recorder->record($event);
    });
}

function acmeActivityCount(): int
{
    return app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table('activity'))->count();
}

it('observes the event after the drive sinks inside the transaction, and changes nothing they stored', function (): void {
    $folder = DriveTestHelpers::model(DriveTestHelpers::drive()->folders()->create('alpha')->id);
    $dispatched = new ArrayObject;
    Event::listen(NodeRenamed::class, static function (NodeRenamed $event) use ($dispatched): void {
        $dispatched[] = $event->eventId;
    });
    $before = acmeActivityCount();

    acmeRecord(acmeKernel(), $folder);

    expect(AcmeEventSink::$seen)->toHaveCount(1)
        ->and(AcmeEventSink::$seen[0]['transactionLevel'])->toBeGreaterThanOrEqual(1)
        ->and(AcmeEventSink::$seen[0]['journalSeq'])->not->toBeNull()
        ->and(acmeActivityCount())->toBe($before + 1)
        ->and(count($dispatched))->toBe(1)
        ->and(DriveTestHelpers::model((string) $folder->getKey())->name)->toBe('renamed');
});

it('rolls the operation back when the sink of the module throws', function (): void {
    $folder = DriveTestHelpers::model(DriveTestHelpers::drive()->folders()->create('alpha')->id);
    $dispatched = new ArrayObject;
    Event::listen(NodeRenamed::class, static function (NodeRenamed $event) use ($dispatched): void {
        $dispatched[] = $event->eventId;
    });
    $before = acmeActivityCount();
    AcmeEventSink::$mode = 'throw';

    expect(fn () => acmeRecord(acmeKernel(), $folder))->toThrow(RuntimeException::class, 'acme sink failed')
        ->and(acmeActivityCount())->toBe($before)
        ->and(count($dispatched))->toBe(0)
        ->and(DriveTestHelpers::model((string) $folder->getKey())->name)->toBe('alpha');
});

/** @return array<string, mixed>|null */
function acmeDelivery(string $eventId): ?array
{
    return WebhookTestHelpers::outboxDelivery($eventId, AcmeOutboxConsumer::ID);
}

it('delivers an outbox event to the consumer of the module, and retries it when the consumer fails', function (): void {
    acmeKernel();
    $delivered = WebhookTestHelpers::outbox(['created_at' => now()->subSeconds(900)], consumer: AcmeOutboxConsumer::ID);
    app(OutboxRelay::class)->pass();

    expect(AcmeOutboxConsumer::$consumed)->toBe([$delivered->event_id])
        ->and(acmeDelivery($delivered->event_id)['state'])->toBe('done');

    AcmeOutboxConsumer::$failing = true;
    $failed = WebhookTestHelpers::outbox(['created_at' => now()->subSeconds(900)], consumer: AcmeOutboxConsumer::ID);
    app(OutboxRelay::class)->pass();

    expect(acmeDelivery($failed->event_id)['state'])->toBe('pending')
        ->and((int) acmeDelivery($failed->event_id)['attempts'])->toBe(1)
        ->and(AcmeOutboxConsumer::$consumed)->toBe([$delivered->event_id]);
});

it('pauses the consumer of a module switched off without losing its events, and resumes it where it stopped', function (): void {
    acmeKernel();
    app(OutboxRelay::class)->pass();

    acmeKernel(false);
    AcmeProbe::reset();
    $waiting = WebhookTestHelpers::outbox(['created_at' => now()->subSeconds(900)], consumer: AcmeOutboxConsumer::ID);
    app(OutboxRelay::class)->pass();

    $consumer = app(OutboxStore::class)->consumer(AcmeOutboxConsumer::ID);
    expect($consumer['state'])->toBe('paused')
        ->and(acmeDelivery($waiting->event_id)['state'])->toBe('pending')
        ->and(AcmeOutboxConsumer::$consumed)->toBe([])
        ->and(AcmeProbe::$resolved)->toBe([]);

    acmeKernel();
    app(OutboxRelay::class)->pass();

    expect(app(OutboxStore::class)->consumer(AcmeOutboxConsumer::ID)['state'])->toBe('active')
        ->and(acmeDelivery($waiting->event_id)['state'])->toBe('done')
        ->and(AcmeOutboxConsumer::$consumed)->toBe([$waiting->event_id]);
});

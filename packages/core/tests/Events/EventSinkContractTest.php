<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\Nodes\NodeRenamed;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\EventSink;
use Vaulter\Events\SinkResult;
use Vaulter\Events\SinkWriter;
use Vaulter\Kernel;
use Vaulter\Models\Drive\Node;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\SlotContribution;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Events\Fixtures\AlphaSink;
use Vaulter\Tests\Events\Fixtures\BetaSink;
use Vaulter\Tests\Events\Fixtures\GammaSink;
use Vaulter\Tests\Events\Fixtures\ScriptedSink;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Access\Actor;

/**
 * The EventSink slot (D38): the recorder runs the contributions in the order (priority, id), the first sink
 * that keeps the event tells a new occurrence from a replay, a partial store and a failing sink roll the
 * operation back, and a sink writes only inside the operation's transaction. Committed migrations, so a
 * rollback here is a real one.
 */
uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    ScriptedSink::reset();
    $this->drive = DriveTestHelpers::drive();
    $this->folder = $this->drive->folders()->create('alpha');
    $this->dispatched = new ArrayObject;
    Event::listen(NodeRenamed::class, function (NodeRenamed $event): void {
        $this->dispatched[] = $event->eventId;
    });
});

/**
 * A recorder over the application's compiled modules with `$sinks` (id => [class, priority]) as the only sinks.
 *
 * @param array<string, array{0: class-string<EventSink>, 1: int}> $sinks
 */
function sinkContractRecorder(array $sinks): EventRecorder
{
    $arguments = get_object_vars(app(Kernel::class)->compiled());
    $contributions = [];
    foreach ($sinks as $id => [$class, $priority]) {
        $contributions[] = new SlotContribution(EventSink::class, $id, 'core', $class, $priority, null);
    }
    usort($contributions, static fn (SlotContribution $a, SlotContribution $b): int => $a->priority <=> $b->priority ?: strcmp($a->id, $b->id));
    $arguments['contributions'][EventSink::class] = $contributions;
    $kernel = new Kernel(app());
    $kernel->seal(new CompiledModules(...$arguments));

    return new EventRecorder(app(VaulterDatabase::class), app(), app(MetricsRecorder::class), $kernel);
}

function sinkContractEvent(Node $folder, ?string $eventId = null): NodeRenamed
{
    return new NodeRenamed(
        node: $folder,
        fromName: 'alpha',
        toName: 'alpha',
        actor: Actor::system('sink-contract')->ref(),
        correlationId: (string) Str::ulid(),
        eventId: $eventId,
    );
}

function sinkContractRecord(EventRecorder $recorder, NodeRenamed $event): void
{
    app(VaulterDatabase::class)->transaction(static fn () => $recorder->record($event));
}

it('runs the sinks by priority, then id, and shares values with the sinks after', function (): void {
    $recorder = sinkContractRecorder([
        'test.gamma' => [GammaSink::class, -10],
        'test.beta' => [BetaSink::class, 0],
        'test.alpha' => [AlphaSink::class, 0],
    ]);

    sinkContractRecord($recorder, sinkContractEvent(DriveTestHelpers::model($this->folder->id)));

    expect(ScriptedSink::$log)->toBe([GammaSink::class, AlphaSink::class, BetaSink::class])
        ->and(ScriptedSink::$seen)->toBe([GammaSink::class => null, AlphaSink::class => GammaSink::class, BetaSink::class => AlphaSink::class])
        ->and(count($this->dispatched))->toBe(1);
});

it('lets the first sink that keeps the event tell a replay: the rest are not called and nothing is dispatched', function (): void {
    ScriptedSink::$script = [AlphaSink::class => SinkResult::Skipped, BetaSink::class => SinkResult::Replayed];
    $recorder = sinkContractRecorder(['test.alpha' => [AlphaSink::class, 0], 'test.beta' => [BetaSink::class, 0], 'test.gamma' => [GammaSink::class, 0]]);

    sinkContractRecord($recorder, sinkContractEvent(DriveTestHelpers::model($this->folder->id)));

    expect(ScriptedSink::$log)->toBe([AlphaSink::class, BetaSink::class])
        ->and(count($this->dispatched))->toBe(0);
});

it('rolls back an occurrence that a later sink reports as a replay', function (): void {
    ScriptedSink::$script = [BetaSink::class => SinkResult::Replayed];
    $recorder = sinkContractRecorder(['test.alpha' => [AlphaSink::class, 0], 'test.beta' => [BetaSink::class, 0]]);
    $folder = DriveTestHelpers::model($this->folder->id);

    expect(fn () => app(VaulterDatabase::class)->transaction(static function () use ($recorder, $folder): void {
        $folder->forceFill(['name' => 'renamed'])->save();
        $recorder->record(sinkContractEvent($folder));
    }))->toThrow(LogicException::class, 'stored only partially')
        ->and(DriveTestHelpers::model($this->folder->id)->name)->toBe('alpha')
        ->and(count($this->dispatched))->toBe(0);
});

it('rolls the operation back when a sink throws', function (): void {
    ScriptedSink::$script = [BetaSink::class => 'throw'];
    $recorder = sinkContractRecorder(['test.alpha' => [AlphaSink::class, 0], 'test.beta' => [BetaSink::class, 0], 'test.gamma' => [GammaSink::class, 0]]);
    $folder = DriveTestHelpers::model($this->folder->id);

    expect(fn () => app(VaulterDatabase::class)->transaction(static function () use ($recorder, $folder): void {
        $folder->forceFill(['name' => 'renamed'])->save();
        $recorder->record(sinkContractEvent($folder));
    }))->toThrow(RuntimeException::class, 'sink failed')
        ->and(ScriptedSink::$log)->toBe([AlphaSink::class, BetaSink::class])
        ->and(DriveTestHelpers::model($this->folder->id)->name)->toBe('alpha')
        ->and(count($this->dispatched))->toBe(0);
});

it('dispatches an event no sink keeps', function (): void {
    ScriptedSink::$script = [AlphaSink::class => SinkResult::Skipped];
    $recorder = sinkContractRecorder(['test.alpha' => [AlphaSink::class, 0]]);

    sinkContractRecord($recorder, sinkContractEvent(DriveTestHelpers::model($this->folder->id)));

    expect(count($this->dispatched))->toBe(1);
});

it('gives a sink the connection of the operation only inside its transaction', function (): void {
    ScriptedSink::$script = [AlphaSink::class => 'connection'];
    $recorder = sinkContractRecorder(['test.alpha' => [AlphaSink::class, 0]]);
    sinkContractRecord($recorder, sinkContractEvent(DriveTestHelpers::model($this->folder->id)));

    expect(fn () => (new SinkWriter(app(VaulterDatabase::class)))->connection())
        ->toThrow(LogicException::class, 'only inside the transaction');
});

it('contributes the drive activity feed and change journal as sinks, in that order', function (): void {
    $ids = array_map(static fn (SlotContribution $c): string => $c->id, app(Kernel::class)->extensions()->contributions(EventSink::class));

    expect($ids)->toBe(['drive.activity', 'drive.changes']);
});

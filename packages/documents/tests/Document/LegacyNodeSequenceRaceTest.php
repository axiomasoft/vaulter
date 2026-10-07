<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Collaboration\PersistCollaborationStateAction;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Data\Collaboration\PersistCollaborationStateInput;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Events\Nodes\NodeRenamed;
use Vaulter\Exceptions\Nodes\StaleNodeException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\Tests\Concurrency\RaceOutcome;

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
});

function legacyRaceDocument(): Node
{
    $drive = DocumentTestHelpers::drive();
    $node = app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Race document',
        content: DocumentTestHelpers::content('initial'),
        parent: $drive->root(),
    ));
    Node::query()->whereKey($node->id)->update(['sequence' => 7]);

    return $node->refresh();
}

/** @return array{kind: string, pid: int, events: int, status: ?int} */
function legacyRaceWrite(Closure $write): array
{
    $pid = (int) app(VaulterDatabase::class)->connection()->selectOne('select pg_backend_pid() as pid')->pid;
    $events = 0;
    Event::listen([DocumentContentUpdated::class, NodeRenamed::class], static function () use (&$events): void {
        $events++;
    });

    try {
        $write();

        return ['kind' => 'committed', 'pid' => $pid, 'events' => $events, 'status' => null];
    } catch (StaleNodeException $exception) {
        return ['kind' => 'stale', 'pid' => $pid, 'events' => $events, 'status' => $exception->httpStatus()];
    }
}

it('SEC-LEGACY-RACE lets a content write and a rename with their own validators both commit (D35)', function (): void {
    $node = legacyRaceDocument();
    $drive = $node->storage;
    $outcomes = ForkRace::run([
        static fn (): array => legacyRaceWrite(static fn () => app(UpdateDocumentContentAction::class)->execute(
            new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('race edit'), expectedStateVersion: 1),
        )),
        static fn (): array => legacyRaceWrite(static fn () => Vaulter::drive($drive)->asSystem('legacy sequence race')
            ->nodes()->rename(node: $node, name: 'race rename', expectedSequence: 7)),
    ], connections: [app(VaulterDatabase::class)->connection()->getName()]);

    foreach ($outcomes as $outcome) {
        expect($outcome->ok)->toBeTrue($outcome->message ?? '');
    }
    $results = array_map(static fn (RaceOutcome $outcome): array => $outcome->value, $outcomes);
    $document = DocumentTestHelpers::document($node);
    expect(array_column($results, 'kind'))->toBe(['committed', 'committed'])
        ->and(count(array_unique(array_column($results, 'pid'))))->toBe(2)
        ->and(array_sum(array_column($results, 'events')))->toBe(2)
        // Only the rename moves the node validator; only the edit moves the content one.
        ->and($node->refresh()->sequence)->toBe(8)
        ->and($node->name)->toBe('race rename')
        ->and($document->state_version)->toBe(2)
        ->and($document->tipTapContent()->toArray())->toBe(DocumentTestHelpers::content('race edit')->toArray())
        ->and($drive->refresh()->used_bytes)->toBe(0);
});

it('SEC-LEGACY-RACE keeps the installed Yjs writer off the rename validator (D35)', function (): void {
    $node = legacyRaceDocument();
    $drive = $node->storage;
    $outcomes = ForkRace::run([
        static fn (): array => legacyRaceWrite(static fn () => app(PersistCollaborationStateAction::class)->execute(
            new PersistCollaborationStateInput(node: $node, yjsState: "actual\0Yjs\0snapshot"),
        )),
        static fn (): array => legacyRaceWrite(static fn () => Vaulter::drive($drive)->asSystem('legacy Yjs race')
            ->nodes()->rename(node: $node, name: 'race rename', expectedSequence: 7)),
    ], connections: [app(VaulterDatabase::class)->connection()->getName()]);

    foreach ($outcomes as $outcome) {
        expect($outcome->ok)->toBeTrue($outcome->message ?? '');
    }
    $results = array_map(static fn (RaceOutcome $outcome): array => $outcome->value, $outcomes);
    $document = DocumentTestHelpers::document($node);
    expect(array_column($results, 'kind'))->toBe(['committed', 'committed'])
        ->and(count(array_unique(array_column($results, 'pid'))))->toBe(2)
        ->and(array_sum(array_column($results, 'events')))->toBe(2)
        ->and($node->refresh()->sequence)->toBe(8)
        ->and($node->name)->toBe('race rename')
        ->and($document->yjs_state)->toBe("actual\0Yjs\0snapshot")
        ->and($document->state_version)->toBe(2)
        ->and($drive->refresh()->used_bytes)->toBe(0);
});

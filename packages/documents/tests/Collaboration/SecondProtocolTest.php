<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Exceptions\DocumentStatePendingException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\State\CheckpointPublication;
use Vaulter\Documents\State\CollaborationStateBinding;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Documents\State\PendingStateDrainer;
use Vaulter\Documents\State\PendingStateGuard;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers as Docs;
use Vaulter\Documents\Tests\Fixtures\Collaboration\AcmeDriver;
use Vaulter\Documents\Tests\Fixtures\Collaboration\AcmeFixtureLog;
use Vaulter\Documents\Tests\Fixtures\Collaboration\AcmePendingStateDrainer;
use Vaulter\Documents\Tests\Fixtures\Collaboration\WithAcme;
use Vaulter\Documents\Tests\Fixtures\State\FakePendingStateDrainer;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Kernel;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\SlotContribution;

uses(OperationsTestCase::class, DatabaseMigrations::class, WithAcme::class);

function disableFixtureAdapter(): void
{
    // A new sealed graph models the next worker boot with only the adapter absent;
    // the independently installed type and stored rows remain. No live graph mutation.
    $old = app(Kernel::class)->compiled();
    $contributions = $old->contributions;
    foreach ([CollaborationProtocolDriver::class, PendingStateDrainer::class] as $slot) {
        $contributions[$slot] = array_values(array_filter($contributions[$slot] ?? [], fn (SlotContribution $entry): bool => $entry->key !== 'acme-ot'));
    }
    $kernel = new Kernel(app());
    $kernel->seal(new CompiledModules(...array_replace(get_object_vars($old), ['contributions' => $contributions])));
    app()->instance(Kernel::class, $kernel);
}

beforeEach(function (): void {
    expect(app(Kernel::class)->reasons())->toBe([]);
    $this->drive = Docs::drive();
    $this->node = Documents::in($this->drive)->create('Acme lifecycle', type: 'acme/ot');
    AccessTestHelpers::grant($this->node, RoleRegistry::OWNER, subjectId: 5);
    $this->documents = Documents::in($this->drive->actingAs(AccessTestHelpers::user(5)));
    $this->log = app(AcmeFixtureLog::class);
    $this->session = $this->documents->collaborationSession($this->node);
});

it('runs ACK checkpoint drain transition restore and fences the previous credential', function (): void {
    $initial = $this->documents->content($this->node);
    expect($initial->collabProtocol)->toBe('acme-ot')->and($initial->collabStateFormat)->toBe('acme-ot-v1')->and($initial->schemaVersion)->toBe(1);
    $one = ['columns' => [['title' => 'ACK 1', 'cards' => []]]];
    $marker = $this->log->append($this->session->token, $one);
    expect($marker->throughSeq)->toBe(1)->and($this->log->projection($marker))->toBe($one);
    app(VaulterDatabase::class)->transaction(function () use ($marker, $one): void {
        $state = app(CollaborativeState::class);
        $lock = $state->lock($this->node->id);
        $wrong = new CollaborationStateBinding('foreign', 'foreign-v1', 1, $lock->generation);
        expect($state->publishCheckpoint($lock, new CheckpointPublication($wrong, $lock->stateVersion, 1, null, $one))->published)->toBeFalse();
        $result = $state->publishCheckpoint($lock, new CheckpointPublication($marker->binding, $lock->stateVersion, 1, null, $one));
        expect($result->published)->toBeTrue()->and($result->pending)->toBeNull();
    });
    $two = ['columns' => [['title' => 'ACK 2', 'cards' => ['a', 'b']]]];
    $this->log->append($this->session->token, $two);
    expect($this->documents->content($this->node)->content->toArray())->toBe($two);
    expect(app(AcmePendingStateDrainer::class)->calls[0]->throughSeq)->toBe(2);
    $settled = $this->documents->content($this->node);
    $changed = $this->documents->update($this->node, ['columns' => []]);
    expect($changed->epoch)->toBe($settled->epoch + 1);
    // The ordinary replacement captures the accepted old projection before reseeding.
    $source = Document::query()->where('node_id', $this->node->id)->firstOrFail()->revisions()->get()
        ->first(fn ($revision): bool => $revision->content === $two);
    expect($source)->not->toBeNull();
    $this->documents->restore($this->node, $source->getKey());
    $restored = $this->documents->content($this->node);
    expect($restored->epoch)->toBe($changed->epoch + 1)->and($restored->content->toArray())->toBe($two)
        ->and($restored->collabProtocol)->toBe('acme-ot')->and($restored->collabStateFormat)->toBe('acme-ot-v1')
        ->and($restored->schemaVersion)->toBe(1)->and($restored->collabStateSize)->toBeNull();
    $next = $this->documents->collaborationSession($this->node);
    expect($next->generation)->toBe($restored->epoch);
    $records = $this->log->records();
    expect(fn () => $this->log->append($this->session->token, ['columns' => ['lost']]))->toThrow(InvalidArgumentException::class, 'Stale credential binding.');
    expect($this->log->records())->toBe($records);
    expect($this->log->append($next->token, ['columns' => ['new']])->generation)->toBe($next->generation);
});

it('executes settled ordinary JSON editing after adapter disable with a fresh generation', function (): void {
    $this->log->append($this->session->token, ['columns' => ['accepted']]);
    $before = $this->documents->content($this->node);
    disableFixtureAdapter();
    $this->documents = Documents::in($this->drive->actingAs(AccessTestHelpers::user(5)));
    $resolutions = [];
    app()->resolving(function ($object) use (&$resolutions): void {
        if (is_object($object)) {
            $resolutions[] = $object::class;
        }
    });
    $updated = $this->documents->update($this->node, ['columns' => ['local']]);
    expect($updated->epoch)->toBe($before->epoch + 1)->and($updated->stateVersion)->toBe($before->stateVersion + 1)
        ->and($updated->collabProtocol)->toBe('acme-ot')->and($updated->collabStateFormat)->toBe('acme-ot-v1')
        ->and($updated->schemaVersion)->toBe(1)->and($updated->content->toArray())->toBe(['columns' => ['local']]);
    expect($resolutions)->not->toContain(AcmeDriver::class, AcmePendingStateDrainer::class);
    expect(fn () => $this->documents->collaborationSession($this->node))->toThrow(CollaborationUnavailableException::class);
    expect(fn () => $this->log->append($this->session->token, ['columns' => []]))->toThrow(InvalidArgumentException::class, 'Stale credential binding.');
});

it('refuses unresolved pending after adapter disable and retains every ACKed byte', function (): void {
    $this->log->append($this->session->token, ['columns' => ['ACKed']]);
    $records = $this->log->records();
    $before = Document::query()->where('node_id', $this->node->id)->firstOrFail()->getAttributes();
    disableFixtureAdapter();
    $this->documents = Documents::in($this->drive->actingAs(AccessTestHelpers::user(5)));
    expect(fn () => $this->documents->update($this->node, ['columns' => []]))->toThrow(DocumentStatePendingException::class);
    expect(Document::query()->where('node_id', $this->node->id)->firstOrFail()->getAttributes())->toBe($before)
        ->and($this->log->records())->toBe($records);
});

it('dispatches a mixed pending page by stored protocol and preserves unknown markers', function (): void {
    $this->log->append($this->session->token, ['columns' => ['ACKed']]);
    $yjs = Documents::in($this->drive)->create('Yjs-like');
    $unknown = Documents::in($this->drive)->create('Unknown', type: 'acme/ot');
    // A stored protocol from an uninstalled adapter, not a runtime product branch.
    Document::query()->where('node_id', $unknown->id)->update(['collab_protocol' => 'uninstalled']);
    app(VaulterDatabase::class)->transaction(function () use ($yjs, $unknown): void {
        $state = app(CollaborativeState::class);
        foreach ([$yjs, $unknown] as $node) {
            $locked = $state->lock($node->id);
            $state->markPending($locked, $locked->binding, 1);
        }
        expect($state->pendingDocuments())->toHaveCount(3)
            ->and($state->pendingDocuments(protocol: 'acme-ot'))->toHaveCount(1);
    });
    FakePendingStateDrainer::reset();
    FakePendingStateDrainer::$behaviour = function ($marker): void {
        app(VaulterDatabase::class)->transaction(function () use ($marker): void {
            $state = app(CollaborativeState::class);
            $locked = $state->lock($marker->nodeId);
            $state->publishCheckpoint($locked, new CheckpointPublication($marker->binding, $locked->stateVersion, $marker->throughSeq, 'fixture-yjs-bytes', $locked->content));
        });
    };
    try {
        app(PendingStateGuard::class)->drainTrashed($this->drive->model()->getKey(), wholeDrive: true);
        expect(app(AcmePendingStateDrainer::class)->calls)->toHaveCount(1);
        expect(FakePendingStateDrainer::$calls)->toHaveCount(1);
        expect(FakePendingStateDrainer::$calls[0]['marker']->nodeId)->toBe($yjs->id);
        $remaining = app(VaulterDatabase::class)->transaction(fn () => app(CollaborativeState::class)->pendingDocuments());
        expect($remaining)->toHaveCount(1)->and($remaining[0]->nodeId)->toBe($unknown->id);
    } finally {
        FakePendingStateDrainer::reset();
    }
});

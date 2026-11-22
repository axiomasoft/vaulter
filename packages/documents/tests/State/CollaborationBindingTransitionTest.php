<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Types\DocumentFeatures;
use Vaulter\Documents\Data\Types\DocumentViewer;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Events\Revisions\DocumentRevisionCreated;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Exceptions\DocumentStatePendingException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\State\CheckpointPublication;
use Vaulter\Documents\State\CollaborationStateBinding;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Documents\State\CollaborativeStateTransition;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments;
use Vaulter\Documents\Tests\Fixtures\StateWrites;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Documents\Types\SpreadsheetDocumentType;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Kernel;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Access\ActorRef;

it('compares every stored binding component', function (): void {
    $binding = new CollaborationStateBinding('yjs', 'yjs-update-v1', 1, 7);
    expect($binding->sameAs(new CollaborationStateBinding('yjs', 'yjs-update-v1', 1, 7)))->toBeTrue();
    foreach ([['acme-ot', 'yjs-update-v1', 1, 7], ['yjs', 'other', 1, 7], ['yjs', 'yjs-update-v1', 2, 7], ['yjs', 'yjs-update-v1', 1, 8]] as $values) {
        expect($binding->sameAs(new CollaborationStateBinding(...$values)))->toBeFalse();
    }
});

it('fences identical JSON transitions and retains explicit user attribution', function (): void {
    $node = GrantedDocuments::document(level: null);
    $actor = new ActorRef('staff', '42');
    $before = GrantedDocuments::aggregate($node);
    $outcome = app(VaulterDatabase::class)->transaction(function () use ($node, $actor) {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($node->getKey());

        return $state->transition($locked, new CollaborativeStateTransition(
            expectedBinding: $locked->binding,
            expectedStateVersion: $locked->stateVersion,
            documentType: $locked->type,
            schemaVersion: $locked->schemaVersion,
            protocol: $locked->binding?->protocol,
            stateFormat: $locked->binding?->stateFormat,
            content: OpaqueContent::fromArray($locked->content),
            collabState: null,
            actor: $actor,
            correlationId: 'transition-42',
        ));
    });
    $after = GrantedDocuments::aggregate($node);
    expect($outcome->published)->toBeTrue()
        ->and($after->collab_epoch)->toBe($before->collab_epoch + 1)
        ->and($after->state_version)->toBe($before->state_version + 1)
        ->and($after->payload?->content_hash)->toBe($before->payload?->content_hash)
        ->and((string) $after->updated_by)->toBe('42')
        ->and((string) $after->currentRevision->created_by)->toBe('42')
        ->and($after->currentRevision->type)->toBe($before->type);
});

/** A dedicated stateless protocol fixture; its version change models installing the next host codec. */
final class P36StatelessType implements DocumentType, SupportsCollaboration
{
    public int $version = 1;

    public function key(): string
    {
        return 'acme/stateless';
    }

    public function label(): string
    {
        return 'Stateless board';
    }

    public function schemaVersion(): int
    {
        return $this->version;
    }

    public function codec(): DocumentCodec
    {
        return new OpaqueJsonCodec;
    }

    public function defaultContent(): DocumentContent
    {
        return OpaqueContent::fromArray(['columns' => []]);
    }

    public function viewer(): DocumentViewer
    {
        return new DocumentViewer('acme/board');
    }

    public function features(): DocumentFeatures
    {
        return new DocumentFeatures(editable: true, previewable: true, downloadable: false);
    }

    public function collaborationProtocol(): string
    {
        return 'acme-ot';
    }

    public function collaborationStateFormat(): string
    {
        return 'acme-json-v1';
    }

    public function projectFromState(string $state): ?DocumentContent
    {
        return null;
    }
}

function p36Rows(): array
{
    return array_map(static fn (string $table) => DB::table($table)->get()->toJson(), ['v_nodes', 'v_documents', 'v_document_revisions']);
}

it('propagates the authorized user or authenticated service actor and correlation to every transition effect', function (bool $service): void {
    $node = GrantedDocuments::document(level: RoleRegistry::WRITER, actorId: 42);
    $correlation = $service ? 'authenticated-service-transition' : 'authorized-user-transition';
    if ($service) {
        // The host authenticates its converter service before constructing an internal system context.
        $authenticate = static function (string $signature) use ($node, $correlation): DriveContext {
            if (!hash_equals(hash_hmac('sha256', 'convert:'.$node->getKey(), 'fixture-service-secret'), $signature)) {
                throw new RuntimeException('Unauthenticated converter');
            }

            return DriveContext::for($node->storage()->firstOrFail(), Actor::system('authenticated converter'), $correlation);
        };
        expect(fn (): DriveContext => $authenticate('invalid'))->toThrow(RuntimeException::class);
        $context = $authenticate(hash_hmac('sha256', 'convert:'.$node->getKey(), 'fixture-service-secret'));
    } else {
        $context = DriveContext::for($node->storage()->firstOrFail(), Actor::user(AccessTestHelpers::user(42)), $correlation);
    }
    app(OperationGuard::class)->authorize($context, Ability::Edit, $node, OperationKind::Mutation);
    $events = [];
    foreach ([DocumentContentUpdated::class, DocumentRevisionCreated::class] as $event) {
        app('events')->listen($event, function ($event) use (&$events): void {
            $events[] = $event;
        });
    }
    app(VaulterDatabase::class)->transaction(function () use ($node, $context, &$events): void {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($node->getKey());
        $outcome = $state->transition($locked, new CollaborativeStateTransition($locked->binding, $locked->stateVersion, $locked->type, $locked->schemaVersion, $locked->binding?->protocol, $locked->binding?->stateFormat, OpaqueContent::fromArray($locked->content), null, $context->actor->ref(), $context->correlationId));
        expect($outcome->published)->toBeTrue()->and($events)->toBe([]);
    });
    $after = GrantedDocuments::aggregate($node);
    $expected = $service ? null : '42';
    expect($after->updated_by === null ? null : (string) $after->updated_by)->toBe($expected)
        ->and($node->fresh()->updated_by === null ? null : (string) $node->fresh()->updated_by)->toBe($expected)
        ->and($after->currentRevision->created_by === null ? null : (string) $after->currentRevision->created_by)->toBe($expected)
        ->and($events)->toHaveCount(2);
    foreach ($events as $event) {
        expect($event->actor)->toEqual($context->actor->ref())->and($event->correlationId)->toBe($correlation);
    }
})->with(['authorized user' => false, 'authenticated service' => true]);

it('emits no events and preserves every aggregate row when transition CAS or pending recheck loses', function (string $conflict): void {
    $node = GrantedDocuments::document(level: null);
    $prepared = app(VaulterDatabase::class)->transaction(fn () => app(CollaborativeState::class)->lock($node->getKey()));
    if ($conflict === 'pending') {
        app(VaulterDatabase::class)->transaction(function () use ($node): void {
            $state = app(CollaborativeState::class);
            $lock = $state->lock($node->getKey());
            $state->markPending($lock, $lock->binding, 9);
        });
    }
    $events = [];
    foreach ([DocumentContentUpdated::class, DocumentRevisionCreated::class] as $event) {
        app('events')->listen($event, function ($event) use (&$events): void {
            $events[] = $event;
        });
    }
    $before = p36Rows();
    app(VaulterDatabase::class)->transaction(function () use ($node, $prepared, $conflict): void {
        $state = app(CollaborativeState::class);
        $lock = $state->lock($node->getKey());
        $expected = $conflict === 'binding' ? new CollaborationStateBinding('other', 'other-format', 1, $prepared->generation) : $prepared->binding;
        $outcome = $state->transition($lock, new CollaborativeStateTransition($expected, $prepared->stateVersion + ($conflict === 'version' ? 1 : 0), $prepared->type, $prepared->schemaVersion, $prepared->binding?->protocol, $prepared->binding?->stateFormat, OpaqueContent::fromArray($prepared->content), null, new ActorRef(ActorRef::SYSTEM_TYPE, null, 'authenticated converter'), 'rejected-transition'));
        expect($outcome->published)->toBeFalse();
    });
    expect(p36Rows())->toBe($before)->and($events)->toBe([]);
})->with(['binding', 'version', 'pending']);

it('writes bound snapshots and keeps their generation without an active driver', function (bool $identical, bool $withCheckpoint): void {
    expect(app(Kernel::class)->isActive('collaboration'))->toBeFalse();
    $drive = DocumentTestHelpers::drive();
    $documents = Documents::in($drive);
    $node = $documents->create('No driver');
    $model = Node::query()->findOrFail($node->id);
    $checkpoint = "working\0checkpoint";
    if ($withCheckpoint) {
        StateWrites::yjs($model, $checkpoint, seed: true);
    }
    $before = $documents->content($node);
    expect($before->collabStateSize)->toBe($withCheckpoint ? strlen($checkpoint) : null);
    $json = $identical ? $before->content->toArray() : GrantedDocuments::tiptap('Local edit');
    $updated = $documents->update($node, $json);
    expect($updated->epoch)->toBe($before->epoch)->and($updated->stateVersion)->toBe($before->stateVersion + 1)
        ->and($updated->collabProtocol)->toBe('yjs')->and($updated->collabStateFormat)->toBe('yjs-update-v1')
        ->and($updated->schemaVersion)->toBe($before->schemaVersion)->and($updated->collabStateSize)->toBeNull()
        ->and(GrantedDocuments::aggregate($model)->syncState?->collab_state)->toBeNull();

    if ($withCheckpoint) {
        $saved = GrantedDocuments::aggregate($model)->revisions()->where('state_version', $before->stateVersion)->sole();
        expect($saved->syncState?->collab_state)->toBe($checkpoint);
    }
})->with(['changed JSON' => [false, false], 'identical JSON' => [true, false], 'identical JSON with binary state' => [true, true]]);

it('keeps pending data when ordinary Documents update has no active drainer', function (): void {
    $drive = DocumentTestHelpers::drive();
    $documents = Documents::in($drive);
    $node = $documents->create('Pending without driver');
    app(VaulterDatabase::class)->transaction(function () use ($node): void {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($node->id);
        $state->markPending($locked, $locked->binding, 3);
    });
    $before = p36Rows();
    expect(fn () => $documents->update($node, GrantedDocuments::tiptap('Would lose edits')))->toThrow(DocumentStatePendingException::class);
    expect(p36Rows())->toBe($before);
});

it('preserves Acme stateless null state through checkpoint transition restore and independent copy', function (): void {
    $type = new P36StatelessType;
    $registry = new DocumentTypeRegistry(app());
    foreach ([new RichTextDocumentType, new MarkdownDocumentType, $type] as $registered) {
        $registry->register($registered);
    }
    $registry->freeze();
    app()->instance(DocumentTypeRegistry::class, $registry);
    $drive = DocumentTestHelpers::drive();
    $documents = Documents::in($drive);
    $snapshot = $documents->create('Stateless', type: $type->key());
    $node = Node::query()->findOrFail($snapshot->id);
    $source = GrantedDocuments::aggregate($node)->currentRevision;
    $sourceBytes = $source->getAttributes();
    app(VaulterDatabase::class)->transaction(function () use ($node): void {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($node->getKey());
        $state->markPending($locked, $locked->binding, 1);
        $wrong = new CollaborationStateBinding('yjs', 'yjs-update-v1', 1, $locked->generation);
        $refused = $state->publishCheckpoint($locked, new CheckpointPublication($wrong, $locked->stateVersion, 1, null, $locked->content));
        expect($refused->published)->toBeFalse()->and($refused->pending->throughSeq)->toBe(1);
        $published = $state->publishCheckpoint($locked, new CheckpointPublication($locked->binding, $locked->stateVersion, 1, null, ['columns' => [['name' => 'accepted']]]));
        expect($published->published)->toBeTrue()->and($published->pending)->toBeNull();
    });
    $prepared = app(VaulterDatabase::class)->transaction(fn () => app(CollaborativeState::class)->lock($node->getKey()));
    $type->version = 2;
    // The host must explicitly supply its converter. Its refusal happens before locks and changes no source.
    $prepare = static function (?Closure $converter) use ($prepared) {
        if ($converter === null) {
            throw new CollaborationUnavailableException('converter_unavailable', $prepared->type);
        }

        return $converter($prepared->content);
    };
    $before = p36Rows();
    expect(fn () => $prepare(null))->toThrow(CollaborationUnavailableException::class);
    expect(p36Rows())->toBe($before);
    $converted = $prepare(static fn (array $content): OpaqueContent => OpaqueContent::fromArray($content));
    app(VaulterDatabase::class)->transaction(function () use ($node, $prepared, $converted): void {
        $state = app(CollaborativeState::class);
        $lock = $state->lock($node->getKey());
        expect($state->transition($lock, new CollaborativeStateTransition($prepared->binding, $prepared->stateVersion, $prepared->type, 2, 'acme-ot', 'acme-json-v1', $converted, null, new ActorRef(ActorRef::SYSTEM_TYPE, null, 'host converter'), 'acme-schema2'))->published)->toBeTrue();
    });
    $beforeRestore = GrantedDocuments::aggregate($node);
    $documents->restore($node->getKey(), $source->getKey());
    $restored = GrantedDocuments::aggregate($node);
    expect($restored->collab_epoch)->toBe($beforeRestore->collab_epoch + 1)->and($restored->schema_version)->toBe(1)->and($restored->syncState?->collab_protocol)->toBe('acme-ot')->and($restored->syncState?->collab_state_format)->toBe('acme-json-v1')->and($restored->syncState?->collab_state)->toBeNull();
    $copySnapshot = $drive->nodes()->copy($node->getKey(), options: new CopyOptions(name: 'Stateless copy'));
    $copy = Document::query()->where('node_id', $copySnapshot->id)->firstOrFail();
    expect($copy->collab_epoch)->toBe(0)->and($copy->syncState?->pending_generation)->toBeNull()->and($copy->syncState?->collab_protocol)->toBe('acme-ot')->and($copy->syncState?->collab_state_format)->toBe('acme-json-v1')->and($copy->syncState?->collab_state)->toBeNull()->and($copy->schema_version)->toBe(1)
        ->and($source->fresh()->getAttributes())->toBe($sourceBytes);
});

it('reads an immutable revision through its source type after a prepared cross-type transition', function (): void {
    $registry = new DocumentTypeRegistry(app());
    foreach ([new RichTextDocumentType, new P36StatelessType] as $registered) {
        $registry->register($registered);
    }
    $registry->freeze();
    app()->instance(DocumentTypeRegistry::class, $registry);
    $drive = DocumentTestHelpers::drive();
    $documents = Documents::in($drive);
    $node = $documents->create('Convert type');
    $source = $documents->snapshot($node);
    $old = $documents->content($node);
    app(VaulterDatabase::class)->transaction(function () use ($node): void {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($node->id);
        $target = OpaqueContent::fromArray(['original' => $locked->content]);
        expect($state->transition($locked, new CollaborativeStateTransition($locked->binding, $locked->stateVersion, 'acme/stateless', 1, 'acme-ot', 'acme-json-v1', $target, null, new ActorRef(ActorRef::SYSTEM_TYPE, null, 'host converter'), 'cross-type'))->published)->toBeTrue();
    });
    $historical = $documents->content($node, $source);
    expect($historical->type)->toBe('vaulter/rich-text')->and($historical->schemaVersion)->toBe($old->schemaVersion)
        ->and($historical->content->toArray())->toBe($old->content->toArray())->and($historical->collabProtocol)->toBe('yjs')->and($historical->collabStateFormat)->toBe('yjs-update-v1');
});

it('returns unpublished without rows or events when a prepared checkpoint loses to a noncollaborative transition', function (): void {
    $registry = new DocumentTypeRegistry(app());
    foreach ([new RichTextDocumentType, new MarkdownDocumentType, new SpreadsheetDocumentType] as $type) {
        $registry->register($type);
    }
    $registry->freeze();
    app()->instance(DocumentTypeRegistry::class, $registry);
    $node = GrantedDocuments::document(level: null);
    $publication = app(VaulterDatabase::class)->transaction(function () use ($node): CheckpointPublication {
        $locked = app(CollaborativeState::class)->lock($node->getKey());

        return new CheckpointPublication($locked->binding, $locked->stateVersion, 1, 'old-bytes', $locked->content);
    });
    app(VaulterDatabase::class)->transaction(function () use ($node): void {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($node->getKey());
        expect($state->transition($locked, new CollaborativeStateTransition($locked->binding, $locked->stateVersion,
            'vaulter/spreadsheet', 1, null, null, OpaqueContent::fromArray(['sheets' => []]), null,
            new ActorRef('staff', '42'), 'noncollab-race'))->published)->toBeTrue();
    });
    $before = p36Rows();
    $events = [];
    app('events')->listen(DocumentContentUpdated::class, function ($event) use (&$events): void {
        $events[] = $event;
    });
    $outcome = app(VaulterDatabase::class)->transaction(function () use ($node, $publication) {
        $state = app(CollaborativeState::class);

        return $state->publishCheckpoint($state->lock($node->getKey()), $publication);
    });
    expect($outcome->published)->toBeFalse()->and(p36Rows())->toBe($before)->and($events)->toBe([]);
});

it('publishes an ACKed historical schema checkpoint without upgrading its stored projection', function (): void {
    $type = new P36StatelessType;
    $registry = new DocumentTypeRegistry(app());
    foreach ([new RichTextDocumentType, new MarkdownDocumentType, $type] as $item) {
        $registry->register($item);
    }
    $registry->freeze();
    app()->instance(DocumentTypeRegistry::class, $registry);
    $node = GrantedDocuments::document(level: null);
    app(VaulterDatabase::class)->transaction(function () use ($node): void {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($node->getKey());
        $state->transition($locked, new CollaborativeStateTransition($locked->binding, $locked->stateVersion, 'acme/stateless', 1, 'acme-ot', 'acme-json-v1', OpaqueContent::fromArray(['columns' => []]), null, new ActorRef('staff', '42'), 'historical-schema'));
        $locked = $state->lock($node->getKey());
        $state->markPending($locked, $locked->binding, 1);
    });
    $before = GrantedDocuments::aggregate($node);
    $type->version = 2;
    $result = app(VaulterDatabase::class)->transaction(function () use ($node) {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($node->getKey());

        return $state->publishCheckpoint($locked, new CheckpointPublication($locked->binding, $locked->stateVersion, 1, null, ['columns' => ['accepted']]));
    });
    $after = GrantedDocuments::aggregate($node);
    expect($result->published)->toBeTrue()->and($result->pending)->toBeNull()->and($after->schema_version)->toBe(1)->and($after->collab_epoch)->toBe($before->collab_epoch)->and($after->syncState?->collab_state)->toBeNull()->and($after->payload?->content)->toBe(['columns' => ['accepted']]);
});

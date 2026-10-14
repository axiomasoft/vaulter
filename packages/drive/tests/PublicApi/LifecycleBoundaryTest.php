<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Content\NodeContentLifecycleRegistry;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\CopyResult;
use Vaulter\Data\Drive\RestoreOptions;
use Vaulter\Data\Drive\RevisionOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\FileContentLifecycle;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Drive\Providers\BindingsRegistrar;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Content\ContentQuarantinedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\RestoreDestinationRequiredException;
use Vaulter\Exceptions\Nodes\UnsupportedNodeKindException;
use Vaulter\Exceptions\Registry\DuplicateNodeContentLifecycleException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;

/** Injects a failure after real CAS edge detach, so transaction rollback is observable. */
final readonly class LifecycleBoundaryFailingFile implements NodeContentLifecycle
{
    public function __construct(
        private FileContentLifecycle $actual,
        private int|string $failedNodeId,
        private RuntimeException $failure,
    ) {}

    public function kind(): NodeKind
    {
        return NodeKind::File;
    }

    public function copy(OperationContext $context, Node $source, Node $copy): CopyResult
    {
        return $this->actual->copy($context, $source, $copy);
    }

    public function purge(OperationContext $context, Node $node): void
    {
        $this->actual->purge($context, $node);
        if ((string) $node->id === (string) $this->failedNodeId) {
            throw $this->failure;
        }
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    $this->system = Vaulter::drive(DriveTestHelpers::host())->asSystem('lifecycle acceptance fixture');
});

/** Database state includes side effects, not just the returned node. */
function lifecycleBoundaryState(): array
{
    $db = app(VaulterDatabase::class)->connection();

    return array_map(static fn (string $table): array => $db->table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(), [
        'v_nodes', 'v_files', 'v_file_revisions', 'v_file_revision_blobs', 'v_blobs', 'v_drives', 'v_activity', 'v_outbox',
    ]);
}

function lifecycleBoundaryEdges(FileRevision $revision): array
{
    return $revision->blobs()->get()->map(fn ($blob): array => [
        'blob_id' => $blob->id,
        'role' => $blob->pivot->role,
        'ordinal' => $blob->pivot->ordinal,
        'offset_bytes' => $blob->pivot->offset_bytes,
        'length_bytes' => $blob->pivot->length_bytes,
    ])->sortBy(fn (array $edge): string => $edge['role'].':'.$edge['ordinal'])->values()->all();
}

function lifecycleBoundaryInjectFailure(Node|NodeSnapshot $node, RuntimeException $failure): void
{
    $registry = new NodeContentLifecycleRegistry;
    $registry->register(new LifecycleBoundaryFailingFile(app(FileContentLifecycle::class), $node->id, $failure));
    app()->instance(NodeContentLifecycleRegistry::class, $registry);
}

it('preflights a mixed subtree before any copy rows quota edges or events', function (NodeKind $unsupported): void {
    $folder = $this->system->folders()->create('mixed');
    $this->system->files()->upload(UploadSource::fromString('valid bytes', 'first.txt'), new UploadOptions(parent: $folder));
    $branch = $this->system->folders()->create('later branch', $folder);
    $leaf = AccessTestHelpers::folder($this->system->model(), $branch, 'unsupported payload');
    app(VaulterDatabase::class)->write(fn () => $leaf->forceFill(['kind' => $unsupported])->save());
    $before = lifecycleBoundaryState();

    expect(fn () => $this->system->nodes()->copy($folder, options: new CopyOptions(name: 'would be partial')))
        ->toThrow(UnsupportedNodeKindException::class)
        ->and(lifecycleBoundaryState())->toBe($before);
})->with([NodeKind::Document, NodeKind::Shortcut]);

it('rejects duplicate lifecycle registrations without replacing the first handler', function (): void {
    $registry = new NodeContentLifecycleRegistry;
    $handler = app(FileContentLifecycle::class);
    $registry->register($handler);

    expect(fn () => $registry->register($handler))->toThrow(DuplicateNodeContentLifecycleException::class)
        ->and($registry->forKind(NodeKind::File))->toBe($handler);

    config()->set('vaulter.content_lifecycles', [FileContentLifecycle::class]);
    app()->forgetInstance(NodeContentLifecycleRegistry::class);
    try {
        expect(fn () => (new BindingsRegistrar)->boot(app()))->toThrow(DuplicateNodeContentLifecycleException::class);
    } finally {
        config()->set('vaulter.content_lifecycles', []);
        app()->forgetInstance(NodeContentLifecycleRegistry::class);
    }
});

it('copies and restores immutable payload identity edge attributes and metadata with correct sequence', function (): void {
    $node = $this->system->files()->upload(UploadSource::fromString('abcdef', 'source.txt'));
    $original = DriveTestHelpers::model($node)->file()->firstOrFail()->currentRevision()->firstOrFail();
    $blob = $original->blob()->firstOrFail();
    app(VaulterDatabase::class)->write(fn () => app(VaulterDatabase::class)->write(fn () => $original->forceFill([
        'manifest_blob_id' => $blob->id,
        'declared_mime_type' => 'text/plain',
        'keep_forever' => true,
    ])->save()));
    DriveTestHelpers::insideOperation(); // the CAS edge is attached by hand, outside an operation
    app(BlobStore::class)->attach(version: $original, blob: $blob, attributes: [
        'role' => 'chunk', 'ordinal' => 7, 'offset_bytes' => 1, 'length_bytes' => 3,
    ]);
    $edges = lifecycleBoundaryEdges($original);
    $originalBeforeCopy = $original->fresh()->getAttributes();
    $copy = $this->system->nodes()->copy($node, options: new CopyOptions(name: 'copied.txt'));
    $copied = DriveTestHelpers::model($copy)->file()->firstOrFail()->currentRevision()->firstOrFail();

    expect($copy->sequence)->toBe(0)->and(DriveTestHelpers::model($node)->sequence)->toBe(0)
        ->and($copied->id)->not->toBe($original->id)
        ->and($copied->blob_id)->toBe($original->blob_id)
        ->and($copied->manifest_blob_id)->toBe($original->manifest_blob_id)
        ->and($copied->declared_mime_type)->toBe('text/plain')
        ->and($copied->keep_forever)->toBeTrue()
        ->and(lifecycleBoundaryEdges($copied))->toBe($edges)
        ->and($original->fresh()->getAttributes())->toBe($originalBeforeCopy)
        ->and($this->system->model()->refresh()->used_bytes)->toBe(12);

    $this->system->files()->addRevision($node, UploadSource::fromString('different newer bytes', 'source.txt'), new RevisionOptions(expectedSequence: 0));
    $originalBeforeRestore = $original->fresh()->getAttributes();
    $restored = FileRevision::query()->findOrFail($this->system->files()->restoreRevision($node, $original->getKey())->id);
    expect($restored->id)->not->toBe($original->id)
        ->and($restored->number)->toBe(3)
        ->and($restored->blob_id)->toBe($original->blob_id)
        ->and($restored->manifest_blob_id)->toBe($original->manifest_blob_id)
        ->and($restored->declared_mime_type)->toBe('text/plain')
        ->and($restored->keep_forever)->toBeTrue()
        // Copy-forward restore remembers its source (D34); a copy does not.
        ->and($restored->restored_from_revision_id)->toBe($original->id)
        ->and($copied->restored_from_revision_id)->toBeNull()
        ->and(lifecycleBoundaryEdges($restored))->toBe($edges)
        ->and($original->fresh()->getAttributes())->toBe($originalBeforeRestore)
        ->and(DriveTestHelpers::model($node)->sequence)->toBe(2)
        ->and(DriveTestHelpers::model($copy)->sequence)->toBe(0)
        // Every retained revision counts (PLAN2.D48): 6 + 6 (copy) + 21 + 6 (restored copy).
        ->and($this->system->model()->refresh()->used_bytes)->toBe(39);
});

it('uses fresh destination admission without inheriting a legacy scan exemption', function (): void {
    $node = $this->system->files()->upload(UploadSource::fromString('legacy admitted bytes', 'legacy.txt'));
    $original = DriveTestHelpers::model($node)->file()->firstOrFail()->currentRevision()->firstOrFail();
    $original->forceFill(['scan_status' => 'not_required'])->save();
    config()->set('vaulter.scan.mode', 'new_content_only');
    config()->set('vaulter.scan.rollout_key', 'legacy-copy');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($this->system->model(), OperationContext::system($this->system->model(), 'lifecycle acceptance fixture'));
    $copy = $this->system->nodes()->copy($node);
    $copied = DriveTestHelpers::model($copy)->file()->firstOrFail()->currentRevision()->firstOrFail();

    expect($copied->scan_status)->toBe('pending')
        ->and($original->fresh()->scan_status)->toBe('not_required');
});

it('refuses known infected immutable bytes before copying changes the database', function (): void {
    $node = $this->system->files()->upload(UploadSource::fromString('infected fixture bytes', 'infected.txt'));
    app(VaulterDatabase::class)->write(fn () => DriveTestHelpers::model($node)->file()->firstOrFail()->currentRevision()->firstOrFail()->forceFill(['scan_status' => 'infected'])->save());
    $before = lifecycleBoundaryState();

    expect(fn () => $this->system->nodes()->copy($node))->toThrow(ContentQuarantinedException::class)
        ->and(lifecycleBoundaryState())->toBe($before);
});

it('purge rolls back the entire physical subtree payload edges quota and sequence on failure', function (): void {
    $folder = $this->system->folders()->create('atomic root');
    $file = $this->system->files()->upload(UploadSource::fromString('survives rollback', 'bad.txt'), new UploadOptions(parent: $folder));
    $this->system->nodes()->trash($folder);
    $failure = new RuntimeException('controlled root failure');
    lifecycleBoundaryInjectFailure($file, $failure);
    Log::spy();
    $before = lifecycleBoundaryState();
    try {
        $this->system->trash()->purge($folder);
        test()->fail('The original purge failure must propagate.');
    } catch (RuntimeException $caught) {
        expect($caught)->toBe($failure);
    }
    expect(lifecycleBoundaryState())->toBe($before);
    Log::shouldHaveReceived('error')->once()->with('Vaulter trash root purge failed.', Mockery::on(
        fn (array $context): bool => (string) $context['root_id'] === (string) $folder->id
            && $context['code'] === 'unexpected_failure' && is_string($context['correlation_id'])
            && !isset($context['exception'], $context['message']),
    ));
});

it('empty completes later roots then rethrows the first original failure with failed root rolled back', function (): void {
    $failed = $this->system->files()->upload(UploadSource::fromString('bad payload', 'bad.txt'));
    $later = $this->system->files()->upload(UploadSource::fromString('later payload', 'later.txt'));
    $this->system->nodes()->trash($failed);
    $this->system->nodes()->trash($later);
    // Distinct timestamps guarantee the failed root is processed first.
    app(VaulterDatabase::class)->write(fn () => DriveTestHelpers::model($failed)->forceFill(['trashed_at' => Carbon::now()->subDays(2), 'trash_root_id' => $failed->id])->save());
    app(VaulterDatabase::class)->write(fn () => DriveTestHelpers::model($later)->forceFill(['trashed_at' => Carbon::now()->subDay(), 'trash_root_id' => $later->id])->save());
    $failedRevision = DriveTestHelpers::model($failed)->file()->firstOrFail()->currentRevision()->firstOrFail();
    $failedEdges = lifecycleBoundaryEdges($failedRevision);
    $failure = new RuntimeException('first original failure');
    lifecycleBoundaryInjectFailure($failed, $failure);
    Log::spy();
    try {
        $this->system->trash()->empty();
        test()->fail('empty must rethrow the original failure after processing later roots.');
    } catch (RuntimeException $caught) {
        expect($caught)->toBe($failure);
    }
    expect(Node::query()->whereKey($failed->id)->exists())->toBeTrue()
        ->and(Node::query()->whereKey($later->id)->exists())->toBeFalse()
        ->and(DriveTestHelpers::model($failed)->sequence)->toBe(1)
        ->and(lifecycleBoundaryEdges($failedRevision->fresh()))->toBe($failedEdges)
        ->and($this->system->model()->refresh()->used_bytes)->toBe(strlen('bad payload'));
    Log::shouldHaveReceived('error')->once();
});

it('empty counts successful subtree roots rather than the number of nodes', function (): void {
    $first = $this->system->folders()->create('first root');
    $this->system->folders()->create('child', $first);
    $second = $this->system->folders()->create('second root');
    $this->system->nodes()->trash($first);
    $this->system->nodes()->trash($second);

    expect($this->system->trash()->empty())->toBe(2)
        ->and(Node::query()->whereIn('id', [$first->id, $second->id])->count())->toBe(0)
        ->and($this->system->trash()->empty())->toBe(0);
});

it('retention purge reports a failed root exits one and still physically commits a later root', function (): void {
    $failed = $this->system->files()->upload(UploadSource::fromString('retained bad bytes', 'bad.txt'));
    $later = $this->system->files()->upload(UploadSource::fromString('successful later bytes', 'later.txt'));
    foreach ([$failed, $later] as $root) {
        $this->system->nodes()->trash($root);
    }
    app(VaulterDatabase::class)->write(fn () => DriveTestHelpers::model($failed)->forceFill(['trashed_at' => Carbon::now()->subDays(42), 'trash_root_id' => $failed->id])->save());
    app(VaulterDatabase::class)->write(fn () => DriveTestHelpers::model($later)->forceFill(['trashed_at' => Carbon::now()->subDays(41), 'trash_root_id' => $later->id])->save());
    $failedRevision = DriveTestHelpers::model($failed)->file()->firstOrFail()->currentRevision()->firstOrFail();
    $edges = lifecycleBoundaryEdges($failedRevision);
    lifecycleBoundaryInjectFailure($failed, new RuntimeException('controlled retention failure'));
    Log::spy();

    $this->artisan('vaulter:trash:purge', ['--days' => 30])->assertExitCode(1);
    expect(Node::query()->whereKey($failed->id)->exists())->toBeTrue()
        ->and(Node::query()->whereKey($later->id)->exists())->toBeFalse()
        ->and(lifecycleBoundaryEdges($failedRevision->fresh()))->toBe($edges)
        ->and($this->system->model()->refresh()->used_bytes)->toBe(strlen('retained bad bytes'));
    Log::shouldHaveReceived('error')->once()->with('Vaulter retention purge root failed.', Mockery::on(
        fn (array $context): bool => (string) $context['root_id'] === (string) $failed->id
            && $context['code'] === 'unexpected_failure' && is_string($context['correlation_id'])
            && !isset($context['exception'], $context['message']),
    ));
});

it('restores to the active original parent without Create when no destination is given', function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    $user = AccessTestHelpers::user(83);
    $parent = $this->system->folders()->create('original');
    $source = $this->system->folders()->create('source', $parent);
    AccessTestHelpers::grant($parent, RoleRegistry::READER, scope: GrantScope::Item, subjectId: 83);
    AccessTestHelpers::grant($source, RoleRegistry::WRITER, scope: GrantScope::Item, subjectId: 83);
    $this->system->nodes()->trash($source);
    $actor = Vaulter::drive($this->system->model())->actingAs($user);

    $restored = $actor->trash()->restore($source);
    expect($restored->parentId)->toBe($parent->id)->and($restored->sequence)->toBe(2);
});

it('authorizes View and Create on the actual fallback destination', function (string $lane): void {
    config()->set('vaulter.access.permission_driver', 'database');
    $user = AccessTestHelpers::user(84);
    $parent = $this->system->folders()->create('original');
    $source = $this->system->folders()->create('source', $parent);
    $fallback = $this->system->folders()->create('explicit fallback');
    AccessTestHelpers::grant($source, RoleRegistry::WRITER, scope: GrantScope::Item, subjectId: 84);
    if ($lane !== 'hidden') {
        AccessTestHelpers::grant($fallback, $lane === 'allowed' ? RoleRegistry::WRITER : RoleRegistry::READER, scope: GrantScope::Item,
            subjectId: 84);
    }
    $this->system->nodes()->trash($source);
    app(VaulterDatabase::class)->write(fn () => DriveTestHelpers::model($parent)->delete());
    $before = lifecycleBoundaryState();
    $actor = Vaulter::drive($this->system->model())->actingAs($user);
    $run = fn () => $actor->trash()->restore($source, new RestoreOptions(destination: $fallback));
    if ($lane === 'allowed') {
        expect($run()->parentId)->toBe($fallback->id)->and(DriveTestHelpers::model($source)->sequence)->toBe(2);
    } else {
        expect($run)->toThrow($lane === 'hidden' ? NodeNotFoundException::class : PermissionDeniedException::class)
            ->and(lifecycleBoundaryState())->toBe($before);
    }
})->with(['hidden', 'missing Create', 'allowed']);

it('repeats public destination authorization when the original parent changes after precheck', function (bool $allowFallback): void {
    config()->set('vaulter.access.permission_driver', 'database');
    $user = AccessTestHelpers::user(85);
    $parent = $this->system->folders()->create('racing original');
    $source = $this->system->folders()->create('source', $parent);
    $root = $this->system->root();
    AccessTestHelpers::grant($source, RoleRegistry::WRITER, scope: GrantScope::Item, subjectId: 85);
    AccessTestHelpers::grant($parent, RoleRegistry::READER, scope: GrantScope::Item, subjectId: 85);
    AccessTestHelpers::grant($root, $allowFallback ? RoleRegistry::WRITER : RoleRegistry::READER, scope: GrantScope::Item, subjectId: 85);
    $this->system->nodes()->trash($source);
    $db = app(VaulterDatabase::class)->connection();
    $baselineLevel = $db->transactionLevel();
    $parentReads = 0;
    $raced = false;
    // Fire outside the Action savepoint after parent resolution + public View precheck.
    Node::retrieved(function (Node $model) use ($parent, $source, $db, $baselineLevel, &$parentReads, &$raced): void {
        if ($raced || $db->transactionLevel() !== $baselineLevel) {
            return;
        }
        if ((string) $model->id === (string) $parent->id) {
            $parentReads++;
        }
        if ((string) $model->id === (string) $source->id && $parentReads >= 2) {
            $raced = true;
            app(VaulterDatabase::class)->write(fn () => Node::query()->whereKey($parent->id)->update(['trashed_at' => Carbon::now(), 'trash_root_id' => DB::raw('id')]));
        }
    });
    $actor = Vaulter::drive($this->system->model())->actingAs($user);
    // The original parent was trashed after the precheck: the restore never falls back to the root.
    expect(fn () => $actor->trash()->restore($source))->toThrow(RestoreDestinationRequiredException::class)
        ->and(DriveTestHelpers::model($source)->trashed_at)->not->toBeNull()
        ->and(DriveTestHelpers::model($source)->sequence)->toBe(1)
        ->and(DriveTestHelpers::model($source)->parent_id)->toEqual($parent->id);
    expect($raced)->toBeTrue()->and(DriveTestHelpers::model($parent)->trashed_at)->not->toBeNull();
})->with([false, true]);

<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\RestoreOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Exceptions\Nodes\InvalidCursorException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;

final class OperationMatrixNoCreateDriver extends DatabasePermissionDriver
{
    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        return false;
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.access.permission_driver', 'database');
    $this->owner = DriveTestHelpers::host();
    $this->system = Vaulter::drive($this->owner)->asSystem('operation matrix fixture');
    $this->user = AccessTestHelpers::user(71);
    $this->actor = Vaulter::drive($this->owner)->actingAs($this->user);
});

function operationMatrixGrant(DriveHandle $system, Node|NodeSnapshot $node, Authorizable $user, string $role): void
{
    $system->access()->grant($node, new GrantInput(
        subject: Subject::ofModel($user), role: $role, scope: GrantScope::Item,
    ));
}

function operationMatrixState(): array
{
    return DriveTestHelpers::writeTablesSnapshot();
}

/*
 * Per operation: the narrowest role that may perform it on the source, and the
 * broadest role that may not (null when View alone suffices). The roles nest, so the
 * "missing" role is the strongest negative case the role model allows (PLAN2.D45):
 * read needs download (previewer lacks it), copy needs view+download+copy on every
 * copied node (D30), purge needs delete (organizer lacks it).
 */
dataset('operation permission matrix', static function (): array {
    $cases = [];
    foreach ([
        'find' => [RoleRegistry::PREVIEWER, null], 'children' => [RoleRegistry::PREVIEWER, null], 'abilities' => [RoleRegistry::PREVIEWER, null],
        'revisions' => [RoleRegistry::PREVIEWER, null], 'star' => [RoleRegistry::PREVIEWER, null], 'properties' => [RoleRegistry::PREVIEWER, null],
        'read' => [RoleRegistry::READER, RoleRegistry::PREVIEWER],
        'rename' => [RoleRegistry::WRITER, RoleRegistry::COMMENTER], 'setProperties' => [RoleRegistry::WRITER, RoleRegistry::COMMENTER],
        'move' => [RoleRegistry::WRITER, RoleRegistry::COMMENTER], 'copy' => [RoleRegistry::WRITER, RoleRegistry::COMMENTER],
        'create' => [RoleRegistry::WRITER, RoleRegistry::COMMENTER], 'upload' => [RoleRegistry::WRITER, RoleRegistry::COMMENTER],
        'addRevision' => [RoleRegistry::WRITER, RoleRegistry::COMMENTER], 'restoreRevision' => [RoleRegistry::WRITER, RoleRegistry::COMMENTER],
        'trash' => [RoleRegistry::WRITER, RoleRegistry::COMMENTER], 'restore' => [RoleRegistry::WRITER, RoleRegistry::COMMENTER],
        'purge' => [RoleRegistry::OWNER, RoleRegistry::ORGANIZER],
        'setRevisionRetention' => [RoleRegistry::WRITER, RoleRegistry::COMMENTER], 'deleteRevision' => [RoleRegistry::OWNER, RoleRegistry::ORGANIZER],
    ] as $operation => [$allowed, $missing]) {
        foreach (['hidden', 'allowed', ...($missing === null ? [] : ['missing'])] as $lane) {
            $cases[$operation.' '.$lane] = [$operation, $lane === 'missing' ? $missing : $allowed, $lane];
        }
    }

    return $cases;
});

it('enforces the complete public operation source permission matrix', function (string $operation, string $role, string $lane): void {
    $fileOperation = in_array($operation, ['revisions', 'read', 'addRevision', 'restoreRevision', 'setRevisionRetention', 'deleteRevision'], true);
    $source = $fileOperation
        ? $this->system->files()->upload(UploadSource::fromString('fixture bytes', 'source.txt'))
        : $this->system->folders()->create('source');
    $destination = $this->system->folders()->create('destination');
    operationMatrixGrant($this->system, $destination, $this->user, RoleRegistry::OWNER);
    if ($lane !== 'hidden') {
        operationMatrixGrant($this->system, $source, $this->user, $role);
    }
    if (in_array($operation, ['restore', 'purge'], true)) {
        $this->system->nodes()->trash($source);
    }
    if ($operation === 'restore') {
        operationMatrixGrant($this->system, $this->system->root(), $this->user, RoleRegistry::PREVIEWER);
    }
    $revision = $fileOperation ? $this->system->files()->revisions($source)->items[0] : null;
    if ($operation === 'deleteRevision') {
        // Only a non-current revision can be deleted.
        $this->system->files()->addRevision($source, UploadSource::fromString('newer bytes', 'source.txt'));
    }
    $run = fn () => match ($operation) {
        'find' => $this->actor->nodes()->find($source),
        'children' => $this->actor->nodes()->children($source),
        'abilities' => $this->actor->nodes()->abilities($source),
        'revisions' => $this->actor->files()->revisions($source),
        'read' => $this->actor->files()->read($source),
        'rename' => $this->actor->nodes()->rename($source, 'renamed'),
        'star' => $this->actor->nodes()->star($source),
        'properties' => $this->actor->nodes()->properties($source),
        'setProperties' => $this->actor->nodes()->setProperties($source, 'acme', ['k' => 'v']),
        'move' => $this->actor->nodes()->move($source, $destination),
        'copy' => $this->actor->nodes()->copy($source, $destination),
        'create' => $this->actor->folders()->create('new child', $source),
        'upload' => $this->actor->files()->upload(UploadSource::fromString('new bytes', 'new.txt'), new UploadOptions(parent: $source)),
        'addRevision' => $this->actor->files()->addRevision($source, UploadSource::fromString('new revision', 'source.txt')),
        'restoreRevision' => $this->actor->files()->restoreRevision($source, $revision),
        'trash' => $this->actor->nodes()->trash($source),
        'restore' => $this->actor->trash()->restore($source),
        'purge' => $this->actor->trash()->purge($source),
        'setRevisionRetention' => $this->actor->files()->setRevisionRetention($source, $revision, keepForever: true),
        'deleteRevision' => $this->actor->files()->deleteRevision($source, $revision),
    };
    $before = operationMatrixState();
    if ($lane === 'allowed') {
        $result = $run();
        switch ($operation) {
            case 'find':
                expect($result->id)->toBe($source->id);
                break;
            case 'children':
                expect($result->items)->toBe([])->and($result->nextCursor)->toBeNull();
                break;
            case 'abilities':
                expect($result->flags['view'])->toBeTrue();
                break;
            case 'revisions':
                expect($result->items)->toHaveCount(1)->and($result->items[0]->id)->toBe((string) $revision->id);
                break;
            case 'read':
                try {
                    expect(stream_get_contents($result->stream))->toBe('fixture bytes')
                        ->and($result->revision->id)->toBe($revision->id);
                } finally {
                    fclose($result->stream);
                }
                break;
            case 'rename':
                expect($result->id)->toBe($source->id)->and(DriveTestHelpers::model($source)->name)->toBe('renamed');
                break;
            case 'star':
                expect($result->id)->toBe($source->id)->and($this->actor->nodes()->starred()->items[0]->id ?? null)->toBe($source->id);
                break;
            case 'properties':
                expect($result)->toBe([]);
                break;
            case 'setProperties':
                expect($result->id)->toBe($source->id)->and(DriveTestHelpers::model($source)->properties)->toBe(['acme' => ['k' => 'v']]);
                break;
            case 'move':
                expect($result->id)->toBe($source->id)->and(DriveTestHelpers::model($source)->parent_id)->toEqual($destination->id);
                break;
            case 'copy':
                expect($result->id)->not->toBe($source->id)->and($result->parentId)->toBe($destination->id)
                    ->and(Node::query()->whereKey($source->id)->exists())->toBeTrue();
                break;
            case 'create':
                expect($result->parentId)->toBe($source->id)->and($result->name)->toBe('new child');
                break;
            case 'upload':
                expect($result->parentId)->toBe($source->id)->and($result->name)->toBe('new.txt')
                    ->and($result->sizeBytes)->toBe(strlen('new bytes'));
                break;
            case 'addRevision':
            case 'restoreRevision':
                expect($result->number)->toBe(2)->and((string) DriveTestHelpers::model($source)->file()->value('current_revision_id'))->toBe($result->id);
                break;
            case 'trash':
                expect($result->id)->toBe($source->id)->and(DriveTestHelpers::model($source)->trashed_at)->not->toBeNull();
                break;
            case 'restore':
                expect($result->id)->toBe($source->id)->and(DriveTestHelpers::model($source)->trashed_at)->toBeNull();
                break;
            case 'purge':
                expect(Node::query()->whereKey($source->id)->exists())->toBeFalse();
                break;
            case 'setRevisionRetention':
                expect($result->id)->toBe($revision->id)->and($result->keepForever)->toBeTrue()
                    ->and(FileRevision::query()->findOrFail($revision->id)->keep_forever)->toBeTrue();
                break;
            case 'deleteRevision':
                expect(FileRevision::query()->whereKey($revision->id)->exists())->toBeFalse();
                break;
        }
    } else {
        expect($run)->toThrow($lane === 'hidden' ? NodeNotFoundException::class : PermissionDeniedException::class)
            ->and(operationMatrixState())->toBe($before);
    }
})->with('operation permission matrix');

it('answers abilitiesMany exactly like abilities for every role and leaves hidden nodes out', function (?string $role): void {
    $source = $this->system->folders()->create('source');
    if ($role !== null) {
        operationMatrixGrant($this->system, $source, $this->user, $role);
    }
    $before = operationMatrixState();

    $many = $this->actor->nodes()->abilitiesMany([$source, $source->id]);

    if ($role === null) {
        expect($many)->toBe([]);
    } else {
        expect(array_map('strval', array_keys($many)))->toBe([$source->id])
            ->and($many[$source->id]->toArray())->toBe($this->actor->nodes()->abilities($source)->toArray());
    }
    expect(operationMatrixState())->toBe($before);
})->with([null, RoleRegistry::PREVIEWER, RoleRegistry::READER, RoleRegistry::COMMENTER, RoleRegistry::WRITER, RoleRegistry::ORGANIZER, RoleRegistry::OWNER]);

it('checks each move copy and changed restore destination independently', function (string $operation, string $lane): void {
    $originalParent = $operation === 'restore' ? $this->system->folders()->create('original parent') : null;
    $source = $this->system->folders()->create('source', $originalParent);
    $destination = $this->system->folders()->create('destination');
    operationMatrixGrant($this->system, $source, $this->user, RoleRegistry::OWNER);
    if ($lane === 'missing') {
        operationMatrixGrant($this->system, $destination, $this->user, RoleRegistry::COMMENTER);
    }
    if ($operation === 'restore') {
        $this->system->nodes()->trash($source);
        $this->system->nodes()->trash($originalParent);
    }
    $before = operationMatrixState();
    $run = fn () => match ($operation) {
        'move' => $this->actor->nodes()->move($source, $destination),
        'copy' => $this->actor->nodes()->copy($source, $destination),
        'restore' => $this->actor->trash()->restore($source, new RestoreOptions(destination: $destination)),
    };
    expect($run)->toThrow($lane === 'hidden' ? NodeNotFoundException::class : PermissionDeniedException::class)
        ->and(operationMatrixState())->toBe($before);
})->with(['move', 'copy', 'restore'])->with(['hidden', 'missing']);

it('checks the host drive admission gate for both create and upload despite a full parent grant', function (string $operation): void {
    $parent = $this->system->folders()->create('parent');
    operationMatrixGrant($this->system, $parent, $this->user, RoleRegistry::OWNER);
    config()->set('vaulter.access.permission_drivers.no_create', OperationMatrixNoCreateDriver::class);
    config()->set('vaulter.access.permission_driver', 'no_create');
    $before = operationMatrixState();
    $run = fn () => $operation === 'create'
        ? $this->actor->folders()->create('blocked', $parent)
        : $this->actor->files()->upload(UploadSource::fromString('blocked', 'blocked.txt'), new UploadOptions(parent: $parent));
    expect($run)->toThrow(PermissionDeniedException::class)->and(operationMatrixState())->toBe($before);
})->with(['create', 'upload']);

it('keeps system operations bound to their drive for foreign keys models revisions and destinations', function (): void {
    $other = Vaulter::drive(DriveTestHelpers::host())->asSystem('foreign fixture');
    $own = $this->system->files()->upload(UploadSource::fromString('own', 'own.txt'));
    $foreign = $other->files()->upload(UploadSource::fromString('foreign', 'foreign.txt'));
    $revision = $other->files()->revisions($foreign)->items[0];
    $before = operationMatrixState();
    foreach ([$foreign, $foreign->id] as $key) {
        expect(fn () => $this->system->nodes()->find($key))->toThrow(NodeNotFoundException::class)
            ->and(fn () => $this->system->nodes()->rename($key, 'foreign edit'))->toThrow(NodeNotFoundException::class)
            ->and(fn () => $this->system->nodes()->move($own, $key))->toThrow(NodeNotFoundException::class);
    }
    foreach ([$revision, $revision->id] as $key) {
        expect(fn () => $this->system->files()->read($own, $key))->toThrow(NodeNotFoundException::class)
            ->and(fn () => $this->system->files()->restoreRevision($own, $key))->toThrow(NodeNotFoundException::class);
    }
    expect(operationMatrixState())->toBe($before);
});

it('rebinds a stale node snapshot by its id and ignores forged attributes', function (): void {
    $source = $this->system->folders()->create('original');
    operationMatrixGrant($this->system, $source, $this->user, RoleRegistry::WRITER);
    $this->system->nodes()->rename($source, 'persisted', 0);
    // Only the id of a snapshot is used; the drive, name and sequence it carries are not trusted.
    $forged = new NodeSnapshot(...[...get_object_vars($source), 'driveId' => '-1', 'name' => 'forged', 'sequence' => 0]);
    expect($this->actor->nodes()->find($forged)->name)->toBe('persisted')
        ->and($this->actor->nodes()->rename($forged, 'fresh', 1)->sequence)->toBe(2);
    $foreign = Vaulter::drive(DriveTestHelpers::host())->asSystem('foreign fixture')->folders()->create('foreign');
    $claimed = new NodeSnapshot(...[...get_object_vars($foreign), 'driveId' => $source->driveId]);
    expect(fn () => $this->actor->nodes()->find($claimed))->toThrow(NodeNotFoundException::class)
        ->and(fn () => $this->actor->nodes()->find(''))->toThrow(NodeNotFoundException::class);
});

it('provisions once and resolves the ambient actor lazily while keeping explicit clones independent', function (): void {
    $owner = DriveTestHelpers::host();
    $db = app(VaulterDatabase::class)->connection();
    $before = $db->table('v_drives')->count();
    expect(Vaulter::findDrive($owner))->toBeNull()->and($db->table('v_drives')->count())->toBe($before);
    $base = Vaulter::drive($owner);
    expect($db->table('v_drives')->count())->toBe($before + 1)
        ->and(Vaulter::drive($owner)->model()->id)->toBe($base->model()->id)
        ->and(Vaulter::findDrive($owner)->model()->id)->toBe($base->model()->id);
    $user = $base->actingAs($this->user)->withCorrelationId('matrix-user');
    $system = $base->asSystem('matrix clone')->withCorrelationId('matrix-system');
    expect($db->table('v_drives')->count())->toBe($before + 1)
        ->and($user)->not->toBe($base)->and($system)->not->toBe($base)
        ->and($user->actor()->isSystem())->toBeFalse()->and($system->actor()->isSystem())->toBeTrue();
    $node = $system->folders()->create('system fixture');
    expect(fn () => $user->nodes()->find($node))->toThrow(NodeNotFoundException::class)
        ->and($system->nodes()->find($node)->id)->toBe($node->id)
        ->and($db->table('v_drives')->count())->toBe($before + 1);
    Auth::logout();
    expect(fn () => $base->nodes()->find($node))->toThrow(MissingActorException::class);
    Auth::login($this->user);
    expect(fn () => $base->nodes()->find($node))->toThrow(NodeNotFoundException::class);
    operationMatrixGrant($system, $node, $this->user, RoleRegistry::PREVIEWER);
    expect($base->nodes()->find($node)->id)->toBe($node->id);
    Auth::logout();
    expect(fn () => $base->nodes()->find($node))->toThrow(MissingActorException::class)
        ->and($user->nodes()->find($node)->id)->toBe($node->id)
        ->and($system->nodes()->find($node)->id)->toBe($node->id);
    Auth::login(AccessTestHelpers::user(72));
    expect(fn () => $base->nodes()->find($node))->toThrow(NodeNotFoundException::class)
        ->and($user->nodes()->find($node)->id)->toBe($node->id)
        ->and($db->table('v_drives')->count())->toBe($before + 1);
    Auth::logout();
});

it('empty skips unauthorized roots and deletes only the independently authorized root', function (): void {
    $hidden = $this->system->folders()->create('hidden');
    $visible = $this->system->folders()->create('visible');
    $allowed = $this->system->folders()->create('allowed');
    operationMatrixGrant($this->system, $visible, $this->user, RoleRegistry::ORGANIZER);
    operationMatrixGrant($this->system, $allowed, $this->user, RoleRegistry::OWNER);
    foreach ([$hidden, $visible, $allowed] as $node) {
        $this->system->nodes()->trash($node);
    }
    expect($this->actor->trash()->empty())->toBe(1)
        ->and(Node::query()->whereKey($hidden->id)->exists())->toBeTrue()
        ->and(Node::query()->whereKey($visible->id)->exists())->toBeTrue()
        ->and(Node::query()->whereKey($allowed->id)->exists())->toBeFalse();
});

it('read blocks legacy infection and uncommitted content after Download authorization', function (string $scanStatus): void {
    $file = $this->system->files()->upload(UploadSource::fromString('read bytes', 'read.txt'));
    operationMatrixGrant($this->system, $file, $this->user, RoleRegistry::READER);
    $revision = $this->system->files()->revisions($file)->items[0];
    $row = FileRevision::query()->findOrFail($revision->id);
    app(VaulterDatabase::class)->write(fn () => $row->forceFill(['scan_status' => $scanStatus])->save());
    $before = operationMatrixState();
    expect(fn () => $this->actor->files()->read($file))->toThrow(ContentUnavailableException::class)
        ->and(operationMatrixState())->toBe($before);
    app(VaulterDatabase::class)->write(fn () => $row->forceFill(['scan_status' => 'clean'])->save());
    $stream = $this->actor->files()->read($file);
    try {
        expect(stream_get_contents($stream->stream))->toBe('read bytes');
    } finally {
        fclose($stream->stream);
    }
    app(VaulterDatabase::class)->write(fn () => $row->forceFill(['status' => 'rejected'])->save());
    expect(fn () => $this->actor->files()->read($file, $revision))->toThrow(NodeNotFoundException::class);
})->with(['infected']);

it('SEC-CONFLICT appends to the normalized case-insensitive sibling with no extra node, charging the revision in full', function (string $stored, string $incoming): void {
    $node = $this->system->files()->upload(UploadSource::fromString('old', $stored));
    // Both revisions are retained, so both count (PLAN2.D48).
    app(VaulterDatabase::class)->write(fn () => $this->system->model()->forceFill(['quota_bytes' => 6])->save());
    $result = $this->system->files()->upload(UploadSource::fromString('new', $incoming), new UploadOptions(onConflict: OnNameConflict::AddRevision));
    expect($result->id)->toBe($node->id)
        ->and($result->sequence)->toBe(1)
        ->and($result->file->sha256)->toBe(hash('sha256', 'new'))
        ->and(Node::query()->count())->toBe(2)
        ->and(FileRevision::query()->count())->toBe(2)
        ->and($this->system->model()->used_bytes)->toBe(6);
})->with([['Report.txt', 'report.TXT'], ['Résumé.txt', "Re\u{0301}sume\u{0301}.txt"], ['résumé.txt', 'RÉSUMÉ.txt']]);

it('SEC-CONFLICT requires Edit on the actual case-insensitive revision target outside the transaction', function (): void {
    $node = $this->system->files()->upload(UploadSource::fromString('old', 'Report.txt'));
    operationMatrixGrant($this->system, $this->system->root(), $this->user, RoleRegistry::WRITER);
    operationMatrixGrant($this->system, $node, $this->user, RoleRegistry::COMMENTER);
    $before = operationMatrixState();
    expect(fn () => $this->actor->files()->upload(UploadSource::fromString('new', 'report.TXT'), new UploadOptions(onConflict: OnNameConflict::AddRevision)))
        ->toThrow(PermissionDeniedException::class);
    expect(operationMatrixState())->toBe($before);
});

it('SEC-CONFLICT keeps a folder sibling as an upload conflict without side effects', function (): void {
    $this->system->folders()->create('Report.txt');
    $before = operationMatrixState();
    expect(fn () => $this->system->files()->upload(UploadSource::fromString('new', 'report.TXT'), new UploadOptions(onConflict: OnNameConflict::AddRevision)))
        ->toThrow(NodeNameConflictException::class);
    expect(operationMatrixState())->toBe($before);
});

it('SEC-CONFLICT answers 404 about the requested name for a hidden sibling, file or folder', function (string $kind): void {
    $hidden = $kind === 'file'
        ? $this->system->files()->upload(UploadSource::fromString('old', 'Report.txt'))
        : $this->system->folders()->create('Report.txt');
    operationMatrixGrant($this->system, $this->system->root(), $this->user, RoleRegistry::WRITER);
    $before = operationMatrixState();
    try {
        $this->actor->files()->upload(UploadSource::fromString('new', 'report.TXT'), new UploadOptions(onConflict: OnNameConflict::AddRevision));
        $this->fail('A hidden sibling must not accept a revision or reveal a conflict.');
    } catch (NodeNotFoundException $exception) {
        expect($exception->getMessage())->toContain('report.TXT')
            ->not->toContain('['.$hidden->id.']')
            ->not->toContain('Report.txt');
    }
    expect(operationMatrixState())->toBe($before);
})->with(['file', 'folder']);

it('SEC-CURSOR rejects a file list cursor for a different kind filter', function (): void {
    $this->system->folders()->create('a-folder');
    $this->system->files()->upload(UploadSource::fromString('x', 'z-file.txt'));
    $page = $this->system->nodes()->children($this->system->root(), new ListOptions(perPage: 1, kinds: [NodeKind::File]));
    expect($page->nextCursor)->not->toBeNull();
    expect(fn () => $this->system->nodes()->children($this->system->root(), new ListOptions(perPage: 1, cursor: $page->nextCursor, kinds: [NodeKind::Folder])))
        ->toThrow(InvalidCursorException::class);
});

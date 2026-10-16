<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\PermissionDriverManager;
use Vaulter\Access\Roles\Role;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Nodes\CopyNodeAction;
use Vaulter\Drive\Data\Nodes\CopyNodeInput;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;

// D30 (variant A): COPY needs View and Copy on EVERY active descendant, not only on the root.

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.access.permission_driver', 'database');
    $this->owner = DriveTestHelpers::host();
    $this->system = Vaulter::drive($this->owner)->asSystem('copy subtree fixture');
    $this->user = AccessTestHelpers::user(72);
    $this->actor = Vaulter::drive($this->owner)->actingAs($this->user);
});

function copySubtreeGrant(DriveHandle $system, Node|NodeSnapshot $node, object $user, string $role, GrantScope $scope): void
{
    $system->access()->grant($node, new GrantInput(
        subject: Subject::ofModel($user), role: $role, scope: $scope,
    ));
}

function copySubtreeFixture(object $test): array
{
    $folder = $test->system->folders()->create('project');
    $visible = $test->system->files()->upload(UploadSource::fromString('visible', 'visible.txt'), new UploadOptions(parent: $folder));
    $secret = $test->system->files()->upload(UploadSource::fromString('secret', 'secret.txt'), new UploadOptions(parent: $folder));
    $destination = $test->system->folders()->create('destination');
    copySubtreeGrant($test->system, $destination, $test->user, RoleRegistry::OWNER, GrantScope::Item);
    copySubtreeGrant($test->system, $folder, $test->user, RoleRegistry::WRITER, GrantScope::Subtree);
    // The secret file cuts inheritance: nothing granted on the folder reaches it.
    app(VaulterDatabase::class)->write(fn () => DriveTestHelpers::model($secret)->forceFill(['inherit_grants' => false])->save());

    return [$folder, $visible, $secret, $destination];
}

it('SEC-D30-copy-subtree refuses the whole copy when a descendant is hidden and writes nothing', function (): void {
    [$folder, , $secret, $destination] = copySubtreeFixture($this);
    $nodes = Node::query()->count();
    $used = $this->system->model()->used_bytes;

    expect(fn () => $this->actor->nodes()->copy($folder, $destination))->toThrow(PermissionDeniedException::class);

    expect(Node::query()->count())->toBe($nodes)->and($this->system->model()->used_bytes)->toBe($used);

    // Making the descendant visible and copyable makes the same call legal.
    copySubtreeGrant($this->system, $secret, $this->user, RoleRegistry::WRITER, GrantScope::Item);
    $copy = $this->actor->nodes()->copy($folder, $destination);

    expect(Node::query()->where('parent_id', $copy->id)->count())->toBe(2);
});

it('SEC-D30-copy-subtree refuses a hidden document descendant before any document or revision row exists', function (): void {
    [$folder, , , $destination] = copySubtreeFixture($this);
    // A document the copier cannot see: the copy would otherwise reveal its content.
    $hidden = AccessTestHelpers::folder($this->system->model(), $folder, 'hidden document', inheritGrants: false);
    app(VaulterDatabase::class)->write(fn () => $hidden->forceFill(['kind' => NodeKind::Document])->save());
    $db = app(VaulterDatabase::class)->connection();
    $rows = static fn (): array => [Node::query()->count(), $db->table('v_documents')->count(), $db->table('v_document_revisions')->count()];
    $before = $rows();

    expect(fn () => $this->actor->nodes()->copy($folder, $destination))
        ->toThrow(PermissionDeniedException::class, 'copy')
        ->and($rows())->toBe($before);
});

it('SEC-D30-copy-subtree refuses the whole copy when a visible descendant lacks download or copy', function (string $role): void {
    [$folder, , $secret, $destination] = copySubtreeFixture($this);
    // The secret is visible now, but its role cannot take the content out (previewer)
    // or copy it (commenter).
    copySubtreeGrant($this->system, $secret, $this->user, $role, GrantScope::Item);
    $nodes = Node::query()->count();

    expect(fn () => $this->actor->nodes()->copy($folder, $destination))->toThrow(PermissionDeniedException::class)
        ->and(Node::query()->count())->toBe($nodes);
})->with([RoleRegistry::PREVIEWER, RoleRegistry::COMMENTER]);

it('SEC-D30-copy-subtree lets a system actor copy everything but still stays in its drive', function (): void {
    [$folder, , , $destination] = copySubtreeFixture($this);

    $copy = $this->system->nodes()->copy($folder, $destination);

    expect(Node::query()->where('parent_id', $copy->id)->count())->toBe(2);
});

it('SEC-D30-copy-subtree refuses a descendant outside the authorised key set at the locked copy', function (): void {
    [$folder, $visible, , $destination] = copySubtreeFixture($this);
    $context = DriveContext::system($this->system->model(), 'late descendant');
    $nodes = Node::query()->count();

    expect(fn () => app(CopyNodeAction::class)->execute(
        $context,
        new CopyNodeInput(DriveTestHelpers::model($folder), DriveTestHelpers::model($destination), 'late', OnNameConflict::Rename, [(string) $folder->id, (string) $visible->id]),
    ))->toThrow(PermissionDeniedException::class);

    expect(Node::query()->count())->toBe($nodes);
});

/**
 * A host role lane that grants View, Copy and Create everywhere but never Download:
 * no built-in role has that shape, so only a driver can pin the D30 Download requirement.
 */
final class CopyWithoutDownloadDriver implements PermissionDriver
{
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        return new Decision(role: new Role(key: 'copy-without-download', version: 1, abilities: [Ability::View, Ability::Copy, Ability::Create]));
    }

    public function override(Authorizable $actor, AccessScope $scope): ?Effect
    {
        return null;
    }

    public function subjectsFor(Authorizable $actor): array
    {
        return $actor instanceof Model ? [Subject::ofModel($actor)] : [];
    }

    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        return true;
    }
}

it('SEC-D30-copy-subtree refuses a copy of nodes the actor may view and copy but not download', function (): void {
    $folder = $this->system->folders()->create('project');
    $this->system->files()->upload(UploadSource::fromString('visible', 'visible.txt'), new UploadOptions(parent: $folder));
    $destination = $this->system->folders()->create('destination');
    app()->instance(CopyWithoutDownloadDriver::class, new CopyWithoutDownloadDriver);
    config()->set('vaulter.access.permission_drivers.d30', CopyWithoutDownloadDriver::class);
    config()->set('vaulter.access.permission_driver', 'd30');
    app()->forgetInstance(PermissionDriverManager::class);
    app()->forgetInstance(AccessResolver::class);
    $nodes = Node::query()->count();

    expect(fn () => $this->actor->nodes()->copy($folder, $destination))->toThrow(PermissionDeniedException::class)
        ->and(Node::query()->count())->toBe($nodes);
});

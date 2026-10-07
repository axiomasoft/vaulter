<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Actions\Nodes\CopyNodeAction;
use Vaulter\Drive\Data\Nodes\CopyNodeInput;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;

// D30 (variant A): COPY needs View and Copy on EVERY active descendant, not only on the root.

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.access.permission_driver', 'database');
    $this->owner = DriveTestHelpers::host();
    $this->system = Vaulter::drive($this->owner)->asSystem('copy subtree fixture');
    $this->user = AccessTestHelpers::user(72);
    $this->actor = Vaulter::drive($this->owner)->actingAs($this->user);
});

function copySubtreeGrant(DriveHandle $system, Node $node, object $user, int $bits, GrantScope $scope): void
{
    $system->access()->grant($node, new GrantInput(
        subject: Subject::ofModel($user), level: GrantLevel::Reader, scope: $scope, permissions: $bits,
    ));
}

function copySubtreeFixture(object $test): array
{
    $folder = $test->system->folders()->create('project');
    $visible = $test->system->files()->upload(UploadSource::fromString('visible', 'visible.txt'), new UploadOptions(parent: $folder));
    $secret = $test->system->files()->upload(UploadSource::fromString('secret', 'secret.txt'), new UploadOptions(parent: $folder));
    $destination = $test->system->folders()->create('destination');
    copySubtreeGrant($test->system, $destination, $test->user, 63, GrantScope::Item);
    copySubtreeGrant($test->system, $folder, $test->user, 1 | 4, GrantScope::Subtree);
    // The secret file cuts inheritance: nothing granted on the folder reaches it.
    $secret->forceFill(['inherit_grants' => false])->save();

    return [$folder, $visible, $secret, $destination];
}

it('SEC-D30-copy-subtree refuses the whole copy when a descendant is hidden and writes nothing', function (): void {
    [$folder, , $secret, $destination] = copySubtreeFixture($this);
    $nodes = Node::query()->count();
    $used = $this->system->model()->used_bytes;

    expect(fn () => $this->actor->nodes()->copy($folder, $destination))->toThrow(PermissionDeniedException::class);

    expect(Node::query()->count())->toBe($nodes)->and($this->system->model()->used_bytes)->toBe($used);

    // Making the descendant visible and copyable makes the same call legal.
    copySubtreeGrant($this->system, $secret, $this->user, 1 | 4, GrantScope::Item);
    $copy = $this->actor->nodes()->copy($folder, $destination);

    expect(Node::query()->where('parent_id', $copy->id)->count())->toBe(2);
});

it('SEC-D30-copy-subtree lets a system actor copy everything but still stays in its drive', function (): void {
    [$folder, , , $destination] = copySubtreeFixture($this);

    $copy = $this->system->nodes()->copy($folder, $destination);

    expect(Node::query()->where('parent_id', $copy->id)->count())->toBe(2);
});

it('SEC-D30-copy-subtree refuses a descendant outside the authorised key set at the locked copy', function (): void {
    [$folder, $visible, , $destination] = copySubtreeFixture($this);
    $context = OperationContext::system($this->system->model(), 'late descendant');
    $nodes = Node::query()->count();

    expect(fn () => app(CopyNodeAction::class)->execute(
        $context,
        new CopyNodeInput($folder, $destination, 'late', OnNameConflict::Rename, [(string) $folder->id, (string) $visible->id]),
    ))->toThrow(PermissionDeniedException::class);

    expect(Node::query()->count())->toBe($nodes);
});

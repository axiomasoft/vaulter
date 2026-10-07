<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\PermissionDriverManager;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Enums\Access\Effect;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * Absolute deny. The suite default is NullPermissionDriver, which short-circuits every
 * guard() to allow, so removing the guard call is invisible. This driver makes
 * the call the only reason a request is refused.
 */
final class R07cDenyRoleDriver implements PermissionDriver
{
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        return null;
    }

    public function override(Authorizable $actor, AccessScope $scope): ?Effect
    {
        return Effect::Deny;
    }

    /**
     * @return list<Subject>
     */
    public function subjectsFor(Authorizable $actor): array
    {
        return [];
    }

    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        return false;
    }
}

beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    $this->actingAs((new TestUser)->forceFill(['id' => 41]));
});

function r07cStorage(): Drive
{
    return Vaulter::for(DriveTestHelpers::host())->model();
}

function r07cDeny(): void
{
    app()->instance(R07cDenyRoleDriver::class, new R07cDenyRoleDriver);
    config()->set('vaulter.access.permission_drivers.r07cdeny', R07cDenyRoleDriver::class);
    config()->set('vaulter.access.permission_driver', 'r07cdeny');
    app()->forgetInstance(PermissionDriverManager::class);
    app()->forgetInstance(AccessResolver::class);
}

function r07cNode(Drive $storage): string
{
    return (string) test()->postJson('api/vaulter/storages/'.$storage->getKey().'/uploads', [
        'file' => UploadedFile::fake()->createWithContent('guard.txt', 'guard'),
    ])->assertCreated()->json('data.id');
}

it('refuses every node guard when the host role driver denies the actor', function (): void {
    $storage = r07cStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();
    $nodeId = r07cNode($storage);
    $folderId = (string) $this->postJson($base.'/folders', ['name' => 'Hold'])->assertCreated()->json('data.id');

    r07cDeny();

    $this->getJson($base.'/nodes')->assertForbidden();
    $this->getJson('api/vaulter/nodes/'.$nodeId)->assertForbidden();
    $this->postJson($base.'/folders', ['name' => 'Nope'])->assertForbidden();
    $this->postJson($base.'/uploads', [
        'file' => UploadedFile::fake()->createWithContent('nope.txt', 'nope'),
    ])->assertForbidden();
    $this->patchJson('api/vaulter/nodes/'.$nodeId, ['name' => 'renamed.txt'])->assertForbidden();
    $this->postJson('api/vaulter/nodes/'.$nodeId.'/move', ['parent_id' => $folderId])->assertForbidden();
    $this->postJson('api/vaulter/nodes/'.$nodeId.'/copy', ['name' => 'copy.txt'])->assertForbidden();
    $this->deleteJson('api/vaulter/nodes/'.$nodeId)->assertForbidden();
    $this->deleteJson('api/vaulter/nodes/'.$nodeId.'/force')->assertForbidden();
});

it('refuses restore of a trashed node when the host role driver denies the actor', function (): void {
    $storage = r07cStorage();
    $nodeId = r07cNode($storage);

    $this->deleteJson('api/vaulter/nodes/'.$nodeId)->assertOk();

    r07cDeny();

    $this->postJson('api/vaulter/nodes/'.$nodeId.'/restore')->assertForbidden();
});

it('places a folder under the requested parent, not the storage root', function (): void {
    $storage = r07cStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();
    $parentId = (string) $this->postJson($base.'/folders', ['name' => 'Parent'])->assertCreated()->json('data.id');

    $childId = (string) $this->postJson($base.'/folders', [
        'name' => 'Child',
        'parent_id' => $parentId,
    ])->assertCreated()->json('data.id');

    $this->getJson('api/vaulter/nodes/'.$childId)
        ->assertOk()
        ->assertJsonPath('data.parent_id', (int) $parentId);
});

it('uses the default page size of 50 when per_page is omitted', function (): void {
    $storage = r07cStorage();

    $this->getJson('api/vaulter/storages/'.$storage->getKey().'/nodes')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 50);
});

it('refuses a tus abort when the host role driver denies the actor', function (): void {
    $storage = r07cStorage();
    $uploadId = (string) $this->postJson('api/vaulter/storages/'.$storage->getKey().'/resumable-uploads', [
        'filename' => 'guard.bin',
        'size' => 4,
    ])->assertCreated()->json('data.upload_id');

    r07cDeny();

    $this->delete('api/vaulter/resumable-uploads/'.$uploadId, [], ['Tus-Resumable' => '1.0.0'])
        ->assertNotFound();
});

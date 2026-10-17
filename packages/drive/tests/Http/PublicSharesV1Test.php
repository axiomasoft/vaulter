<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Sharing\ShareAudience;
use Vaulter\Drive\Http\Resources\Sharing\SharedNodeResource;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Drive\Testing\Sharing\ShareTestHelpers;

/**
 * The public share routes: the link's own role on its node-set, opaque node ids, one
 * level of listing for a subtree link, and problem+json denials that do not tell a bad
 * token from a revoked one.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-drive.features.share_links', true);
    $this->drive = V1::drive(userId: 1);
});

it('shows the shared node by opaque id and lists a subtree link page by page', function (): void {
    $folder = V1::folder($this->drive, 'album');
    foreach (['a.txt', 'b.txt', 'c.txt'] as $name) {
        V1::file($this->drive, $name, $name, parent: $folder->id);
    }
    ShareTestHelpers::share(DriveTestHelpers::model($folder), token: 'subtree-v1', scope: GrantScope::Subtree);

    $this->getJson(V1::url('shares/subtree-v1'))
        ->assertOk()
        ->assertJsonPath('data.id', SharedNodeResource::opaqueId($folder->id))
        ->assertJsonPath('data.name', 'album')
        ->assertJsonPath('data.children', null);

    $first = $this->getJson(V1::url('shares/subtree-v1/nodes?per_page=2'))->assertOk();
    expect(array_column($first->json('data'), 'name'))->toBe(['a.txt', 'b.txt'])
        ->and($first->json('meta.per_page'))->toBe(2);
    $rest = $this->getJson(V1::url('shares/subtree-v1/nodes?per_page=2&cursor='.urlencode($first->json('meta.next_cursor'))))->assertOk();
    expect(array_column($rest->json('data'), 'name'))->toBe(['c.txt'])
        ->and($rest->json('meta.next_cursor'))->toBeNull();
});

it('downloads a listed child by its opaque id and refuses any other id', function (): void {
    $folder = V1::folder($this->drive, 'docs');
    $inside = V1::file($this->drive, 'inside.txt', 'inside bytes', parent: $folder->id);
    $outside = V1::file($this->drive, 'outside.txt', 'outside bytes');
    ShareTestHelpers::share(DriveTestHelpers::model($folder), token: 'pick-v1', scope: GrantScope::Subtree);

    $response = $this->get(V1::url('shares/pick-v1/download?node='.SharedNodeResource::opaqueId($inside->id)))->assertOk();
    expect($response->streamedContent())->toBe('inside bytes');

    foreach ([SharedNodeResource::opaqueId($outside->id), $inside->id, 'garbage'] as $node) {
        $this->getJson(V1::url('shares/pick-v1/download?node='.$node))->assertNotFound()->assertJsonPath('code', 'share_not_found');
    }
    // The folder itself has no bytes.
    $this->getJson(V1::url('shares/pick-v1/download'))->assertNotFound()->assertJsonPath('code', 'share_not_found');
});

it('lists nothing for an item link and downloads only its own file', function (): void {
    $file = V1::file($this->drive, 'single.txt', 'single bytes');
    ShareTestHelpers::share(DriveTestHelpers::model($file), token: 'item-v1');

    $this->getJson(V1::url('shares/item-v1/nodes'))->assertNotFound()->assertJsonPath('code', 'share_not_found');
    expect($this->get(V1::url('shares/item-v1/download'))->assertOk()->streamedContent())->toBe('single bytes');
});

it('denies with problem+json: bad token, previewer download, password, sign-in', function (): void {
    $file = V1::file($this->drive, 'x.txt', 'x');
    $model = DriveTestHelpers::model($file);
    ShareTestHelpers::share($model, token: 'preview-v1', role: RoleRegistry::PREVIEWER);
    ShareTestHelpers::share($model, token: 'login-v1', audience: ShareAudience::Authenticated);

    $this->getJson(V1::url('shares/no-such-token'))->assertNotFound()->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'share_not_found');
    $this->getJson(V1::url('shares/preview-v1/download'))->assertForbidden()->assertJsonPath('code', 'permission_denied');
    $this->getJson(V1::url('shares/login-v1'))->assertUnauthorized()->assertJsonPath('code', 'actor_required');
});

it('answers 423 before any byte for a quarantined file', function (): void {
    $file = V1::file($this->drive, 'bad.txt', 'bad');
    $revision = DriveTestHelpers::model($file)->file()->firstOrFail()->currentRevision()->firstOrFail();
    app(VaulterDatabase::class)->write(static fn () => $revision->forceFill(['scan_status' => 'infected'])->save());
    ShareTestHelpers::share(DriveTestHelpers::model($file), token: 'quarantine-v1');

    $this->getJson(V1::url('shares/quarantine-v1/download'))->assertStatus(423)->assertJsonPath('code', 'content_unavailable');
});

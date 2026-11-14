<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Filament\Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->parityActor = DriveBuilder::user();
    $this->actingAs($this->parityActor);
    $this->parityDrive = DriveBuilder::storage();
    $this->paritySource = DriveBuilder::folder($this->parityDrive, 'Source');
    $this->parityTarget = DriveBuilder::folder($this->parityDrive, 'Target');
});

/** The node's current ETag, or a placeholder tag when the actor cannot read the node at all. */
function parityEtag(TestCase $test, string $nodeId): array
{
    $response = $test->getJson('api/vaulter/v1/nodes/'.$nodeId);
    $etag = $response->isSuccessful() ? $response->headers->get('ETag') : null;

    return ['If-Match' => is_string($etag) ? $etag : 'W/"0"'];
}

function parityAction(string $method): Action
{
    return (new ReflectionMethod(NodeResource::class, $method))->invoke(null);
}

it('gives a hidden source the same 404 through PHP HTTP and a forged panel callback', function (): void {
    $handle = Vaulter::drive($this->parityDrive)->actingAs($this->parityActor);
    $action = parityAction('renameAction');

    expect(fn () => $handle->nodes()->rename($this->paritySource->getKey(), 'Forbidden'))
        ->toThrow(NodeNotFoundException::class);
    $this->patchJson('api/vaulter/v1/nodes/'.$this->paritySource->id, ['name' => 'Forbidden'], parityEtag($this, (string) $this->paritySource->id))->assertNotFound();
    ($action->getActionFunction())(DriveBuilder::row($this->paritySource), ['name' => 'Forbidden']);
    Notification::assertNotified(Notification::make()->title('Rename failed')->body(NodeNotFoundException::forId($this->paritySource->id)->getMessage())->danger());
    expect($this->paritySource->refresh()->name)->toBe('Source')
        ->and($this->paritySource->sequence)->toBe(0);
});

it('gives a visible source without Edit the same 403 through all three boundaries', function (): void {
    DriveBuilder::grant($this->paritySource, $this->parityActor, RoleRegistry::READER, scope: GrantScope::Item);
    $handle = Vaulter::drive($this->parityDrive)->actingAs($this->parityActor);
    $action = parityAction('renameAction');

    expect(fn () => $handle->nodes()->rename($this->paritySource->getKey(), 'Forbidden'))
        ->toThrow(PermissionDeniedException::class);
    $this->patchJson('api/vaulter/v1/nodes/'.$this->paritySource->id, ['name' => 'Forbidden'], parityEtag($this, (string) $this->paritySource->id))->assertForbidden();
    ($action->getActionFunction())(DriveBuilder::row($this->paritySource), ['name' => 'Forbidden']);
    Notification::assertNotified(Notification::make()->title('Rename failed')->body(PermissionDeniedException::forAbility('edit')->getMessage())->danger());
    expect($this->paritySource->refresh()->name)->toBe('Source')
        ->and($this->paritySource->sequence)->toBe(0);
});

it('renames with the same explicit actor through PHP HTTP and the actual panel callback', function (): void {
    DriveBuilder::grant($this->paritySource, $this->parityActor, RoleRegistry::WRITER);
    Vaulter::drive($this->parityDrive)->actingAs($this->parityActor)->nodes()->rename($this->paritySource->getKey(), 'PHP');
    $this->patchJson('api/vaulter/v1/nodes/'.$this->paritySource->id, ['name' => 'HTTP'], parityEtag($this, (string) $this->paritySource->id))
        ->assertOk()->assertJsonPath('data.name', 'HTTP');
    $action = parityAction('renameAction');
    ($action->getActionFunction())(DriveBuilder::row($this->paritySource), ['name' => 'Panel']);

    expect($this->paritySource->refresh()->name)->toBe('Panel')
        ->and($this->paritySource->sequence)->toBe(3);
});

it('hides a move destination without View consistently without changing the source', function (): void {
    DriveBuilder::grant($this->paritySource, $this->parityActor, RoleRegistry::WRITER);
    $handle = Vaulter::drive($this->parityDrive)->actingAs($this->parityActor);
    $action = parityAction('moveAction');
    $parent = $this->paritySource->parent_id;

    expect(fn () => $handle->nodes()->move($this->paritySource->getKey(), $this->parityTarget->getKey()))
        ->toThrow(NodeNotFoundException::class);
    $this->postJson('api/vaulter/v1/nodes/'.$this->paritySource->id.'/move', ['destination_id' => (string) $this->parityTarget->id], parityEtag($this, (string) $this->paritySource->id))
        ->assertNotFound();
    expect(fn () => ($action->getActionFunction())(DriveBuilder::row($this->paritySource), ['parent_id' => $this->parityTarget->id], $action))
        ->toThrow(Halt::class);
    Notification::assertNotified(Notification::make()->title('Move not allowed')->body(NodeNotFoundException::forId($this->parityTarget->id)->getMessage())->danger());
    expect($this->paritySource->refresh()->parent_id)->toBe($parent)
        ->and($this->paritySource->sequence)->toBe(0);
});

it('rejects a visible destination without Create and retains the panel denial notification', function (): void {
    DriveBuilder::grant($this->paritySource, $this->parityActor, RoleRegistry::WRITER);
    DriveBuilder::grant($this->parityTarget, $this->parityActor, RoleRegistry::READER);
    $handle = Vaulter::drive($this->parityDrive)->actingAs($this->parityActor);
    $action = parityAction('moveAction');
    $parent = $this->paritySource->parent_id;

    expect(fn () => $handle->nodes()->move($this->paritySource->getKey(), $this->parityTarget->getKey()))
        ->toThrow(PermissionDeniedException::class);
    $this->postJson('api/vaulter/v1/nodes/'.$this->paritySource->id.'/move', ['destination_id' => (string) $this->parityTarget->id], parityEtag($this, (string) $this->paritySource->id))
        ->assertForbidden();
    expect(fn () => ($action->getActionFunction())(DriveBuilder::row($this->paritySource), ['parent_id' => $this->parityTarget->id], $action))
        ->toThrow(Halt::class);
    Notification::assertNotified(Notification::make()->title('Move not allowed')->body(PermissionDeniedException::forAbility('create')->getMessage())->danger());
    expect($this->paritySource->refresh()->parent_id)->toBe($parent)
        ->and($this->paritySource->sequence)->toBe(0);
});

it('permits a move only after both source and destination grants allow it', function (): void {
    DriveBuilder::grant($this->paritySource, $this->parityActor, RoleRegistry::WRITER);
    DriveBuilder::grant($this->parityTarget, $this->parityActor, RoleRegistry::WRITER);
    Vaulter::drive($this->parityDrive)->actingAs($this->parityActor)->nodes()->move($this->paritySource->getKey(), $this->parityTarget->getKey());
    $this->postJson('api/vaulter/v1/nodes/'.$this->paritySource->id.'/move', ['destination_id' => (string) $this->parityTarget->id], parityEtag($this, (string) $this->paritySource->id))
        ->assertOk()->assertJsonPath('data.parent_id', (string) $this->parityTarget->id);
    $action = parityAction('moveAction');
    ($action->getActionFunction())(DriveBuilder::row($this->paritySource), ['parent_id' => $this->parityTarget->id], $action);
    expect($this->paritySource->refresh()->parent_id)->toBe($this->parityTarget->id)
        ->and($this->paritySource->sequence)->toBe(1);
});

it('rejects non-scalar forged panel destinations before attempting a move', function (mixed $destination): void {
    DriveBuilder::grant($this->paritySource, $this->parityActor, RoleRegistry::WRITER);
    $action = parityAction('moveAction');
    $parent = $this->paritySource->parent_id;

    expect(fn () => ($action->getActionFunction())(DriveBuilder::row($this->paritySource), ['parent_id' => $destination], $action))
        ->toThrow(ValidationException::class);
    expect($this->paritySource->refresh()->parent_id)->toBe($parent)
        ->and($this->paritySource->sequence)->toBe(0);
})->with(['boolean' => [true], 'array' => [[]], 'float' => [1.5]]);

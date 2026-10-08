<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Facades\Vaulter;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->parityActor = DriveBuilder::user();
    $this->actingAs($this->parityActor);
    $this->parityDrive = DriveBuilder::storage();
    $this->paritySource = DriveBuilder::folder($this->parityDrive, 'Source');
    $this->parityTarget = DriveBuilder::folder($this->parityDrive, 'Target');
});

function parityAction(string $method): Action
{
    return (new ReflectionMethod(NodeResource::class, $method))->invoke(null);
}

it('gives a hidden source the same 404 through PHP HTTP and a forged panel callback', function (): void {
    $handle = Vaulter::drive($this->parityDrive)->actingAs($this->parityActor);
    $action = parityAction('renameAction');

    expect(fn () => $handle->nodes()->rename($this->paritySource, 'Forbidden'))
        ->toThrow(NodeNotFoundException::class);
    $this->patchJson('api/vaulter/nodes/'.$this->paritySource->id, ['name' => 'Forbidden'])->assertNotFound();
    expect(fn () => ($action->getActionFunction())($this->paritySource, ['name' => 'Forbidden']))
        ->toThrow(NodeNotFoundException::class);
    expect($this->paritySource->refresh()->name)->toBe('Source')
        ->and($this->paritySource->sequence)->toBe(0);
});

it('gives a visible source without Edit the same 403 through all three boundaries', function (): void {
    DriveBuilder::grant($this->paritySource, $this->parityActor, RoleRegistry::READER, scope: GrantScope::Item);
    $handle = Vaulter::drive($this->parityDrive)->actingAs($this->parityActor);
    $action = parityAction('renameAction');

    expect(fn () => $handle->nodes()->rename($this->paritySource, 'Forbidden'))
        ->toThrow(PermissionDeniedException::class);
    $this->patchJson('api/vaulter/nodes/'.$this->paritySource->id, ['name' => 'Forbidden'])->assertForbidden();
    expect(fn () => ($action->getActionFunction())($this->paritySource, ['name' => 'Forbidden']))
        ->toThrow(PermissionDeniedException::class);
    expect($this->paritySource->refresh()->name)->toBe('Source')
        ->and($this->paritySource->sequence)->toBe(0);
});

it('renames with the same explicit actor through PHP HTTP and the actual panel callback', function (): void {
    DriveBuilder::grant($this->paritySource, $this->parityActor, RoleRegistry::WRITER);
    Vaulter::drive($this->parityDrive)->actingAs($this->parityActor)->nodes()->rename($this->paritySource, 'PHP');
    $this->patchJson('api/vaulter/nodes/'.$this->paritySource->id, ['name' => 'HTTP'])
        ->assertOk()->assertJsonPath('data.name', 'HTTP');
    $action = parityAction('renameAction');
    ($action->getActionFunction())($this->paritySource, ['name' => 'Panel']);

    expect($this->paritySource->refresh()->name)->toBe('Panel')
        ->and($this->paritySource->sequence)->toBe(3);
});

it('hides a move destination without View consistently without changing the source', function (): void {
    DriveBuilder::grant($this->paritySource, $this->parityActor, RoleRegistry::WRITER);
    $handle = Vaulter::drive($this->parityDrive)->actingAs($this->parityActor);
    $action = parityAction('moveAction');
    $parent = $this->paritySource->parent_id;

    expect(fn () => $handle->nodes()->move($this->paritySource, $this->parityTarget))
        ->toThrow(NodeNotFoundException::class);
    $this->postJson('api/vaulter/nodes/'.$this->paritySource->id.'/move', ['parent_id' => $this->parityTarget->id])
        ->assertNotFound();
    expect(fn () => ($action->getActionFunction())($this->paritySource, ['parent_id' => $this->parityTarget->id], $action))
        ->toThrow(NodeNotFoundException::class);
    expect($this->paritySource->refresh()->parent_id)->toBe($parent)
        ->and($this->paritySource->sequence)->toBe(0);
});

it('rejects a visible destination without Create and retains the panel denial notification', function (): void {
    DriveBuilder::grant($this->paritySource, $this->parityActor, RoleRegistry::WRITER);
    DriveBuilder::grant($this->parityTarget, $this->parityActor, RoleRegistry::READER);
    $handle = Vaulter::drive($this->parityDrive)->actingAs($this->parityActor);
    $action = parityAction('moveAction');
    $parent = $this->paritySource->parent_id;

    expect(fn () => $handle->nodes()->move($this->paritySource, $this->parityTarget))
        ->toThrow(PermissionDeniedException::class);
    $this->postJson('api/vaulter/nodes/'.$this->paritySource->id.'/move', ['parent_id' => $this->parityTarget->id])
        ->assertForbidden();
    expect(fn () => ($action->getActionFunction())($this->paritySource, ['parent_id' => $this->parityTarget->id], $action))
        ->toThrow(Halt::class);
    Notification::assertNotified(Notification::make()->title('Move not allowed')->danger());
    expect($this->paritySource->refresh()->parent_id)->toBe($parent)
        ->and($this->paritySource->sequence)->toBe(0);
});

it('permits a move only after both source and destination grants allow it', function (): void {
    DriveBuilder::grant($this->paritySource, $this->parityActor, RoleRegistry::WRITER);
    DriveBuilder::grant($this->parityTarget, $this->parityActor, RoleRegistry::WRITER);
    Vaulter::drive($this->parityDrive)->actingAs($this->parityActor)->nodes()->move($this->paritySource, $this->parityTarget);
    $this->postJson('api/vaulter/nodes/'.$this->paritySource->id.'/move', ['parent_id' => $this->parityTarget->id])
        ->assertOk()->assertJsonPath('data.parent_id', $this->parityTarget->id);
    $action = parityAction('moveAction');
    ($action->getActionFunction())($this->paritySource, ['parent_id' => $this->parityTarget->id], $action);
    expect($this->paritySource->refresh()->parent_id)->toBe($this->parityTarget->id)
        ->and($this->paritySource->sequence)->toBe(1);
});

it('rejects non-scalar forged panel destinations before attempting a move', function (mixed $destination): void {
    DriveBuilder::grant($this->paritySource, $this->parityActor, RoleRegistry::WRITER);
    $action = parityAction('moveAction');
    $parent = $this->paritySource->parent_id;

    expect(fn () => ($action->getActionFunction())($this->paritySource, ['parent_id' => $destination], $action))
        ->toThrow(ValidationException::class);
    expect($this->paritySource->refresh()->parent_id)->toBe($parent)
        ->and($this->paritySource->sequence)->toBe(0);
})->with(['boolean' => [true], 'array' => [[]], 'float' => [1.5]]);

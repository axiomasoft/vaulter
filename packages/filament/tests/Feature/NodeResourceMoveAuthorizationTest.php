<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\DataStore;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Resources\NodeResource\Pages\ListNodes;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Testbench loses the provider's DataStore instance; restore the normal
    // Livewire mechanism registration so hydration retains validation/action state.
    app(DataStore::class)->register();
    expect(app(DataStore::class))->toBe(app(DataStore::class));
    $this->moveActor = DriveBuilder::user();
    $this->actingAs($this->moveActor);
    $this->moveStorage = DriveBuilder::storage();
    DriveBuilder::grant($this->moveStorage->rootNode, $this->moveActor, RoleRegistry::READER, scope: GrantScope::Item);
    $this->moveSource = DriveBuilder::folder($this->moveStorage, 'Source');
    $this->moveTarget = DriveBuilder::folder($this->moveStorage, 'Target');
    DriveBuilder::grant($this->moveSource, $this->moveActor, RoleRegistry::WRITER, scope: GrantScope::Item);
    expect(app(AccessResolver::class)->can($this->moveActor, Ability::Move, $this->moveSource))->toBeTrue()
        ->and(app(AccessResolver::class)->can($this->moveActor, Ability::Create, $this->moveTarget))->toBeFalse();
});

it('omits a destination without Create from the folder options', function (): void {
    $options = new ReflectionMethod(NodeResource::class, 'folderOptions')->invoke(null, DriveBuilder::row($this->moveSource));
    expect($options)->not->toHaveKey($this->moveTarget->getKey());
});

it('rejects a forged move into a folder without Create and notifies the actor', function (): void {
    DriveBuilder::grant($this->moveTarget, $this->moveActor, RoleRegistry::READER, scope: GrantScope::Item);
    $parent = $this->moveSource->parent_id;
    // Admit the forged ID at form validation only, so this proves the action's
    // authorization even if an option was cached before access changed.
    Select::configureUsing(
        modifyUsing: fn (Select $select): Select => $select->in([$this->moveTarget->getKey()]),
        during: fn () => Livewire::test(ListNodes::class)->callTableAction('move', (string) $this->moveSource->getKey(), ['parent_id' => $this->moveTarget->getKey()]),
        isImportant: true,
    );
    expect($this->moveSource->fresh()->parent_id)->toBe($parent);
    Notification::assertNotified(Notification::make()->title('Move not allowed')->body(PermissionDeniedException::forAbility('create')->getMessage())->danger());
    Livewire::test(ListNodes::class)->callTableAction('move', (string) $this->moveSource->getKey(), ['parent_id' => null]);
    expect($this->moveSource->fresh()->parent_id)->toBe($parent);
    Notification::assertNotified(Notification::make()->title('Move not allowed')->body(PermissionDeniedException::forAbility('create')->getMessage())->danger());
});

it('moves into a destination when the actor has Create there', function (): void {
    DriveBuilder::grant($this->moveTarget, $this->moveActor, RoleRegistry::WRITER, scope: GrantScope::Item);
    Livewire::test(ListNodes::class)->callTableAction('move', (string) $this->moveSource->getKey(), ['parent_id' => $this->moveTarget->getKey()]);
    expect($this->moveSource->fresh()->parent_id)->toBe($this->moveTarget->getKey());
});

<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\DataStore;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Contracts\DriveHandleFactory;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Models\Drive;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Resources\NodeResource\Pages\ListNodes;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Filament\Tests\Fixtures\SpyDriveHandle;

uses(RefreshDatabase::class);

/*
 * V27 — moving a node needs Move on the source AND Create on the destination. A user
 * with Move on folder A (writer) and only read on folder B must not be offered B, and a
 * forged request for B is refused by the operation behind the handle, not by the panel.
 */

beforeEach(function (): void {
    app(DataStore::class)->register();
    $this->v27Calls = new ArrayObject;
    $calls = $this->v27Calls;

    // Wrap every handle the factory builds so the test sees which operations the panel calls.
    app()->extend(DriveHandleFactory::class, static fn (DriveHandleFactory $factory): DriveHandleFactory => new class($factory, $calls) implements DriveHandleFactory
    {
        public function __construct(private readonly DriveHandleFactory $inner, private readonly ArrayObject $calls) {}

        public function forOwner(Model $owner, string $profile): DriveHandle
        {
            return new SpyDriveHandle($this->inner->forOwner($owner, $profile), $this->calls);
        }

        public function forDrive(Drive $drive): DriveHandle
        {
            return new SpyDriveHandle($this->inner->forDrive($drive), $this->calls);
        }

        public function findById(int|string $id): ?DriveHandle
        {
            $handle = $this->inner->findById($id);

            return $handle === null ? null : new SpyDriveHandle($handle, $this->calls);
        }

        public function findForOwner(Model $owner, string $profile): ?DriveHandle
        {
            $handle = $this->inner->findForOwner($owner, $profile);

            return $handle === null ? null : new SpyDriveHandle($handle, $this->calls);
        }
    });

    $actor = DriveBuilder::user();
    $this->actingAs($actor);
    $drive = DriveBuilder::storage();
    DriveBuilder::grant($drive->rootNode()->firstOrFail(), $actor, RoleRegistry::READER, scope: GrantScope::Item);
    $this->v27Source = DriveBuilder::folder($drive, 'A');
    $this->v27Target = DriveBuilder::folder($drive, 'B');
    DriveBuilder::grant($this->v27Source, $actor, RoleRegistry::WRITER, scope: GrantScope::Item);
    DriveBuilder::grant($this->v27Target, $actor, RoleRegistry::READER, scope: GrantScope::Item);
});

it('does not offer a destination folder without Create', function (): void {
    $options = (new ReflectionMethod(NodeResource::class, 'folderOptions'))->invoke(null, DriveBuilder::row($this->v27Source));

    expect($options)->not->toHaveKey((string) $this->v27Target->getKey());
});

it('refuses a forged move into it with permission_denied through the handle', function (): void {
    $parent = $this->v27Source->parent_id;

    // The select would reject an unlisted id at form validation; admit it so the refusal
    // under test is the operation's own.
    Select::configureUsing(
        modifyUsing: fn (Select $select) => $select->in([(string) $this->v27Target->getKey()]),
        during: fn () => Livewire::test(ListNodes::class)->callTableAction('move', (string) $this->v27Source->getKey(), ['parent_id' => (string) $this->v27Target->getKey()]),
        isImportant: true,
    );

    expect($this->v27Source->fresh()->parent_id)->toBe($parent)
        ->and(collect($this->v27Calls)->contains(fn (array $call): bool => $call[0] === 'nodes.move' && (string) $call[1][1] === (string) $this->v27Target->getKey()))->toBeTrue();
    Notification::assertNotified(Notification::make()->title('Move not allowed')->danger());
});

it('moves through the handle once Create is granted on the destination', function (): void {
    DriveBuilder::grant($this->v27Target, DriveBuilder::user(), RoleRegistry::WRITER, scope: GrantScope::Item);

    Livewire::test(ListNodes::class)->callTableAction('move', (string) $this->v27Source->getKey(), ['parent_id' => (string) $this->v27Target->getKey()]);

    expect($this->v27Source->fresh()->parent_id)->toBe($this->v27Target->getKey())
        ->and(collect($this->v27Calls)->pluck(0)->all())->toContain('nodes.move');
});

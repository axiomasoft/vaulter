<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\DataStore;
use Vaulter\Enums\Access\Effect;
use Vaulter\Filament\Resources\NodeResource\Pages\ListNodes;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;

uses(RefreshDatabase::class);

it('hides a denied node from the actual table and shows an allowed node', function (): void {
    app(DataStore::class)->register();
    $actor = DriveBuilder::user();
    $this->actingAs($actor);
    $storage = DriveBuilder::storage();
    $hidden = DriveBuilder::folder($storage, 'Denied');
    $visible = DriveBuilder::folder($storage, 'Visible');
    DriveBuilder::grant($hidden, $actor);
    DriveBuilder::grant($hidden, $actor, effect: Effect::Deny);
    DriveBuilder::grant($visible, $actor);
    Livewire::test(ListNodes::class)->assertCanNotSeeTableRecords([$hidden])->assertCanSeeTableRecords([$visible]);
});

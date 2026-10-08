<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\DataStore;
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
    // Both are candidates through the root's subtree grant; the boundary on the
    // hidden folder cuts it off in the exact check.
    DriveBuilder::grant($storage->rootNode()->firstOrFail(), $actor);
    $hidden->forceFill(['inherit_grants' => false])->save();
    Livewire::test(ListNodes::class)->assertCanNotSeeTableRecords([$hidden])->assertCanSeeTableRecords([$visible]);
});

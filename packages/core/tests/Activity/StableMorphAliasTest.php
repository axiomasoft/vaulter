<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Testing\Activity\ActivityTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Testing\Fixtures\Panels\FakeProject;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\ValueObjects\Events\ActivityTarget;

/**
 * D34: the types of the Vaulter models stored in a polymorphic column are constant aliases, whatever the class name
 * is and whatever morph policy the host runs.
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 7]));
});

afterEach(function (): void {
    Relation::requireMorphMap(false);
    Relation::morphMap([], false);
});

it('stores a node and a drive under their constant alias', function (): void {
    $drive = DriveTestHelpers::drive()->model();
    $root = Node::query()->findOrFail($drive->root_node_id);

    expect($drive->getMorphClass())->toBe('vaulter.drive')
        ->and($root->getMorphClass())->toBe('vaulter.node')
        ->and(ActivityTarget::of($root)->type)->toBe('vaulter.node')
        ->and(ActivityTarget::of($drive)->type)->toBe('vaulter.drive');
});

it('writes the alias as the target type of an activity row', function (): void {
    DriveTestHelpers::drive();

    $row = ActivityTestHelpers::only(DriveEventType::DriveCreated->value);

    expect($row->target_type)->toBe('vaulter.node');
});

it('gives the host subclass configured in vaulter.models the same alias', function (): void {
    $node = new class extends Node {};
    $drive = new class extends Drive {};
    config()->set('vaulter.models.node', $node::class);
    config()->set('vaulter.models.drive', $drive::class);

    expect($node->getMorphClass())->toBe('vaulter.node')
        ->and($drive->getMorphClass())->toBe('vaulter.drive')
        ->and((new (app(VaulterConfig::class)->model('node')))->getMorphClass())->toBe(Node::MORPH_ALIAS);
});

it('is not renamed by a host morph map and is not refused by enforceMorphMap', function (): void {
    // Only the host's own models are mapped: the drive owner and the acting user.
    Relation::enforceMorphMap(['user' => TestUser::class, 'project' => FakeProject::class]);

    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('reports');

    $row = Activity::query()->where('verb', DriveEventType::FolderCreated->value)->where('target_id', $folder->id)->firstOrFail();

    expect($row->target_type)->toBe('vaulter.node')
        ->and(Activity::query()->where('target_type', 'vaulter.drive')->count())->toBe(0);

    Relation::morphMap([Node::class => 'host-node'], true);
    expect((new Node)->getMorphClass())->toBe('vaulter.node');
});

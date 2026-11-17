<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Providers\BindingsRegistrar;
use Vaulter\Drive\Testing\Activity\ActivityTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Testing\Fixtures\Panels\FakeProject;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * The types of the Vaulter models stored in a polymorphic column are constant aliases, whatever the class name
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

it('gives the host subclass configured in vaulter-drive.models the same alias', function (): void {
    $node = new class extends Node {};
    $drive = new class extends Drive {};
    config()->set('vaulter-drive.models.node', $node::class);
    config()->set('vaulter-drive.models.drive', $drive::class);

    expect($node->getMorphClass())->toBe('vaulter.node')
        ->and($drive->getMorphClass())->toBe('vaulter.drive')
        ->and((new (app(DriveOptions::class)->model('node')))->getMorphClass())->toBe(Node::MORPH_ALIAS);
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

it('registers the aliases in the morph map so a host morphTo() resolves them', function (): void {
    $drive = DriveTestHelpers::drive()->model();
    $root = Node::query()->findOrFail($drive->root_node_id);

    expect(Relation::getMorphedModel('vaulter.node'))->toBe(Node::class)
        ->and(Relation::getMorphedModel('vaulter.drive'))->toBe(Drive::class)
        ->and(Relation::requiresMorphMap())->toBeFalse();

    $holder = new class extends Model
    {
        protected $guarded = [];

        public function subject(): MorphTo
        {
            return $this->morphTo('subject');
        }
    };
    $holder->subject_type = 'vaulter.node';
    $holder->subject_id = $root->getKey();

    expect($holder->subject()->getResults()?->getKey())->toBe($root->getKey())
        ->and($holder->subject()->getResults())->toBeInstanceOf(Node::class);
});

it('merges the aliases without erasing a host morph map entry', function (): void {
    Relation::morphMap(['project' => FakeProject::class]);

    // Register again over a map the host already filled: booted() runs the callback at once on a booted app.
    (new BindingsRegistrar)->boot(app());

    expect(Relation::getMorphedModel('project'))->toBe(FakeProject::class)
        ->and(Relation::getMorphedModel('vaulter.node'))->toBe(Node::class);
});

<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\CoreModule;
use Vaulter\Drive\Contracts\NodeSnapshotContributor;
use Vaulter\Drive\Data\Snapshots\DocumentHeadSnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\DriveModule;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Kernel;
use Vaulter\Media\MediaModule;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\SlotContribution;

/**
 * The NodeSnapshotContributor SPI stays supported and model-free. A contributor gets the facts of the page as
 * snapshots (no row, no viewer state, no reader key, no fragment yet) and its fragment joins the snapshot the reader gets,
 * through the Operations and over HTTP alike.
 */
uses(RefreshDatabase::class);

/** Records what the mapper hands it and contributes a document head for every node of the page. */
final class RecordingSnapshotContributor implements NodeSnapshotContributor
{
    /** @var list<list<mixed>> */
    public static array $pages = [];

    public function key(): string
    {
        return 'document';
    }

    public function contribute(array $nodes): array
    {
        self::$pages[] = $nodes;
        $heads = [];
        foreach ($nodes as $node) {
            $heads[$node->id] = new DocumentHeadSnapshot(type: 'recorded', schemaVersion: 3, typeAvailable: true, headRevisionId: null, epoch: 1, stateVersion: 7);
        }

        return $heads;
    }
}

/** Whether $value holds an Eloquent model anywhere in its public properties or array values. */
function contributorInputHoldsModel(mixed $value): bool
{
    return match (true) {
        $value instanceof Model => true,
        is_array($value) => array_filter($value, contributorInputHoldsModel(...)) !== [],
        is_object($value) => array_filter(get_object_vars($value), contributorInputHoldsModel(...)) !== [],
        default => false,
    };
}

beforeEach(function (): void {
    RecordingSnapshotContributor::$pages = [];
    $compiled = (new ModuleCompiler)->compile([new CoreModule, new MediaModule, new DriveModule], []);
    expect($compiled)->toBeInstanceOf(CompiledModules::class);
    $arguments = get_object_vars($compiled);
    $arguments['contributions'][NodeSnapshotContributor::class][] = new SlotContribution(NodeSnapshotContributor::class, 'test.recording', 'drive', RecordingSnapshotContributor::class, 0, 'document');
    $kernel = new Kernel(app());
    $kernel->seal(new CompiledModules(...$arguments));
    app()->instance(Kernel::class, $kernel);

    $this->drive = V1::drive(userId: 1);
    $this->folder = $this->drive->folders()->create('contributed');
    $this->actingAs(V1::user(1));
});

it('hands a contributor model-free base snapshots and puts its fragment into the snapshot the reader gets', function (): void {
    $read = $this->drive->actingAs(V1::user(1))->nodes()->find($this->folder->id);
    $inputs = array_merge(...RecordingSnapshotContributor::$pages);
    $mine = array_values(array_filter($inputs, fn (mixed $node): bool => $node instanceof NodeSnapshot && $node->id === $this->folder->id));

    expect($inputs)->not->toBeEmpty()
        ->and(array_filter($inputs, static fn (mixed $node): bool => !$node instanceof NodeSnapshot))->toBe([])
        ->and(contributorInputHoldsModel(RecordingSnapshotContributor::$pages))->toBeFalse()
        ->and($mine)->not->toBeEmpty()
        // The base facts: no fragment, no viewer state, no reader-scoped key reach a contributor.
        ->and([$mine[0]->document, $mine[0]->viewer, $mine[0]->syncKey])->toBe([null, null, null])
        ->and([$mine[0]->name, $mine[0]->driveId, $mine[0]->sequence])->toBe([$read->name, $read->driveId, $read->sequence])
        // The reader's snapshot keeps its own scope and gains the fragment.
        ->and($read->document?->type)->toBe('recorded')
        ->and($read->document?->stateVersion)->toBe(7)
        ->and($read->viewer)->not->toBeNull();
});

it('keeps the normal read response over HTTP, with the contributed fragment', function (): void {
    $this->getJson(V1::url('nodes/'.$this->folder->id))
        ->assertOk()
        ->assertJsonPath('data.id', $this->folder->id)
        ->assertJsonPath('data.name', 'contributed')
        ->assertJsonPath('data.document.type', 'recorded')
        ->assertJsonPath('data.document.state_version', 7);

    expect(contributorInputHoldsModel(RecordingSnapshotContributor::$pages))->toBeFalse()
        ->and(RecordingSnapshotContributor::$pages)->not->toBeEmpty();
});

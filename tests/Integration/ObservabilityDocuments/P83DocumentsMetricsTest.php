<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vaulter\Collaboration\Persistence\Checkpoint;
use Vaulter\Collaboration\Persistence\CheckpointRequest;
use Vaulter\Collaboration\Persistence\CompactionLeases;
use Vaulter\Collaboration\Persistence\UpdateEnvelope;
use Vaulter\Collaboration\Persistence\UpdateLog;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Export\ExportDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Export\ExportDocumentInput;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Support\LoadTier;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Testing\Fixtures\TestUser;

function p83DocumentNode(): Node
{
    $drive = DocumentTestHelpers::drive();

    return app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Metrics doc',
        content: DocumentTestHelpers::content('Body'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
}

beforeEach(function (): void {
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    $this->app->forgetInstance(MetricsRecorder::class);
});

it('records vaulter_export_duration_seconds', function (): void {
    LoadTier::set('L');

    $node = p83DocumentNode();
    app(ExportDocumentAction::class)->execute(DriveContext::system($node->storage()->firstOrFail(), 'metrics fixture'), new ExportDocumentInput($node, 'html'));

    app(MetricsRecorder::class)->assertHistogram(
        name: 'vaulter_export_duration_seconds',
        labels: ['format' => 'html', 'tier' => 'L'],
    );
    expect(app(MetricsRecorder::class)->emissions())->not->toBeEmpty();
});

it('records vaulter_collab_session_issued_total', function (): void {
    config()->set('vaulter-collaboration.hub.jwt_secret', 'shh-secret-at-least-32-bytes-long-for-hs256');

    $node = p83DocumentNode();
    $actor = new TestUser;
    $actor->forceFill(['id' => 1]);
    $actor->exists = true;
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, subjectId: 1);

    $handle = Vaulter::drive($node->storage()->firstOrFail())->actingAs($actor);
    $handle->extension(DocumentOperations::class)->collaborationSession($node->getKey());

    app(MetricsRecorder::class)->assertCounter(
        name: 'vaulter_collab_session_issued_total',
        labels: [
            'protocol' => 'yjs',
            'ability' => Ability::Edit->value,
        ],
    );
    expect(app(MetricsRecorder::class)->emissions())->not->toBeEmpty();
});

it('records vaulter_collab_snapshot_duration_seconds', function (): void {
    LoadTier::set('S');
    config()->set('vaulter-collaboration.compaction.trigger_updates', 1);
    $node = p83DocumentNode();

    // The snapshot is the checkpoint the hub publishes for the log under a compaction lease.
    app(UpdateLog::class)->append($node->getKey(), new UpdateEnvelope(
        generation: 0, updateId: (string) Str::uuid(), payload: 'binary', claimedSha256: hash('sha256', 'binary'),
        origin: 'server', seedStateVersion: $node->document->state_version,
    ));
    [$lease] = app(CompactionLeases::class)->grant('hub-metrics', 1);
    app(Checkpoint::class)->publish(new CheckpointRequest(
        nodeId: $lease->nodeId,
        generation: 0,
        leaseToken: $lease->token,
        coveredFrom: 0,
        coveredThrough: 1,
        expectedCheckpointVersion: $lease->checkpointVersion,
        state: 'binary',
        binding: $lease->log->binding,
        documentType: $lease->log->type,
        content: DocumentTestHelpers::content('Body')->toArray(),
    ));

    app(MetricsRecorder::class)->assertHistogram(
        name: 'vaulter_collab_snapshot_duration_seconds',
        labels: ['tier' => 'S'],
    );
    expect(app(MetricsRecorder::class)->emissions())->not->toBeEmpty();
});

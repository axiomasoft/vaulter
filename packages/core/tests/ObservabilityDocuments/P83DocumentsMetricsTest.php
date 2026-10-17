<?php

declare(strict_types=1);

use Vaulter\Collaboration\Actions\PersistCollaborationStateAction;
use Vaulter\Collaboration\Data\PersistCollaborationStateInput;
use Vaulter\Collaboration\Operations\CollaborationOperations;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Export\ExportDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Export\ExportDocumentInput;
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
});

it('records vaulter_collab_session_issued_total', function (): void {
    config()->set('vaulter-collaboration.driver', 'hocuspocus');
    config()->set('vaulter-collaboration.hub.jwt_secret', 'shh-secret-at-least-32-bytes-long-for-hs256');

    $node = p83DocumentNode();
    $actor = new TestUser;
    $actor->forceFill(['id' => 1]);
    $actor->exists = true;
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, subjectId: 1);

    $handle = Vaulter::drive($node->storage()->firstOrFail())->actingAs($actor);
    $handle->extension(CollaborationOperations::class)->session($node->getKey());

    app(MetricsRecorder::class)->assertCounter(
        name: 'vaulter_collab_session_issued_total',
        labels: [
            'driver' => config('vaulter-collaboration.driver'),
            'ability' => Ability::Edit->value,
        ],
    );
});

it('records vaulter_collab_snapshot_duration_seconds', function (): void {
    LoadTier::set('S');

    app(PersistCollaborationStateAction::class)->execute(new PersistCollaborationStateInput(
        node: p83DocumentNode(),
        yjsState: 'binary',
        actorId: null,
    ));

    app(MetricsRecorder::class)->assertHistogram(
        name: 'vaulter_collab_snapshot_duration_seconds',
        labels: ['tier' => 'S'],
    );
});

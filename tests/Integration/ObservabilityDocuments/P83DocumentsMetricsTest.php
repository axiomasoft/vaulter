<?php

declare(strict_types=1);

use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Export\ExportDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Export\ExportDocumentInput;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Support\LoadTier;
use Vaulter\Testing\FakeMetricsRecorder;

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

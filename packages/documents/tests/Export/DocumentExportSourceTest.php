<?php

declare(strict_types=1);

use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Exceptions\UnknownExportFormatException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

it('exports the working state with its epoch and state version and no revision id', function (): void {
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $node = $documents->create('Source', content: DocumentTestHelpers::content('one')->toArray());
    $documents->update($node->id, DocumentTestHelpers::content('two')->toArray());
    $stored = DocumentTestHelpers::document(DriveTestHelpers::model($node));

    $export = $documents->export($node->id, 'html');

    expect($export->bytes)->toContain('two')
        ->and($export->sourceRevisionId)->toBeNull()
        ->and($export->sourceStateVersion)->toBe($stored->state_version)
        ->and($export->sourceEpoch)->toBe($stored->collab_epoch)
        ->and($export->format)->toBe('html')
        ->and($export->mimeType)->toBe('text/html')
        ->and($export->filename)->toBe('Source.html');
});

it('exports a chosen checkpoint with its own id and state version', function (): void {
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $node = $documents->create('Source', content: DocumentTestHelpers::content('original')->toArray());
    $first = $documents->revisions($node->id)->items[0];
    $documents->update($node->id, DocumentTestHelpers::content('later')->toArray());

    $export = $documents->export($node->id, 'markdown', $first->id);

    expect($export->bytes)->toContain('original')->not->toContain('later')
        ->and($export->sourceRevisionId)->toBe($first->id)
        ->and($export->sourceStateVersion)->toBe($first->stateVersion)
        ->and($export->filename)->toBe('Source.md');
});

it('never creates a revision or moves the validators', function (): void {
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $node = $documents->create('Source', content: DocumentTestHelpers::content('steady')->toArray());
    $documents->update($node->id, DocumentTestHelpers::content('dirty working state')->toArray());
    $model = DriveTestHelpers::model($node);
    $before = DocumentTestHelpers::document($model)->only(['state_version', 'collab_epoch', 'revision_count', 'current_revision_id', 'checkpoint_state_version']);
    $revisions = DocumentRevision::query()->count();

    $documents->export($node->id, 'html');
    $documents->export($node->id, 'markdown');

    expect(DocumentTestHelpers::document($model)->only(['state_version', 'collab_epoch', 'revision_count', 'current_revision_id', 'checkpoint_state_version']))->toBe($before)
        ->and(DocumentRevision::query()->count())->toBe($revisions);
});

it('refuses a revision of another document', function (): void {
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $one = $documents->create('One', content: DocumentTestHelpers::content('one')->toArray());
    $two = $documents->create('Two', content: DocumentTestHelpers::content('two')->toArray());
    $foreign = $documents->revisions($two->id)->items[0];

    expect(fn () => $documents->export($one->id, 'html', $foreign->id))->toThrow(DocumentRevisionNotFoundException::class);
});

it('flags an export of content its type could only read partly as lossy', function (): void {
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $node = $documents->create('Partly readable', content: DocumentTestHelpers::content('kept')->toArray());
    $model = DriveTestHelpers::model($node);
    // A node type the schema does not know: the read path drops it and reports the loss.
    $stored = DocumentTestHelpers::document($model);
    app(VaulterDatabase::class)->write(fn () => $stored->forceFill(['content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'kept']]],
        ['type' => 'hostWidgetNode', 'attrs' => ['x' => 1]],
    ]]])->save());

    expect($documents->export($node->id, 'html')->lossy)->toBeTrue();

    $whole = $documents->create('Whole', content: DocumentTestHelpers::content('intact')->toArray());
    expect($documents->export($whole->id, 'html')->lossy)->toBeFalse();
});

it('labels the export metric only with a format an exporter renders', function (): void {
    $recorded = [];
    app()->instance(MetricsRecorder::class, new class($recorded) implements MetricsRecorder
    {
        /** @param list<array<string, mixed>> $recorded */
        public function __construct(public array &$recorded) {}

        public function counter(string $name, int|float $amount = 1, array $labels = []): void {}

        public function gauge(string $name, int|float $value, array $labels = []): void {}

        public function histogram(string $name, int|float $value, array $labels = []): void {}

        public function timing(string $name, callable $operation, array $labels = []): mixed
        {
            if ($name === 'vaulter_export_duration_seconds') {
                $this->recorded[] = $labels;
            }

            return $operation();
        }
    });
    $handle = DocumentTestHelpers::drive();
    $documents = Documents::in($handle);
    $node = $documents->create('Labelled', content: DocumentTestHelpers::content('one')->toArray());

    $documents->export($node->id, 'markdown');
    foreach (['pdf-'.str_repeat('x', 40), '<script>'] as $junk) {
        try {
            $documents->export($node->id, $junk);
        } catch (UnknownExportFormatException) {
            // refused — the point is the label the refused call was recorded under
        }
    }

    expect(array_column($recorded, 'format'))->toBe(['markdown', 'unknown', 'unknown']);
});

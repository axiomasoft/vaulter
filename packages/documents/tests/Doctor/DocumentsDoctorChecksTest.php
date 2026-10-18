<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Doctor\DoctorRunner;
use Vaulter\Doctor\DoctorSeverity;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

beforeEach(fn () => DriveTestHelpers::insideOperation());

/** @return list<DoctorFinding> */
function documentsFindings(string $key, bool $production = false): array
{
    return app(DoctorRunner::class)->run(new DoctorContext(production: $production, probeStorage: false, sampleLimit: 1000, now: CarbonImmutable::now('UTC')), [$key])->checks[0]->findings;
}

it('finds the default type registered and no stored document of an unknown type', function (): void {
    expect(array_map(static fn (DoctorFinding $finding): string => $finding->code, documentsFindings('documents.types')))
        ->toBe(['documents.types_default_ok', 'documents.types_known']);
});

it('warns about an unregistered default type', function (): void {
    config(['vaulter-documents.default_type' => 'acme/missing']);

    $finding = documentsFindings('documents.types')[0];

    expect($finding->code)->toBe('documents.types_default_unregistered')
        ->and($finding->severity)->toBe(DoctorSeverity::Warning)
        ->and(documentsFindings('documents.types', production: true)[0]->severity)->toBe(DoctorSeverity::Warning);
});

it('counts the documents whose type is not registered', function (): void {
    $drive = DocumentTestHelpers::drive();
    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Spec',
        content: DocumentTestHelpers::content('First draft'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
    DB::table((new Document)->getTable())->where('node_id', $node->getKey())->update(['type' => 'acme/kanban']);

    $finding = documentsFindings('documents.types')[1];

    expect($finding->code)->toBe('documents.types_unknown')
        ->and($finding->severity)->toBe(DoctorSeverity::Warning)
        ->and($finding->evidence)->toBe(['documents' => 1, 'types' => 'acme/kanban']);
});

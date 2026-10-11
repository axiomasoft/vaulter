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
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(fn () => DriveTestHelpers::insideOperation());

/** @return list<DoctorFinding> */
function documentsFindings(string $key, bool $production = false): array
{
    return app(DoctorRunner::class)->run(new DoctorContext(production: $production, probeStorage: false, sampleLimit: 1000, now: CarbonImmutable::now('UTC')), [$key])->checks[0]->findings;
}

function enableCollaboration(array $hub = []): void
{
    config([
        'vaulter-documents.collaboration.enabled' => true,
        'vaulter-documents.collaboration.hub' => [
            'url' => 'wss://hub.example.test', 'jwt_secret' => 'jwt-secret-value', 'webhook_secret' => 'webhook-secret-value', 'token_ttl' => 3600,
            ...$hub,
        ],
    ]);
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

it('skips collaboration while it is disabled', function (): void {
    $finding = documentsFindings('documents.collaboration')[0];

    expect($finding->code)->toBe('documents.collaboration_disabled')->and($finding->severity)->toBe(DoctorSeverity::Ok);
});

it('accepts complete collaboration settings and never prints a secret', function (): void {
    enableCollaboration();

    $findings = documentsFindings('documents.collaboration', production: true);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('documents.collaboration_ok')
        ->and($findings[0]->evidence)->toBe(['enabled' => true, 'jwt_secret_set' => true, 'webhook_secret_set' => true, 'hub_url_set' => true])
        ->and(json_encode($findings))->not->toContain('jwt-secret-value')->not->toContain('webhook-secret-value');
});

it('reports missing and equal secrets as errors', function (): void {
    enableCollaboration(['jwt_secret' => null]);
    expect(documentsFindings('documents.collaboration')[0]->code)->toBe('documents.collaboration_secrets_missing');

    enableCollaboration(['jwt_secret' => 'same', 'webhook_secret' => 'same']);
    $finding = documentsFindings('documents.collaboration')[0];
    expect($finding->code)->toBe('documents.collaboration_secrets_equal')
        ->and($finding->severity)->toBe(DoctorSeverity::Error)
        ->and(json_encode($finding))->not->toContain('"same"');
});

it('requires wss:// in production only', function (): void {
    enableCollaboration(['url' => 'ws://hub.example.test']);

    expect(documentsFindings('documents.collaboration')[0]->code)->toBe('documents.collaboration_ok');

    $finding = documentsFindings('documents.collaboration', production: true)[0];
    expect($finding->code)->toBe('documents.collaboration_url_insecure')->and($finding->severity)->toBe(DoctorSeverity::Error);

    enableCollaboration(['url' => null]);
    expect(documentsFindings('documents.collaboration')[0]->code)->toBe('documents.collaboration_url_missing');
});

it('refuses a room token lifetime above an hour', function (): void {
    enableCollaboration(['token_ttl' => 7200]);

    expect(documentsFindings('documents.collaboration')[0]->code)->toBe('documents.collaboration_token_ttl');
});

it('lists every collaboration problem, not only the first', function (): void {
    enableCollaboration(['jwt_secret' => null, 'url' => null, 'token_ttl' => 0]);

    $codes = array_map(static fn (DoctorFinding $finding): string => $finding->code, documentsFindings('documents.collaboration'));

    expect($codes)->toBe(['documents.collaboration_secrets_missing', 'documents.collaboration_url_missing', 'documents.collaboration_token_ttl']);
});

it('repeats the documents configuration validation as one of the config validators', function (): void {
    enableCollaboration(['jwt_secret' => 'same', 'webhook_secret' => 'same']);

    $finding = documentsFindings('core.config.valid')[0];

    expect($finding->severity)->toBe(DoctorSeverity::Error)
        ->and($finding->evidence['validator'])->toBe(VaulterDocumentsServiceProvider::class);
});

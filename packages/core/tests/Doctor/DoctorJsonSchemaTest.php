<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

function doctorJson(array $options = []): object
{
    Artisan::call('vaulter:doctor', ['--json' => true, ...$options]);

    return json_decode(Artisan::output(), false, 512, JSON_THROW_ON_ERROR);
}

it('prints one document that validates against doctor-v1.schema.json', function (): void {
    $document = doctorJson();
    $schema = json_decode((string) file_get_contents(__DIR__.'/../../resources/schemas/doctor-v1.schema.json'), false, 512, JSON_THROW_ON_ERROR);

    $result = (new Validator)->validate($document, $schema);
    $error = $result->error();

    expect($result->isValid())->toBeTrue($error === null ? '' : json_encode((new ErrorFormatter)->format($error)))
        ->and($document->schema)->toBe('vaulter.doctor/v1')
        ->and($document->production)->toBeFalse()
        ->and($document->checks)->not->toBeEmpty();
});

it('lists the checks by key and agrees with its own summary and exit code', function (): void {
    $document = doctorJson();
    $keys = array_map(static fn (object $check): string => $check->key, $document->checks);
    $sorted = $keys;
    sort($sorted, SORT_STRING);
    $counts = ['ok' => 0, 'warning' => 0, 'error' => 0];
    foreach ($document->checks as $check) {
        foreach ($check->findings as $finding) {
            $counts[$finding->severity]++;
        }
    }

    expect($keys)->toBe($sorted)
        ->and($keys)->toContain('core.database.connection', 'core.schema.tables', 'core.modules.cache')
        // The opt-in webhooks module is off by default: it contributes no check (D22).
        ->and($keys)->not->toContain('core.webhooks.deliveries')
        ->and((array) $document->summary)->toBe($counts)
        ->and($document->exit_code)->toBe($counts['error'] > 0 ? 1 : 0);
});

it('marks the production run', function (): void {
    expect(doctorJson(['--production' => true])->production)->toBeTrue();
});

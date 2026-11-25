<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Drive\Exceptions\Uploads\OffsetMismatchException;
use Vaulter\Drive\Exceptions\Uploads\StagedUploadMissingException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Exceptions\Uploads\UploadContentMismatchException;
use Vaulter\Drive\Http\Problems\DriveProblemCodes;
use Vaulter\Drive\Http\Problems\DriveSliceProblemCodes;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Testing\Support\ProblemProbeRoutes;

it('answers every drive domain exception as problem+json with its stable code', function (Throwable $exception, string $code, int $status): void {
    ProblemProbeRoutes::register(['case' => $exception]);

    $response = $this->getJson(ProblemProbeRoutes::path('case'))
        ->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', $code)
        ->assertJsonPath('type', 'https://github.com/axiomasoft/vaulter/blob/main/docs/reference/errors.md#'.$code);

    expect($response->json('title'))->toBeString()->not->toBe('')
        ->and($response->getContent())->not->toContain('[Vaulter]');
})->with([
    'move cycle' => [NodeCycleException::forMove(1, 2), 'node_move_cycle', 409],
    'cross drive' => [CrossDriveOperationException::between(1, 2), 'cross_drive_operation', 409],
    'upload conflict' => [UploadConflictException::forSession('upload'), 'upload_conflict', 409],
    'staged upload missing' => [StagedUploadMissingException::forSession('upload', 7), 'upload_conflict', 409],
    'offset mismatch' => [new OffsetMismatchException(serverOffset: 7), 'upload_offset_mismatch', 409],
    'mime mismatch' => [UploadContentMismatchException::forSession('upload', 'image/png'), 'upload_rejected', 422],
]);

it('keeps the tus resume headers on an offset mismatch', function (): void {
    ProblemProbeRoutes::register(['offset' => new OffsetMismatchException(serverOffset: 7)]);

    $this->getJson(ProblemProbeRoutes::path('offset'))
        ->assertStatus(409)
        ->assertHeader('Upload-Offset', '7')
        ->assertHeader('Tus-Resumable', '1.0.0');
});

it('carries the rejection reason as meta', function (): void {
    ProblemProbeRoutes::register(['mime' => UploadContentMismatchException::forSession('upload', 'image/png')]);

    $this->getJson(ProblemProbeRoutes::path('mime'))->assertJsonPath('meta.reason', 'mime_mismatch');
});

it('registers the drive codes with the catalogue and the drive lang namespace', function (): void {
    $catalog = $this->app->make(ProblemCatalog::class);
    $codes = array_keys((new DriveProblemCodes)->codes());

    expect($codes)->not->toBeEmpty();
    foreach ($codes as $code) {
        expect($catalog->all())->toHaveKey($code)
            ->and($catalog->all()[$code]['module'])->toBe('drive');
        foreach (['en', 'ru'] as $locale) {
            expect($catalog->title($code, $locale))->toBeString($locale.' '.$code);
        }
    }
});

it('declares the drive codes located in core under the drive module', function (): void {
    $catalog = $this->app->make(ProblemCatalog::class);

    foreach (array_keys((new DriveSliceProblemCodes)->codes()) as $code) {
        expect($catalog->module($code))->toBe('drive', $code);
    }
});

it('says whether a drive refusal may be retried', function (): void {
    ProblemProbeRoutes::register(['offset' => new OffsetMismatchException(serverOffset: 7), 'cycle' => NodeCycleException::forMove(1, 2)]);

    $this->getJson(ProblemProbeRoutes::path('offset'))->assertJsonPath('retryable', 'after_refetch');
    $this->getJson(ProblemProbeRoutes::path('cycle'))->assertJsonPath('retryable', 'never');
});

it('does not render a drive exception as problem+json outside the versioned prefix', function (): void {
    Route::get('/host/cycle', static function (): never {
        throw NodeCycleException::forMove(1, 2);
    });

    $response = $this->getJson('/host/cycle');

    expect($response->headers->get('Content-Type'))->not->toContain('problem+json');
});

<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\MassAssignmentException;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;

/**
 * Mass-assignment hardening (P1.4 / B13): UploadSession/UploadPart used to carry
 * `$guarded = []` (F-P1.8-02). received_bytes is the AUTHORITATIVE server-side tus
 * offset (F-P1.4-01) — mass-assigning it via a future `fill($request->all())` would
 * let a caller forge the resume point and re-open the offset-desync class of bug
 * P0.3 already closed.
 *
 * Red-before: prior to this item both models declared `protected $guarded = [];`,
 * so `fill()` accepted every key below.
 */
it('never mass-assigns received_bytes on UploadSession via fill() (F-P1.8-02)', function (): void {
    $session = new UploadSession;
    $session->fill([
        'received_bytes' => 999_999_999,
        'status' => UploadStatus::Committed,
        'target_name' => 'legit-name.bin',
    ]);

    expect($session->getAttributes())->not->toHaveKey('received_bytes')
        ->and($session->getAttributes())->not->toHaveKey('status')
        ->and($session->target_name)->toBe('legit-name.bin');
});

it('rejects ANY fill() attribute on UploadPart with MassAssignmentException (F-P1.8-02)', function (): void {
    // UploadPart is fully $guarded = ['*'] — Eloquent's "totally guarded" state
    // throws on any fill(), a stronger guarantee than a silent discard (its
    // production write path is DB::table()->insert()/update() in
    // UploadPartStoreRepository, never Eloquent mass-assignment).
    $part = new UploadPart;

    expect(fn () => $part->fill([
        'upload_session_id' => 999,
        'part_no' => 1,
        'etag' => 'attacker-forged-etag',
    ]))->toThrow(MassAssignmentException::class);

    expect(fn () => UploadPart::query()->create([
        'upload_session_id' => 999,
        'part_no' => 1,
    ]))->toThrow(MassAssignmentException::class);
});

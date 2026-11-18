<?php

declare(strict_types=1);

use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Exceptions\Idempotency\OperationInProgressException;
use Vaulter\Idempotency\IdempotencyStore;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Integration\Tests\Idempotency\Fixtures\EchoReceiptCodec;
use Vaulter\Integration\Tests\Idempotency\Fixtures\RecordingReplayAuthorizer;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;

/**
 * Idempotency under real concurrency (PostgreSQL, forked processes on their own connections): two requests with
 * one key make one effect — the second waits on the uncommitted claim and replays the receipt — and a claim held
 * longer than the bounded wait answers the second request 409 operation_in_progress with Retry-After, without
 * continuing an aborted transaction.
 */
it('makes one effect of two concurrent requests with one key; the second replays it', function (): void {
    ForkRace::skipUnlessSupported($this);
    $drive = DriveTestHelpers::drive();
    // Both children act as the same system actor on the same drive: one scope digest.
    $tasks = array_fill(0, 2, static fn (): string => $drive->withIdempotencyKey('race-1')->folders()->create('Raced')->id);

    $outcomes = ForkRace::run($tasks);

    expect(array_map(static fn (RaceOutcome $o): bool => $o->ok, $outcomes))->toBe([true, true], implode('; ', array_map(static fn (RaceOutcome $o): string => (string) $o->message, $outcomes)))
        ->and($outcomes[0]->value)->toBe($outcomes[1]->value)
        ->and(Node::query()->where('name', 'Raced')->count())->toBe(1)
        ->and(app(VaulterDatabase::class)->connection()->table(app(DatabaseOptions::class)->table(IdempotencyStore::ENTITY))->count())->toBe(1);
});

it('answers 409 operation_in_progress when the claim is held past the bounded wait', function (): void {
    ForkRace::skipUnlessSupported($this);
    $drive = DriveTestHelpers::drive();
    $write = static function (int $holdMs, int $delayMs) use ($drive): string {
        usleep($delayMs * 1000);
        $context = DriveContext::system($drive->model(), 'race', 'corr-race')->operation->withIdempotencyKey('held');

        return app(OperationRunner::class)->write(
            new OperationSpec('drive.folders.create', new ModuleId('drive')),
            $context,
            static function () use ($holdMs): string {
                usleep($holdMs * 1000);

                return 'owner';
            },
            new IdempotentWrite(new EchoReceiptCodec, new RecordingReplayAuthorizer, ['input' => 'held']),
        );
    };

    $outcomes = ForkRace::run([
        static fn (): string => $write(IdempotencyStore::WAIT_SECONDS * 1000 + 1500, 0),
        static function () use ($write): string {
            try {
                return $write(0, 300);
            } catch (OperationInProgressException $busy) {
                return 'busy:'.$busy->problemHeaders()['Retry-After'];
            }
        },
    ], timeoutMs: 15_000);

    expect($outcomes[0]->ok)->toBeTrue((string) $outcomes[0]->message)
        ->and($outcomes[0]->value)->toBe('owner')
        ->and($outcomes[1]->ok)->toBeTrue((string) $outcomes[1]->message)
        ->and($outcomes[1]->value)->toBe('busy:1');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\Tests\Concurrency\RaceOutcome;
use Vaulter\Tests\Drive\DriveTestHelpers;

it('V20 creates one x.pdf and returns a domain conflict to the second writer', function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::fake('local');
    $drive = DriveTestHelpers::drive();
    $outcomes = ForkRace::run([
        static fn (): string => (string) $drive->upload(DriveTestHelpers::streamFor('first'), originalName: 'x.pdf')->id,
        static fn (): string => (string) $drive->upload(DriveTestHelpers::streamFor('second'), originalName: 'x.pdf')->id,
    ]);
    $won = array_values(array_filter($outcomes, static fn (RaceOutcome $o): bool => $o->ok));
    $lost = array_values(array_filter($outcomes, static fn (RaceOutcome $o): bool => !$o->ok));
    expect($won)->toHaveCount(1)->and($lost)->toHaveCount(1);
    expect($lost[0]->failedWith(NodeNameConflictException::class))->toBeTrue($lost[0]->message ?? '');
    expect(Node::query()->where('name', 'x.pdf')->count())->toBe(1)
        ->and(Blob::query()->count())->toBe(1);
});

<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Database\WriteOutsideOperationException;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;

/**
 * PLAN2.P4.13 (D44): a Vaulter model cannot be written outside an operation. The base
 * test case switches the guard off; this file turns it on.
 */
beforeEach(function (): void {
    $this->drive = DriveTestHelpers::drive();
    $this->folder = $this->drive->folders()->create('Reports');
    config()->set('vaulter.database.write_guard', true);
});

it('refuses to update a node through a mass query outside an operation', function (): void {
    expect(fn () => Node::query()->whereKey($this->folder->id)->update(['name' => 'x']))
        ->toThrow(WriteOutsideOperationException::class);

    expect(Node::query()->whereKey($this->folder->id)->value('name'))->toBe('Reports');
});

it('keeps the guard on a fresh statement builder and its descendants', function (): void {
    $query = Node::query()->toBase()->newQuery()->newQuery()->from((new Node)->getTable());

    expect(fn () => $query->where('id', $this->folder->id)->update(['name' => 'Bypassed']))
        ->toThrow(WriteOutsideOperationException::class)
        ->and(Node::query()->whereKey($this->folder->id)->value('name'))->toBe('Reports');

    app(VaulterDatabase::class)->write(fn () => $query->update(['size_bytes' => 8]));
    expect((int) Node::query()->whereKey($this->folder->id)->value('size_bytes'))->toBe(8);
});

it('guards insert-or-ignore with returning on framework versions that provide it', function (): void {
    if (!method_exists(Builder::class, 'insertOrIgnoreReturning')) {
        $this->markTestSkipped('This Laravel version has no insertOrIgnoreReturning statement.');
    }

    $query = Node::query()->toBase();
    expect(fn () => $query->insertOrIgnoreReturning(['id' => $this->folder->id]))
        ->toThrow(WriteOutsideOperationException::class);
});

it('refuses to save, create and delete a model outside an operation', function (): void {
    $node = Node::query()->findOrFail($this->folder->id);
    $node->size_bytes = 5;

    expect(fn () => $node->save())->toThrow(WriteOutsideOperationException::class)
        ->and(fn () => $node->delete())->toThrow(WriteOutsideOperationException::class)
        ->and(fn () => Node::query()->whereKey($this->folder->id)->delete())->toThrow(WriteOutsideOperationException::class)
        ->and(fn () => Node::query()->whereKey($this->folder->id)->increment('sequence'))->toThrow(WriteOutsideOperationException::class);
});

it('reports the integration error with its machine code', function (): void {
    try {
        Node::query()->whereKey($this->folder->id)->update(['name' => 'x']);
    } catch (WriteOutsideOperationException $exception) {
        expect($exception->problemCode())->toBe('write_outside_operation')->and($exception->httpStatus())->toBe(500);

        return;
    }

    $this->fail('The write was not refused.');
});

it('lets a write through inside a Vaulter transaction or write scope', function (): void {
    $database = app(VaulterDatabase::class);

    $database->transaction(fn () => Node::query()->whereKey($this->folder->id)->update(['size_bytes' => 7]));
    $database->write(fn () => Node::query()->whereKey($this->folder->id)->update(['size_bytes' => 8]));

    expect((int) Node::query()->whereKey($this->folder->id)->value('size_bytes'))->toBe(8);
});

it('closes the scope when the callback fails', function (): void {
    $database = app(VaulterDatabase::class);

    expect(fn () => $database->transaction(fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
    expect(fn () => Node::query()->whereKey($this->folder->id)->update(['name' => 'x']))->toThrow(WriteOutsideOperationException::class);
});

it('does not guard reads, raw statements of the host or a disabled guard', function (): void {
    expect(Node::query()->whereKey($this->folder->id)->exists())->toBeTrue();

    DB::table((new Node)->getTable())->where('id', $this->folder->id)->update(['size_bytes' => 3]);
    expect((int) Node::query()->whereKey($this->folder->id)->value('size_bytes'))->toBe(3);

    config()->set('vaulter.database.write_guard', false);
    Node::query()->whereKey($this->folder->id)->update(['size_bytes' => 4]);
    expect((int) Node::query()->whereKey($this->folder->id)->value('size_bytes'))->toBe(4);
});

it('runs every operation under the guard', function (): void {
    $file = $this->drive->folders()->create('Inner', $this->folder);
    $renamed = $this->drive->nodes()->rename($file, 'Renamed');
    $trashed = $this->drive->nodes()->trash($renamed);
    $this->drive->trash()->purge($trashed);

    expect(Node::query()->whereKey($file->id)->exists())->toBeFalse();
});

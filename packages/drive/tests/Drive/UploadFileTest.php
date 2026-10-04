<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Events\FileUploaded;
use Vaulter\Drive\Events\NodeVersionCommitted;
use Vaulter\Drive\Exceptions\QuotaExceededException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

it('загружает файл: node kind=file + File + FileRevision + ребро', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->upload(DriveTestHelpers::streamFor('hello drive'), mimeType: 'text/plain', originalName: 'hello.txt');

    expect($node->kind)->toBe(NodeKind::File)
        ->and($node->name)->toBe('hello.txt')
        ->and($node->extension)->toBe('txt')
        ->and($node->mime_type)->toBe('text/plain')
        ->and($node->size_bytes)->toBe(11)
        ->and($node->file_id)->not->toBeNull()
        ->and($node->current_version_id)->not->toBeNull()
        ->and(FileRevision::query()->count())->toBe(1)
        ->and(Blob::query()->count())->toBe(1);
});

it('дедуп: два одинаковых файла → один блоб, два узла, два ребра', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->upload(DriveTestHelpers::streamFor('same bytes'), originalName: 'a.bin');
    $b = $drive->upload(DriveTestHelpers::streamFor('same bytes'), originalName: 'b.bin');

    expect(Blob::query()->count())->toBe(1)
        ->and(Node::query()->where('kind', NodeKind::File->value)->count())->toBe(2);

    $blob = Blob::query()->first();

    expect($blob->versions()->count())->toBe(2)
        ->and($a->getKey())->not->toEqual($b->getKey());
});

it('увеличивает used_bytes в рамках транзакции загрузки', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->upload(DriveTestHelpers::streamFor('1234567890'), originalName: 'x.bin');

    expect($drive->storage()->refresh()->used_bytes)->toBe(10);
});

it('квота: загрузка сверх квоты отклоняется ДО коммита блоба', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->storage()->forceFill(['quota_bytes' => 5])->save();

    expect(fn (): Node => $drive->upload(DriveTestHelpers::streamFor('too many bytes'), originalName: 'x.bin'))
        ->toThrow(QuotaExceededException::class);

    expect(Blob::query()->count())->toBe(0)
        ->and(Node::query()->where('kind', NodeKind::File->value)->count())->toBe(0)
        ->and($drive->storage()->refresh()->used_bytes)->toBe(0);
});

it('новая версия: бамп number, перенаведение head, прежняя superseded', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->upload(DriveTestHelpers::streamFor('v1'), originalName: 'doc.txt');
    $firstVersionId = $node->current_version_id;

    $updated = $drive->uploadVersion($node, DriveTestHelpers::streamFor('version two longer'), originalName: 'doc.txt');

    $file = $updated->file;

    expect($file->revision_count)->toBe(2)
        ->and($updated->current_version_id)->not->toEqual($firstVersionId)
        ->and(FileRevision::query()->find($firstVersionId)->status)->toBe('superseded')
        ->and($file->currentVersion->number)->toBe(2);
});

it('новая версия корректирует used_bytes на дельту размера', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->upload(DriveTestHelpers::streamFor('aaaa'), originalName: 'doc.txt'); // 4 bytes
    expect($drive->storage()->refresh()->used_bytes)->toBe(4);

    $drive->uploadVersion($node, DriveTestHelpers::streamFor('aaaaaaaaaa'), originalName: 'doc.txt'); // 10 bytes

    // delta = 10 − 4 = 6 → total 10 (the live head size, not the sum of versions).
    expect($drive->storage()->refresh()->used_bytes)->toBe(10);
});

it('диспатчит FileUploaded для нового файла и NodeVersionCommitted для версии', function (): void {
    Event::fake([FileUploaded::class, NodeVersionCommitted::class]);

    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(DriveTestHelpers::streamFor('v1'), originalName: 'doc.txt');

    Event::assertDispatched(FileUploaded::class);

    $drive->uploadVersion($node, DriveTestHelpers::streamFor('v2 longer'), originalName: 'doc.txt');

    Event::assertDispatched(NodeVersionCommitted::class);
});

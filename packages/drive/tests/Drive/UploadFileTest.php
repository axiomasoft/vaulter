<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Events\Files\FileRevisionAdded;
use Vaulter\Drive\Events\Files\FileUploaded;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('загружает файл: node kind=file + File + FileRevision + ребро', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('hello drive'), 'hello.txt'), new UploadOptions(declaredMimeType: 'text/plain'));

    expect($node->kind)->toBe(NodeKind::File)
        ->and($node->name)->toBe('hello.txt')
        ->and($node->extension)->toBe('txt')
        ->and($node->mime_type)->toBe('text/plain')
        ->and($node->size_bytes)->toBe(11)
        ->and($node->file()->value('id'))->not->toBeNull()
        ->and($node->file()->value('current_revision_id'))->not->toBeNull()
        ->and(FileRevision::query()->count())->toBe(1)
        ->and(Blob::query()->count())->toBe(1);
});

it('дедуп: два одинаковых файла → один блоб, два узла, два ребра', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('same bytes'), 'a.bin'));
    $b = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('same bytes'), 'b.bin'));

    expect(Blob::query()->count())->toBe(1)
        ->and(Node::query()->where('kind', NodeKind::File->value)->count())->toBe(2);

    $blob = Blob::query()->first();

    expect($blob->versions()->count())->toBe(2)
        ->and($a->getKey())->not->toEqual($b->getKey());
});

it('увеличивает used_bytes в рамках транзакции загрузки', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('1234567890'), 'x.bin'));

    expect($drive->model()->refresh()->used_bytes)->toBe(10);
});

it('квота: загрузка сверх квоты отклоняется ДО коммита блоба', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->model()->forceFill(['quota_bytes' => 5])->save();

    expect(fn (): Node => $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('too many bytes'), 'x.bin')))
        ->toThrow(QuotaExceededException::class);

    expect(Blob::query()->count())->toBe(0)
        ->and(Node::query()->where('kind', NodeKind::File->value)->count())->toBe(0)
        ->and($drive->model()->refresh()->used_bytes)->toBe(0);
});

it('новая версия: бамп number, перенаведение head, прежняя superseded', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1'), 'doc.txt'));
    $firstVersionId = $node->file()->value('current_revision_id');

    $updated = $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('version two longer'), 'doc.txt'));
    $updated = $node->refresh();

    $file = $updated->file;

    expect($file->revision_count)->toBe(2)
        ->and($updated->file()->value('current_revision_id'))->not->toEqual($firstVersionId)
        ->and(FileRevision::query()->find($firstVersionId)->status)->toBe('superseded')
        ->and($file->currentRevision->number)->toBe(2);
});

it('новая версия добавляет к used_bytes свой полный размер', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('aaaa'), 'doc.txt')); // 4 bytes
    expect($drive->model()->refresh()->used_bytes)->toBe(4);

    $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('aaaaaaaaaa'), 'doc.txt')); // 10 bytes

    // Every retained revision counts (PLAN2.D48): 4 + 10.
    expect($drive->model()->refresh()->used_bytes)->toBe(14);
});

it('диспатчит FileUploaded для нового файла и NodeVersionCommitted для версии', function (): void {
    Event::fake([FileUploaded::class, FileRevisionAdded::class]);

    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1'), 'doc.txt'));

    Event::assertDispatched(FileUploaded::class);

    $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('v2 longer'), 'doc.txt'));

    Event::assertDispatched(FileRevisionAdded::class);
});

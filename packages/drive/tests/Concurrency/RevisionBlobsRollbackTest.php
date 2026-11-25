<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\RevisionBlobLinks;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Models\Cas\Blob;

beforeEach(fn () => DriveTestHelpers::insideOperation());

// Runs on COMMITTED rows (DatabaseMigrations, see tests/Pest.php): the rollback drops a table, which a wrapping test
// transaction must not hold (MySQL commits on DDL).

beforeEach(fn () => Storage::fake('local'));

function revisionBlobsMigration(): Migration
{
    return require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000205_create_v_file_revision_blobs_table.php';
}

it('releases the drive pins and only them before the revision edge table is dropped (P7)', function (): void {
    DriveTestHelpers::drive()->files()->upload(UploadSource::fromString('rolled back', 'a.txt'));
    $blob = Blob::query()->firstOrFail();
    app(VaulterDatabase::class)->transaction(fn () => app(BlobPins::class)->pin($blob->getKey(), 'documents.attachment', 'keep-me'));
    expect(app(BlobPins::class)->countByOwner())->toBe(['documents.attachment' => 1, RevisionBlobLinks::OWNER_TYPE => 1]);

    revisionBlobsMigration()->down();

    expect(Schema::hasTable(app(DatabaseOptions::class)->table('file_revision_blobs')))->toBeFalse()
        ->and(app(BlobPins::class)->countByOwner())->toBe(['documents.attachment' => 1]);

    revisionBlobsMigration()->up();
    expect(Schema::hasTable(app(DatabaseOptions::class)->table('file_revision_blobs')))->toBeTrue();
});

it('rolls back an empty edge table without a pin to release', function (): void {
    revisionBlobsMigration()->down();

    expect(app(BlobPins::class)->countByOwner())->toBe([]);

    revisionBlobsMigration()->up();
});

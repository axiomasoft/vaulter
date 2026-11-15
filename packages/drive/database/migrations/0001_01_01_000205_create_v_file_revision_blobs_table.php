<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\RevisionBlobLinks;
use Vaulter\Drive\Schema\DriveSchema;
use Vaulter\Media\Contracts\Cas\BlobPins;

return new class extends Migration
{
    public function up(): void
    {
        app(DriveSchema::class)->create(entity: 'file_revision_blobs');
    }

    public function down(): void
    {
        // The edges go with the table, so their media pins go first: a pin without its owner row would keep the
        // blob forever. Only the drive's own owner type is released.
        app(VaulterDatabase::class)->transaction(static fn (): int => app(BlobPins::class)->releaseOwner(RevisionBlobLinks::OWNER_TYPE));

        app(DriveSchema::class)->drop(entity: 'file_revision_blobs');
    }
};

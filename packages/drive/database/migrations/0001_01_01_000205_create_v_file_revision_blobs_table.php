<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\RevisionBlobLinks;
use Vaulter\Drive\Schema\DriveSchema;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        Container::getInstance()->make(DriveSchema::class)->create(entity: 'file_revision_blobs');
    }

    public function down(): void
    {
        // The edges go with the table, so their media pins go first: a pin without its owner row would keep the
        // blob forever. Only the drive's own owner type is released.
        Container::getInstance()->make(VaulterDatabase::class)->transaction(
            static fn (): int => Container::getInstance()->make(BlobPins::class)->releaseOwner(RevisionBlobLinks::OWNER_TYPE),
        );

        Container::getInstance()->make(DriveSchema::class)->drop(entity: 'file_revision_blobs');
    }
};

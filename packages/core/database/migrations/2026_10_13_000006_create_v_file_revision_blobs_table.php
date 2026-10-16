<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Drive\Schema\DriveSchema;

return new class extends Migration
{
    public function up(): void
    {
        // Until the migrations move into their packages (P4.5), a host without vaulter-drive has no schema class to call.
        if (!class_exists(DriveSchema::class)) {
            return;
        }

        app(DriveSchema::class)->create(entity: 'file_revision_blobs');
    }

    public function down(): void
    {
        if (!class_exists(DriveSchema::class)) {
            return;
        }

        app(DriveSchema::class)->drop(entity: 'file_revision_blobs');
    }
};

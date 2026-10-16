<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Media\Schema\MediaSchema;

return new class extends Migration
{
    public function up(): void
    {
        // Until the migrations move into their packages (P4.5), a host without vaulter-media has no schema class to call.
        if (!class_exists(MediaSchema::class)) {
            return;
        }

        app(MediaSchema::class)->create(entity: 'blobs');
    }

    public function down(): void
    {
        if (!class_exists(MediaSchema::class)) {
            return;
        }

        app(MediaSchema::class)->drop(entity: 'blobs');
    }
};

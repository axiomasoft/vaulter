<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Media\Schema\MediaSchema;

return new class extends Migration
{
    public function up(): void
    {
        app(MediaSchema::class)->create(entity: 'blobs');
    }

    public function down(): void
    {
        app(MediaSchema::class)->drop(entity: 'blobs');
    }
};

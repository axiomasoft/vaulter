<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Drive\Schema\DriveSchema;

return new class extends Migration
{
    public function up(): void
    {
        app(DriveSchema::class)->create(entity: 'upload_sessions');
    }

    public function down(): void
    {
        app(DriveSchema::class)->drop(entity: 'upload_sessions');
    }
};

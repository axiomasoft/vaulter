<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Drive\Schema\DriveSchema;

return new class extends Migration
{
    public function up(): void
    {
        app(DriveSchema::class)->create(entity: 'node_shares');
    }

    public function down(): void
    {
        app(DriveSchema::class)->drop(entity: 'node_shares');
    }
};

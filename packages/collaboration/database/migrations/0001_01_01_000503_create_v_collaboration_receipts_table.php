<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Collaboration\Schema\CollaborationSchema;

return new class extends Migration
{
    public function up(): void
    {
        app(CollaborationSchema::class)->create(entity: 'collaboration_receipts');
    }

    public function down(): void
    {
        app(CollaborationSchema::class)->drop(entity: 'collaboration_receipts');
    }
};

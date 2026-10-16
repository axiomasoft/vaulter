<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\KernelSchema;

return new class extends Migration
{
    public function up(): void
    {
        app(KernelSchema::class)->create(entity: 'outbox');
    }

    public function down(): void
    {
        app(KernelSchema::class)->drop(entity: 'outbox');
    }
};

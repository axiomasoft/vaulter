<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\KernelSchema;

return new class extends Migration
{
    public function up(): void
    {
        app(KernelSchema::class)->create(entity: 'idempotency_keys');
    }

    public function down(): void
    {
        app(KernelSchema::class)->drop(entity: 'idempotency_keys');
    }
};

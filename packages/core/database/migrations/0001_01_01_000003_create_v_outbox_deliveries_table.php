<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Schema\KernelSchema;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        Container::getInstance()->make(KernelSchema::class)->create(entity: 'outbox_deliveries');
    }

    public function down(): void
    {
        Container::getInstance()->make(KernelSchema::class)->drop(entity: 'outbox_deliveries');
    }
};

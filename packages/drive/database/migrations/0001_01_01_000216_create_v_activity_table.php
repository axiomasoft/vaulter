<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Drive\Schema\DriveSchema;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        Container::getInstance()->make(DriveSchema::class)->create(entity: 'activity');
    }

    public function down(): void
    {
        Container::getInstance()->make(DriveSchema::class)->drop(entity: 'activity');
    }
};

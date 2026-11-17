<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Media\Schema\MediaSchema;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        Container::getInstance()->make(MediaSchema::class)->create(entity: 'blob_references');
    }

    public function down(): void
    {
        Container::getInstance()->make(MediaSchema::class)->drop(entity: 'blob_references');
    }
};

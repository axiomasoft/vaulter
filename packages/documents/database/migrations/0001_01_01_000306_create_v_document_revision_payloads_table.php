<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Documents\Schema\DocumentsSchema;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        Container::getInstance()->make(DocumentsSchema::class)->create(entity: 'document_revision_payloads');
    }

    public function down(): void
    {
        Container::getInstance()->make(DocumentsSchema::class)->drop(entity: 'document_revision_payloads');
    }
};

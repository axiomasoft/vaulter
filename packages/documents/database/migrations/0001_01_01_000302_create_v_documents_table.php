<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Documents\Schema\DocumentsSchema;

return new class extends Migration
{
    public function up(): void
    {
        app(DocumentsSchema::class)->create(entity: 'documents');
    }

    public function down(): void
    {
        app(DocumentsSchema::class)->drop(entity: 'documents');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Documents\Schema\DocumentsSchema;

return new class extends Migration
{
    public function up(): void
    {
        // Until the migrations move into their packages (P4.5), a host without vaulter-documents has no schema class to call.
        if (!class_exists(DocumentsSchema::class)) {
            return;
        }

        app(DocumentsSchema::class)->create(entity: 'documents');
    }

    public function down(): void
    {
        if (!class_exists(DocumentsSchema::class)) {
            return;
        }

        app(DocumentsSchema::class)->drop(entity: 'documents');
    }
};

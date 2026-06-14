<?php

declare(strict_types=1);

use DocVault\Support\DocVaultSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dv_document_permissions', function (Blueprint $table): void {
            DocVaultSchema::key($table);
            DocVaultSchema::tenant($table);
            DocVaultSchema::foreignKey($table, 'document_id');
            DocVaultSchema::morphs($table, 'subject');
            $table->string('permission');
            DocVaultSchema::actor($table, 'granted_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dv_document_permissions');
    }
};

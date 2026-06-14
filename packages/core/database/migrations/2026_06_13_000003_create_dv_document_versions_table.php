<?php

declare(strict_types=1);

use DocVault\Support\Config;
use DocVault\Support\DocVaultSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Config::table('document_versions'), function (Blueprint $table): void {
            DocVaultSchema::key($table);
            DocVaultSchema::tenant($table);
            DocVaultSchema::foreignKey($table, 'document_id');
            $table->longText('content');
            $table->string('snapshot_label')->nullable();
            DocVaultSchema::actor($table, 'created_by');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Config::table('document_versions'));
    }
};

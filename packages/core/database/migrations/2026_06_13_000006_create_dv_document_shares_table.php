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
        Schema::create(Config::table('document_shares'), function (Blueprint $table): void {
            DocVaultSchema::key($table);
            DocVaultSchema::tenant($table);
            DocVaultSchema::foreignKey($table, 'document_id');
            $table->string('token', 64)->unique();
            $table->string('permission');
            $table->timestamp('expires_at')->nullable();
            DocVaultSchema::actor($table, 'created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Config::table('document_shares'));
    }
};

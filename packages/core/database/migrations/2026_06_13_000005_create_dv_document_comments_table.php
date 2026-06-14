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
        Schema::create(Config::table('document_comments'), function (Blueprint $table): void {
            DocVaultSchema::key($table);
            DocVaultSchema::tenant($table);
            DocVaultSchema::foreignKey($table, 'document_id');
            DocVaultSchema::foreignKey($table, 'parent_id', nullable: true);
            DocVaultSchema::morphs($table, 'author');
            $table->text('body');
            $table->json('anchor')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Config::table('document_comments'));
    }
};

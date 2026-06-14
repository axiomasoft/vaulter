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
        Schema::create(Config::table('documents'), function (Blueprint $table): void {
            DocVaultSchema::key($table);
            DocVaultSchema::tenant($table);
            DocVaultSchema::morphs($table, 'workspace');
            DocVaultSchema::foreignKey($table, 'folder_id', nullable: true);
            $table->string('type')->default('richtext')->index();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->json('meta')->nullable();
            DocVaultSchema::actor($table, 'created_by');
            DocVaultSchema::actor($table, 'updated_by');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Config::table('documents'));
    }
};

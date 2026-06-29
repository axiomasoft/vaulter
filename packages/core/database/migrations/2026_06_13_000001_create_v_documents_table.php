<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vaulter\Support\Config;
use Vaulter\Support\VaulterSchema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Config::table('documents'), function (Blueprint $table): void {
            VaulterSchema::key($table);
            VaulterSchema::tenant($table);
            VaulterSchema::morphs($table, 'workspace');
            VaulterSchema::foreignKey($table, 'folder_id', nullable: true);
            $table->string('type')->default('richtext')->index();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->json('meta')->nullable();
            VaulterSchema::actor($table, 'created_by');
            VaulterSchema::actor($table, 'updated_by');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Config::table('documents'));
    }
};

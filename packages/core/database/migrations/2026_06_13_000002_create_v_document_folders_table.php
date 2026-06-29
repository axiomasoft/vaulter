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
        Schema::create(Config::table('document_folders'), function (Blueprint $table): void {
            VaulterSchema::key($table);
            VaulterSchema::tenant($table);
            VaulterSchema::morphs($table, 'workspace');
            VaulterSchema::foreignKey($table, 'parent_id', nullable: true);
            $table->string('name');
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Config::table('document_folders'));
    }
};

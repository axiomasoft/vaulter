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
        Schema::create(Config::table('document_comments'), function (Blueprint $table): void {
            VaulterSchema::key($table);
            VaulterSchema::tenant($table);
            VaulterSchema::foreignKey($table, 'document_id');
            VaulterSchema::foreignKey($table, 'parent_id', nullable: true);
            VaulterSchema::morphs($table, 'author');
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

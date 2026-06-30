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
        Schema::create(Config::table('document_permissions'), function (Blueprint $table): void {
            VaulterSchema::key($table);
            VaulterSchema::tenant($table);
            VaulterSchema::foreignKey($table, 'document_id');
            VaulterSchema::morphs($table, 'subject');
            $table->string('permission');
            VaulterSchema::actor($table, 'granted_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Config::table('document_permissions'));
    }
};

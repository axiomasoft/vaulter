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
        Schema::create(Config::table('document_shares'), function (Blueprint $table): void {
            VaulterSchema::key($table);
            VaulterSchema::tenant($table);
            VaulterSchema::foreignKey($table, 'document_id');
            $table->string('token', 64)->unique();
            $table->string('permission');
            $table->timestamp('expires_at')->nullable();
            VaulterSchema::actor($table, 'created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Config::table('document_shares'));
    }
};

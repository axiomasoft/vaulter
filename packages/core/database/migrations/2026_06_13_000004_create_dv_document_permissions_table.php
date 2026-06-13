<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dv_document_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->index();
            $table->morphs('subject');
            $table->string('permission');
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dv_document_permissions');
    }
};

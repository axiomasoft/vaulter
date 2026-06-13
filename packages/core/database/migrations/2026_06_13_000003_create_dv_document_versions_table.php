<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dv_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->index();
            $table->longText('content');
            $table->string('snapshot_label')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dv_document_versions');
    }
};

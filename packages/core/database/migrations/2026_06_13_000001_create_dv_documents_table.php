<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dv_documents', function (Blueprint $table): void {
            $table->id();
            $table->morphs('workspace');
            $table->foreignId('folder_id')->nullable()->index();
            $table->string('type')->default('richtext')->index();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dv_documents');
    }
};

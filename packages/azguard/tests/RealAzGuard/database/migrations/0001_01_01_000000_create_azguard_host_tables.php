<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The host tables the RealAzGuard stubs live in: the User subject and a workspace entity to scope against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamp('locked_at')->nullable();
        });

        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('users');
    }
};

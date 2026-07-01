<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A minimal `users` table backing the HasRoles actor in the spatie-driver tests (the
 * host owns this table in a real app). Registered as a migration — NOT created by hand
 * in the TestCase — so RefreshDatabase's one-time migrate:fresh commits it and it
 * survives the per-test transaction on a persistent connection (the pgsql lane).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

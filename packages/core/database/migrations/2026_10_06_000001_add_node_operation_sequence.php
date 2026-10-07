<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;

return new class extends Migration
{
    public function up(): void
    {
        app(SchemaBuilder::class)->addNodeSequence();
    }

    public function down(): void
    {
        app(SchemaBuilder::class)->removeNodeSequence();
    }
};

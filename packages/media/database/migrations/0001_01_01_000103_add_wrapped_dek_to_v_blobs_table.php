<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        $schema = $this->schema();
        $blobs = $this->table('blobs');
        if ($schema->hasColumn($blobs, 'wrapped_dek')) {
            return;
        }

        $schema->table($blobs, static function (Blueprint $table): void {
            $table->string('storage_version', 1024)->nullable()->change();
            $table->text('wrapped_dek')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('blobs'), static function (Blueprint $table): void {
            $table->dropColumn('wrapped_dek');
            $table->string('storage_version', 255)->nullable()->change();
        });
    }
};

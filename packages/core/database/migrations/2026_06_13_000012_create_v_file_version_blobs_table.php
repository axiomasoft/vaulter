<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Support\Config;

/**
 * v_file_version_blobs — the version ↔ blob edge table, the GC truth (§2.4).
 *
 * Gated on the 'versions' feature (and depends on v_blobs / 'blobs'). Composite
 * primary key, never soft-deleted. Thin shell over PanelTablesBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Config::featureEnabled('versions')) {
            return;
        }

        (new PanelTablesBuilder)->createTable(
            panel: (new Panel)->id('default'),
            entity: 'file_version_blobs',
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropTable(
            panel: (new Panel)->id('default'),
            entity: 'file_version_blobs',
        );
    }
};

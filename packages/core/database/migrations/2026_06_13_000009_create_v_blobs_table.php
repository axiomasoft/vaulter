<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Support\Config;

/**
 * v_blobs — the content-addressable, immutable payload (master plan §2.3).
 *
 * Gated on the 'blobs' feature. storage_key is scope-qualified and the
 * dedup-unique index spans the scope columns (H.3). Thin shell over
 * PanelTablesBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Config::featureEnabled('blobs')) {
            return;
        }

        (new PanelTablesBuilder)->createTable(
            panel: (new Panel)->id('default'),
            entity: 'blobs',
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropTable(
            panel: (new Panel)->id('default'),
            entity: 'blobs',
        );
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Support\Config;

/**
 * v_node_document_versions — the NODE-attached, append-only document version
 * (master plan §2.5 / M4a). DISTINCT from the legacy document_id-attached
 * v_document_versions (that table is untouched). Gated on the 'document_versions'
 * feature (default off → opt-in). Thin shell over PanelTablesBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Config::featureEnabled('document_versions')) {
            return;
        }

        (new PanelTablesBuilder)->createTable(
            panel: (new Panel)->id('default'),
            entity: 'node_document_versions',
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropTable(
            panel: (new Panel)->id('default'),
            entity: 'node_document_versions',
        );
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;

/**
 * v_node_comments — the NODE-attached, threaded, anchorable comment (ADR-0014 шаг 1).
 * Additive parity with the legacy document_id-attached v_document_comments (which is
 * left untouched — the legacy DocVault removal is a separate step 2).
 *
 * Thin shell over PanelTablesBuilder; the (node_id, created_at) feed index, the
 * parent_id threading index and the driver-aware id-strategy keys are emitted by the
 * builder. The self-FK on parent_id (a reply cascades with its parent) and the node_id
 * cascade are explicit there.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PanelTablesBuilder)->createTable(
            panel: (new Panel)->id('default'),
            entity: 'node_comments',
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropTable(
            panel: (new Panel)->id('default'),
            entity: 'node_comments',
        );
    }
};

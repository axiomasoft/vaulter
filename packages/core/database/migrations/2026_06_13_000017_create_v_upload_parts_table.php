<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Support\Config;

/**
 * v_upload_parts — the normalised per-part record (master plan §2.8 / D16 / M3a).
 *
 * Gated on the 'uploads' feature. The composite primary key
 * (upload_session_id, part_no) IS the S3 re-upload-replaces semantics. Thin shell
 * over PanelTablesBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Config::featureEnabled('uploads')) {
            return;
        }

        (new PanelTablesBuilder)->createTable(
            panel: (new Panel)->id('default'),
            entity: 'upload_parts',
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropTable(
            panel: (new Panel)->id('default'),
            entity: 'upload_parts',
        );
    }
};

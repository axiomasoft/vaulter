<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Support\Config;

/**
 * v_webhook_endpoints — the subscriber endpoints (master plan §2.11 / D14 / M3b),
 * ported from chatom 1:1.
 *
 * Thin shell over PanelTablesBuilder. events is the JSON subscription list,
 * signing_secret signs the deliveries (HMAC-SHA256), failure_count drives the
 * circuit breaker. Gated on the 'reliability' feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Config::featureEnabled('reliability')) {
            return;
        }

        (new PanelTablesBuilder)->createTable(
            panel: (new Panel)->id('default'),
            entity: 'webhook_endpoints',
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropTable(
            panel: (new Panel)->id('default'),
            entity: 'webhook_endpoints',
        );
    }
};

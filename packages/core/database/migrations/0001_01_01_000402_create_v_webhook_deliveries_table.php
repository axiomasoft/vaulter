<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Webhooks\Schema\WebhooksSchema;

return new class extends Migration
{
    public function up(): void
    {
        app(WebhooksSchema::class)->create(entity: 'webhook_deliveries');
    }

    public function down(): void
    {
        app(WebhooksSchema::class)->drop(entity: 'webhook_deliveries');
    }
};

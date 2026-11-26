<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Schema\VaulterMigration;
use Vaulter\Webhooks\Schema\WebhooksSchema;

return new class extends VaulterMigration
{
    public function up(): void
    {
        Container::getInstance()->make(WebhooksSchema::class)->addDeliveriesEndpointForeignKeyIndex();
    }

    public function down(): void
    {
        Container::getInstance()->make(WebhooksSchema::class)->dropDeliveriesEndpointForeignKeyIndex();
    }
};

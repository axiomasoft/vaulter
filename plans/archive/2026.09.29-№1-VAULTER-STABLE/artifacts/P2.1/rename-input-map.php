<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

$root = dirname(__DIR__, 4);

return RectorConfig::configure()
    ->withPaths(array_merge(
        glob($root.'/packages/*/src'),
        glob($root.'/packages/*/tests'),
        glob($root.'/packages/*/config'),
        glob($root.'/packages/*/database'),
        glob($root.'/packages/*/routes'),
        [$root.'/tests/Pest.php'],
    ))
    ->withImportNames(importDocBlockNames: true, importShortClasses: false)
    ->withoutParallel()
    ->withConfiguredRule(RenameClassRector::class, [
        'Vaulter\\Data\\Access\\GrantNodeAccessData' => 'Vaulter\\Data\\Access\\GrantNodeAccessInput',
        'Vaulter\\Data\\Sharing\\CreateShareLinkData' => 'Vaulter\\Data\\Sharing\\ShareLinkInput',
        'Vaulter\\Data\\Webhooks\\RegisterWebhookEndpointData' => 'Vaulter\\Data\\Webhooks\\RegisterWebhookEndpointInput',
        'Vaulter\\Data\\Webhooks\\UpdateWebhookEndpointData' => 'Vaulter\\Data\\Webhooks\\UpdateWebhookEndpointInput',
    ]);

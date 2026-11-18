<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeAudit;

use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmeMiddleware;
use Vaulter\Operations\OperationMiddleware;

/**
 * Third-party vendor plugin (`acme.audit`) registering an operation middleware imperatively via `PluginRegistrar`.
 */
final class AcmeFacetedAuditPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'acme.audit', version: '1.0.0', requires: ['vaulter.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contributeSlot(
            slot: OperationMiddleware::class,
            id: 'acme.audit.middleware',
            implementation: AcmeMiddleware::class,
            priority: 5,
        );
    }
}

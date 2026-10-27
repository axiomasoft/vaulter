<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Collaboration;

use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

final class AcmeModule implements VaulterModule
{
    public function id(): ModuleId
    {
        return new ModuleId('acme');
    }

    public function version(): string
    {
        return '1.0';
    }

    public function activation(): Activation
    {
        return Activation::Auto;
    }

    public function requires(): array
    {
        return ['documents' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module->slot(DocumentType::class, AcmeType::class, 'acme.type', key: 'acme/ot')
            ->slot(CollaborationProtocolDriver::class, AcmeDriver::class, 'acme.protocol', key: 'acme-ot');
    }
}

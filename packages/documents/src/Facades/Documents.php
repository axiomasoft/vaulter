<?php

declare(strict_types=1);

namespace Vaulter\Documents\Facades;

use Illuminate\Support\Facades\Facade;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Registries\DocumentExporterRegistry;
use Vaulter\Documents\Registries\DocumentTypeRegistry;

/**
 * @method static DocumentTypeRegistry     types()
 * @method static DocumentExporterRegistry exporters()
 *
 * @see DocumentsManager
 */
final class Documents extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'vaulter.documents';
    }
}

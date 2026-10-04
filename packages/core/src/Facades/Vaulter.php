<?php

declare(strict_types=1);

namespace Vaulter\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Handlers\DocumentExporter;
use Vaulter\Contracts\Handlers\FileHandlerRegistry;
use Vaulter\Models\Drive\Drive;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\VaulterManager;

/**
 * @method static DriveHandle                         for(Model $entity, ?string $panel = null)
 * @method static DriveHandle                         forStorage(Drive $storage)
 * @method static void                                registerDocumentType(string $type, array<string, mixed> $definition)
 * @method static array<string, array<string, mixed>> documentTypes()
 * @method static FileHandlerRegistry                 handlers()
 * @method static void                                registerExporter(DocumentExporter|class-string<DocumentExporter> $exporter)
 * @method static DocumentExporter                    exporter(string $format)
 * @method static list<string>                        exporterFormats()
 *
 * @see VaulterManager
 *
 * @method static ProfileRegistry profiles()
 */
final class Vaulter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'vaulter';
    }
}

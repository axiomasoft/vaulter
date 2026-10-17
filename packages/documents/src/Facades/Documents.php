<?php

declare(strict_types=1);

namespace Vaulter\Documents\Facades;

use Illuminate\Support\Facades\Facade;
use Vaulter\Attributes\Api;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Export\DocumentExporterRegistry;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Contracts\DriveHandle;

/**
 * @method static DocumentTypeRegistry     types()
 * @method static DocumentExporterRegistry exporters()
 * @method static DocumentOperations       in(DriveHandle $drive)
 * @method static CommentOperations        comments(DriveHandle $drive)
 *
 * @see DocumentsManager
 */
#[Api]
final class Documents extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'vaulter.documents';
    }
}

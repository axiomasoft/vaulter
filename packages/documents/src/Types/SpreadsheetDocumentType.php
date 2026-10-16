<?php

declare(strict_types=1);

namespace Vaulter\Documents\Types;

use Vaulter\Attributes\Api;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

/**
 * A spreadsheet workbook owned by the Univer editor; the server stores its JSON
 * snapshot as is. Single-user: Univer collaboration is a separate engine, not the
 * Yjs lane. Registered only when listed in vaulter-documents.types.
 */
#[Api]
final readonly class SpreadsheetDocumentType implements DocumentType
{
    public const string KEY = 'vaulter/spreadsheet';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Spreadsheet';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function codec(): DocumentCodec
    {
        return new OpaqueJsonCodec;
    }

    public function defaultContent(): DocumentContent
    {
        return OpaqueContent::fromArray([]);
    }

    public function viewer(): ViewerDescriptor
    {
        return new ViewerDescriptor(key: 'vaulter/univer');
    }

    public function capabilities(): ContentCapabilities
    {
        return new ContentCapabilities(editable: true, previewable: true, downloadable: false, thumbnailable: false, collaborative: false);
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Types;

use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Data\Types\DocumentFeatures;
use Vaulter\Documents\Data\Types\DocumentViewer;

/**
 * A non-collaborative opaque JSON document type fixture using the default global size limit.
 */
final readonly class OpaqueNoteType implements DocumentType
{
    public const string KEY = 'acme/opaque-note';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Opaque note';
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

    public function viewer(): DocumentViewer
    {
        return new DocumentViewer(key: 'acme/opaque-note');
    }

    public function features(): DocumentFeatures
    {
        return new DocumentFeatures(editable: true, previewable: true, thumbnailable: false, downloadable: false);
    }
}

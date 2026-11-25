<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Types;

use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Data\Types\DocumentFeatures;
use Vaulter\Documents\Data\Types\DocumentViewer;

/**
 * An opaque JSON type whose codec is managed: its payload names an external object in
 * "ref", and the codec records every reference change so a test can check them.
 */
final readonly class ManagedNoteType implements DocumentType
{
    public const string KEY = 'acme/managed-note';

    public function __construct(public ManagedNoteCodec $managedCodec = new ManagedNoteCodec) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Managed note';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function codec(): DocumentCodec
    {
        return $this->managedCodec;
    }

    public function defaultContent(): DocumentContent
    {
        return OpaqueContent::fromArray([]);
    }

    public function viewer(): DocumentViewer
    {
        return new DocumentViewer(key: self::KEY);
    }

    public function features(): DocumentFeatures
    {
        return new DocumentFeatures(editable: true, previewable: false, thumbnailable: false, downloadable: false);
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Documents\Data\Types\DocumentFeatures;
use Vaulter\Documents\Data\Types\DocumentViewer;

/**
 * A kind of structured document (rich text, markdown, a spreadsheet, a host's own),
 * registered in Documents::types() under a `vendor/name` key. The key is what a
 * document stores; the class is never serialized.
 *
 * @extensible Keys are strings; a client must handle a type it does not know.
 */
#[Spi(SpiKind::Implement)]
interface DocumentType
{
    /** 'vendor/name', e.g. 'vaulter/rich-text'. */
    public function key(): string;

    public function label(): string;

    /** >= 1; the schema version new content is written in. */
    public function schemaVersion(): int;

    public function codec(): DocumentCodec;

    public function defaultContent(): DocumentContent;

    /** The client editor/viewer key, e.g. 'vaulter/tiptap'. */
    public function viewer(): DocumentViewer;

    public function features(): DocumentFeatures;
}

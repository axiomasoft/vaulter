<?php

declare(strict_types=1);

namespace Vaulter\Documents\Types;

use Vaulter\Attributes\Api;
use Vaulter\Documents\Content\TipTap\TipTapCodec;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

/**
 * Markdown: the TipTap editor limited to what Markdown can represent, so it always
 * exports to Markdown without loss. Collaborative over Yjs.
 */
#[Api]
final readonly class MarkdownDocumentType implements DocumentType, SupportsCollaboration
{
    public const string KEY = 'vaulter/markdown';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Markdown';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function codec(): DocumentCodec
    {
        return new TipTapCodec(TipTapSchema::markdown());
    }

    public function defaultContent(): DocumentContent
    {
        return TipTapContent::empty();
    }

    public function viewer(): ViewerDescriptor
    {
        return new ViewerDescriptor(key: 'vaulter/tiptap', props: ['preset' => 'markdown']);
    }

    public function capabilities(): ContentCapabilities
    {
        return new ContentCapabilities(editable: true, previewable: true, downloadable: false, thumbnailable: false, collaborative: true);
    }

    public function collaborationProtocol(): string
    {
        return 'yjs';
    }

    /** The hub sends the JSON projection with the state. */
    public function projectFromState(string $state): ?DocumentContent
    {
        return null;
    }
}

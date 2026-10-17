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
use Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor;

/** Rich text edited in the TipTap editor, collaboratively over Yjs. */
#[Api]
final readonly class RichTextDocumentType implements DocumentType, SupportsCollaboration
{
    public const string KEY = 'vaulter/rich-text';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Rich text';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function codec(): DocumentCodec
    {
        return new TipTapCodec(TipTapSchema::richText());
    }

    public function defaultContent(): DocumentContent
    {
        return TipTapContent::empty();
    }

    public function viewer(): ViewerDescriptor
    {
        return new ViewerDescriptor(key: 'vaulter/tiptap', props: ['preset' => 'rich-text']);
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

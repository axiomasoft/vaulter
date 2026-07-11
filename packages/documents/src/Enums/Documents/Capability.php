<?php

declare(strict_types=1);

namespace Vaulter\Documents\Enums\Documents;

use Vaulter\Documents\Documents\DocumentHandlerRegistry;
use Vaulter\ValueObjects\Documents\DocumentCapabilities;

/**
 * The capability a {@see DocumentHandlerRegistry} resolution is
 * filtered by (master plan §4.5 / §7). A FileDescriptor is asked "which handler can
 * EDIT / PREVIEW / DOWNLOAD / THUMBNAIL this file?", and the registry only returns a
 * handler whose DocumentCapabilities advertises the requested flag. This is the
 * runtime capability axis — orthogonal to the built-in NodeKind/DocumentType enums
 * (H.5: the registry, keyed by mime/extension strings, is the source of truth; the
 * enums are only built-in sugar).
 */
enum Capability: string
{
    case Edit = 'edit';
    case Preview = 'preview';
    case Download = 'download';
    case Thumbnail = 'thumbnail';
    case Collaborate = 'collaborate';

    /**
     * Whether a DocumentCapabilities VO satisfies this capability — the predicate
     * the registry's capability-filter step applies.
     */
    public function satisfiedBy(DocumentCapabilities $capabilities): bool
    {
        return match ($this) {
            self::Edit => $capabilities->editable,
            self::Preview => $capabilities->previewable,
            self::Download => $capabilities->downloadable,
            self::Thumbnail => $capabilities->thumbnailable,
            self::Collaborate => $capabilities->collaborative,
        };
    }
}

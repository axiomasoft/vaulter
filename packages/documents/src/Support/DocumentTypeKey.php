<?php

declare(strict_types=1);

namespace Vaulter\Documents\Support;

use Vaulter\Attributes\Internal;

/**
 * Maps the 0.2 per-revision `format` label to the document type key the aggregate
 * stores, and back for the JSON keys kept until REST v1. Rich-text variants share
 * one type; a label nothing maps keeps its value under the legacy/ namespace so no
 * information is lost. The 0.2 → 0.3 upgrade migration carries a literal copy.
 */
#[Internal]
final class DocumentTypeKey
{
    public static function fromFormat(string $format): string
    {
        return match ($format) {
            'richtext', 'table', 'template' => 'vaulter/rich-text',
            'markdown' => 'vaulter/markdown',
            'univer', 'vaulter/univer' => 'vaulter/spreadsheet',
            default => str_contains($format, '/') ? $format : 'legacy/'.$format,
        };
    }

    public static function toFormat(string $type): string
    {
        return match (true) {
            $type === 'vaulter/rich-text' => 'richtext',
            $type === 'vaulter/markdown' => 'markdown',
            $type === 'vaulter/spreadsheet' => 'univer',
            str_starts_with($type, 'legacy/') => substr($type, strlen('legacy/')),
            default => $type,
        };
    }
}

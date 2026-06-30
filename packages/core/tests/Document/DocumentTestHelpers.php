<?php

declare(strict_types=1);

namespace Vaulter\Tests\Document;

use Vaulter\Drive\DriveContext;
use Vaulter\Facades\Vaulter;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\ValueObjects\Documents\TipTapContent;

/**
 * Builders for the M4a DocDrive tests: a Drive handle and a quick TipTap document.
 */
final class DocumentTestHelpers
{
    public static function drive(): DriveContext
    {
        return Vaulter::for(DriveTestHelpers::host());
    }

    /**
     * A small TipTap doc with one paragraph of the given text.
     */
    public static function content(string $text = 'Hello'): TipTapContent
    {
        return TipTapContent::fromArray([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => $text],
                    ],
                ],
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\Contracts\DocumentContent;

/**
 * Content as read back from storage. $lossless is false when reading had to drop
 * something the current type does not understand (a node of a disabled editor
 * extension, a payload the codec rejects): such content is shown, never saved back,
 * because the next save would silently delete what was dropped.
 */
#[Internal]
final readonly class ReadContent
{
    public function __construct(
        public DocumentContent $content,
        public bool $lossless,
    ) {}
}

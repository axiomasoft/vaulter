<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content;

use Packman\Attributes\Internal;
use Vaulter\Documents\Contracts\ManagedDocumentCodec;
use Vaulter\Documents\Types\DocumentTypeRegistry;

#[Internal]
final readonly class ManagedDocumentPayloads
{
    public function __construct(private DocumentTypeRegistry $types) {}

    public function change(string $type, string $kind, string $id, ?array $previous, ?array $next): void
    {
        $codec = $this->types->find($type)?->codec();
        if ($codec instanceof ManagedDocumentCodec) {
            $codec->replacePayloadReference($kind, $id, $previous, $next);
        }
    }
}

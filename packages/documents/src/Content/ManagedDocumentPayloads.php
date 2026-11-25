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

    /** True when the type's codec keeps references, so its payloads must be read before they change. */
    public function manages(string $type): bool
    {
        return $this->types->find($type)?->codec() instanceof ManagedDocumentCodec;
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed>|null $next
     */
    public function change(string $type, string $kind, string $id, ?array $previous, ?array $next): void
    {
        $codec = $this->types->find($type)?->codec();
        if ($codec instanceof ManagedDocumentCodec) {
            $codec->replacePayloadReference($kind, $id, $previous, $next);
        }
    }
}

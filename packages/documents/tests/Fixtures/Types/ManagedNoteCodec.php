<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Types;

use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\ManagedDocumentCodec;
use Vaulter\Drive\Operations\DriveContext;

/** The managed codec of ManagedNoteType: a log of reference changes, "kind id old→new". */
final class ManagedNoteCodec implements ManagedDocumentCodec
{
    /** @var list<string> */
    public array $changes = [];

    public function decode(array $raw, int $schemaVersion): DocumentContent
    {
        return new OpaqueJsonCodec()->decode($raw, $schemaVersion);
    }

    public function maxSizeBytes(): ?int
    {
        return null;
    }

    public function decodeStored(array $raw, int $schemaVersion): DocumentContent
    {
        return OpaqueContent::fromArray($raw);
    }

    public function storagePayload(DocumentContent $content): array
    {
        return $content->toArray();
    }

    public function prepare(DocumentContent $content, DriveContext $context): DocumentContent
    {
        return $content;
    }

    public function replacePayloadReference(string $kind, string $id, ?array $previous, ?array $next): void
    {
        $this->changes[] = sprintf('%s %s %s→%s', $kind, $id, self::ref($previous), self::ref($next));
    }

    /** @param array<string, mixed>|null $payload */
    private static function ref(?array $payload): string
    {
        $ref = $payload['ref'] ?? null;

        return is_string($ref) ? $ref : '-';
    }
}

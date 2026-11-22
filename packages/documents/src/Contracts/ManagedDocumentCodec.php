<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\Drive\Operations\DriveContext;

/** A codec whose stored payload can reference externally prepared immutable data. */
#[Spi(SpiKind::Implement)]
interface ManagedDocumentCodec extends DocumentCodec
{
    /** Decode trusted storage. Client input continues through decode(). */
    public function decodeStored(array $raw, int $schemaVersion): DocumentContent;

    /** Encode the database payload, preserving nested JSON map and list types. */
    public function storagePayload(DocumentContent $content): array;

    /** Prepare external bytes before the owner's write transaction. */
    public function prepare(DocumentContent $content, DriveContext $context): DocumentContent;

    /** Maintain references before writing or deleting a working or revision payload, inside its transaction. */
    public function replacePayloadReference(string $kind, string $id, ?array $previous, ?array $next): void;
}

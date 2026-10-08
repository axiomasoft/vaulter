<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots;

/** The head of a document node, supplied by the documents package. */
final readonly class DocumentHeadSnapshot
{
    /** @internal */
    public function __construct(
        public string $type,
        public int $schemaVersion,
        public bool $typeAvailable,
        public ?string $headRevisionId,
        public int $epoch,
        public int $stateVersion,
    ) {}
}

<?php

declare(strict_types=1);

namespace VaulterRef\Drive;

/**
 * BOUNDARY: Vaulter\Drive\Operations\DriveContext. Built by the host from the authenticated session or
 * from a queued job's stored ids, never from agent JSON. Only the fields the port reads are kept.
 */
final readonly class DriveContext
{
    public function __construct(
        public int $tenantId,
        public string $driveId,
        public string $subjectType,
        public int $subjectId,
        public string $correlationId,
    ) {}

    /** The effective_subject of an office command key: "user/42". */
    public function subjectRef(): string
    {
        return $this->subjectType.'/'.$this->subjectId;
    }
}

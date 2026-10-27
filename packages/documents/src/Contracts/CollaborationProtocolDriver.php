<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;

#[Spi(SpiKind::Implement)]
interface CollaborationProtocolDriver
{
    public function protocol(): string;

    public function version(): string;

    /** Pure compatibility predicate; no I/O, conversion or authorization. */
    public function supportsState(string $documentType, string $stateFormat, int $schemaVersion, bool $hasCheckpoint, CollaborationMode $mode): bool;

    public function issueSession(CollaborationGrant $grant): CollaborationSession;
}

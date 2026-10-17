<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Data;

use Vaulter\Attributes\Api;
use Vaulter\Collaboration\Configuration\CollaborationConfig;

/**
 * The parts of a collaboration room name ("<prefix>.<driveId>.<nodeId>.<epoch>"),
 * as {@see CollaborationConfig::parseRoom()} reads them.
 * Ids stay strings, the way every public id is compared.
 */
#[Api]
final readonly class RoomRef
{
    public function __construct(
        public string $driveId,
        public string $nodeId,
        public int $epoch,
    ) {}
}

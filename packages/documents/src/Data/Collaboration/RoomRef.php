<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Collaboration;

use Vaulter\Documents\Configuration\DocumentsConfig;

/**
 * The parts of a collaboration room name ("<prefix>.<driveId>.<nodeId>.<epoch>"),
 * as {@see DocumentsConfig::parseRoom()} reads them.
 * Ids stay strings, the way every public id is compared.
 */
final readonly class RoomRef
{
    public function __construct(
        public string $driveId,
        public string $nodeId,
        public int $epoch,
    ) {}
}

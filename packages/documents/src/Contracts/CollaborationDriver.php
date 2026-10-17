<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Drive\Models\Node;

/**
 * The realtime-collaboration transport for document nodes. A Hocuspocus driver joins
 * a document through an external Node hub and mints a signed room JWT.
 *
 * The access decision is not the driver's job: DocumentOperations::collaborationSession()
 * decides whether the actor may write and passes that in. The driver only encodes the
 * decision, the document generation (epoch) and its schema into the session.
 */
#[Spi(SpiKind::Implement)]
interface CollaborationDriver
{
    public function type(): CollaborationDriverType;

    /**
     * @param int        $epoch         the document's collab_epoch; part of the room name
     * @param int        $schemaVersion the schema the room seeds and stores in
     * @param bool       $writable      false gives a read-only session
     * @param int|string $actorId       the connecting host user's id (the JWT `sub`)
     */
    public function issueSession(Node $node, int $epoch, int $schemaVersion, bool $writable, int|string $actorId): CollaborationSession;
}

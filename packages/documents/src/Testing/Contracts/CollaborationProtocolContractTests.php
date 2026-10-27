<?php

declare(strict_types=1);

namespace Vaulter\Documents\Testing\Contracts;

use PHPUnit\Framework\Assert;
use Vaulter\Attributes\Api;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;

/** Minimal generic DTO contract; credential verification belongs to the adapter kit. */
#[Api]
final class CollaborationProtocolContractTests
{
    public function assertCollaborationSessionContract(CollaborationProtocolDriver $driver, CollaborationGrant $grant, CollaborationSession $session): void
    {
        Assert::assertSame($grant->protocol, $driver->protocol(), 'slot key must match driver protocol');
        $session->assertGrant($grant, $driver->version());
        Assert::assertSame($grant->nodeId, $session->toArray()['node_id']);
    }
}

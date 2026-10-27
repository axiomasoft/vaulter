<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Exceptions\CollaborationProtocolContractException;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Kernel;

#[Internal]
final readonly class CollaborationSessionService
{
    public function __construct(private Kernel $kernel, private MetricsRecorder $metrics) {}

    public function issue(CollaborationGrant $grant, Ability $ability): CollaborationSession
    {
        $extensions = $this->kernel->extensions();
        if (!in_array($grant->protocol, $extensions->handlerKeys(CollaborationProtocolDriver::class), true)) {
            throw new CollaborationUnavailableException('protocol_unavailable', $grant->documentType);
        }
        $driver = $extensions->handler(CollaborationProtocolDriver::class, $grant->protocol);
        if ($driver->protocol() !== $grant->protocol) {
            throw new CollaborationProtocolContractException;
        }
        $session = $driver->issueSession($grant);
        $session->assertGrant($grant, $driver->version());
        $this->metrics->counter(name: 'vaulter_collab_session_issued_total', labels: ['driver' => $grant->protocol, 'ability' => $ability->value]);

        return $session;
    }
}

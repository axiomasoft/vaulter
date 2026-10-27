<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Collaboration;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\State\CheckpointPublication;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Documents\State\PendingMarker;
use Vaulter\Documents\State\PendingStateDrainer;

final class AcmePendingStateDrainer implements PendingStateDrainer
{
    public array $calls = [];

    public function __construct(private AcmeFixtureLog $log, private VaulterDatabase $database) {}

    public function protocol(): string
    {
        return 'acme-ot';
    }

    public function drain(PendingMarker $marker): void
    {
        if ($this->database->connection()->transactionLevel() !== 0 || $marker->binding->protocol !== $this->protocol()) {
            throw new \LogicException('Wrong fixture drain context.');
        }
        $this->calls[] = $marker;
        $content = $this->log->projection($marker);
        $this->database->transaction(function () use ($marker, $content): void {
            $state = app(CollaborativeState::class);
            $locked = $state->lock($marker->nodeId);
            $state->publishCheckpoint($locked, new CheckpointPublication($marker->binding, $locked->stateVersion, $marker->throughSeq, null, $content));
        });
    }
}

<?php

declare(strict_types=1);

// Deliberately wrong implementations. scenario.php runs each of them on the same input as the reference and
// requires a different, observable result; that proves the check distinguishes the two designs.

use VaulterRef\Ai\Core\Contracts\CommandOwner;
use VaulterRef\Ai\Core\Data\CommandOutcome;
use VaulterRef\Ai\Core\Data\CommandScope;
use VaulterRef\Ai\Documents\Writing\SingleTextOwner;
use VaulterRef\Database\Pg;
use VaulterRef\Documents\Actions\RunAtomicDocumentMutation;
use VaulterRef\Documents\Contracts\AtomicDocumentMutation;
use VaulterRef\Documents\Contracts\LockedDocumentMutation;
use VaulterRef\Documents\Exceptions\DocumentMutationException;
use VaulterRef\Drive\Ability;
use VaulterRef\Drive\AccessGate;
use VaulterRef\Drive\DriveContext;

/** Checks the current write mode before looking up the key: loses the receipt after snapshot→journal. */
final readonly class ModeFirstOwner implements CommandOwner
{
    public function __construct(private AtomicDocumentMutation $atomic, private SingleTextOwner $inner) {}

    public function key(): string
    {
        return SingleTextOwner::KEY;
    }

    public function commit(DriveContext $context, CommandScope $scope, string $fingerprintHex, ?array $plan): CommandOutcome
    {
        return $this->atomic->run($context, $scope->resourceId, function (LockedDocumentMutation $locked) use ($context, $scope, $fingerprintHex, $plan): CommandOutcome {
            if ($locked->snapshot()->writeMode !== 'snapshot') {
                return CommandOutcome::rejected('generation_changed');
            }

            return $this->inner->decideLocked($locked, $context, $scope, $fingerprintHex, $plan);
        });
    }

    public function status(DriveContext $context, CommandScope $scope, string $fingerprintHex): CommandOutcome
    {
        return $this->inner->status($context, $scope, $fingerprintHex);
    }
}

final readonly class AllowAll implements AccessGate
{
    public function allows(Pg $db, DriveContext $context, string $nodeId, Ability $ability): bool
    {
        return true;
    }
}

/** The order of today's DocumentOperations::writeContent: Edit is decided before the transaction takes N. */
final readonly class AuthorizeBeforeLock implements AtomicDocumentMutation
{
    public function __construct(private Pg $db, private AccessGate $access) {}

    public function run(DriveContext $context, string $nodeId, Closure $operation): mixed
    {
        if (!$this->access->allows($this->db, $context, $nodeId, Ability::Edit)) {
            throw DocumentMutationException::unavailable();
        }

        return (new RunAtomicDocumentMutation($this->db, new AllowAll))->run($context, $nodeId, $operation);
    }
}

<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Core\Contracts;

use VaulterRef\Ai\Core\Data\CommandOutcome;
use VaulterRef\Ai\Core\Data\CommandScope;
use VaulterRef\Drive\DriveContext;

/**
 * Target: packages/core/src/Contracts/CommandOwner.php in vaulter-ai. One registered writer per owner key
 * ("documents.single_text@1", later "collaboration.live_text@1", "sheets.formula@1"). The key is pinned in the
 * private plan at prepare and copied into v_ai_owner_routes.owner_key; a retry is always sent to that owner,
 * whatever the document's current write mode is.
 */
interface CommandOwner
{
    public function key(): string;

    /**
     * Idempotent per key: returns the stored outcome when one exists (after the current-access check),
     * otherwise decides under the owner's locks. $plan is null when the proposal payload was purged.
     *
     * @param array<string, mixed>|null $plan
     */
    public function commit(DriveContext $context, CommandScope $scope, string $fingerprintHex, ?array $plan): CommandOutcome;

    /** Read-only. Never creates an outcome. */
    public function status(DriveContext $context, CommandScope $scope, string $fingerprintHex): CommandOutcome;
}

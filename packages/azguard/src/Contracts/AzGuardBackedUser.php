<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Contracts;

use AzGuard\Contracts\AzGuardSubject;
use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * The host user the {@see AzGuardGuardAdapter} bridges into
 * Vaulter — AzGuard v1's OWN public subject contract, so there is no re-declared
 * signature to drift out of sync: `AzGuardSubject` supplies the decision
 * `hasPermission(…, on:, guard:)` on resources and assignment scopes.
 *
 * A host User satisfies it with NO extra method bodies — AzGuard's trait already
 * implements every method:
 *
 *   use AzGuard\Concerns\HasAzGuard;
 *   use Vaulter\AzGuard\Contracts\AzGuardBackedUser;
 *
 *   class User extends Authenticatable implements AzGuardBackedUser
 *   {
 *       use HasAzGuard;
 *   }
 *
 * The user model must be a subject model of the AzGuard panel that owns Vaulter's
 * permissions (`PanelBuilder::for(User::class)`).
 */
#[Spi(SpiKind::Implement)]
interface AzGuardBackedUser extends AzGuardSubject {}

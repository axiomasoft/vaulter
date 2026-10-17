<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Vaulter\Attributes\Internal;

/**
 * What stops the removal of a module that no foreign key, row count or reference shows: `vaulter:module:uninstall`
 * reads it from the module itself and from the modules it requires (their manifests implement {@see GuardsRemoval}),
 * and any blocker refuses the removal, the dry run included. The kernel knows nothing of what is counted.
 *
 * {@see blockers()} is read again inside the transaction of the purge and right before each rollback, as the
 * {@see ModuleReferences} are, so it reports the current state, takes no lock and caches nothing. Between two
 * rollbacks some tables of the module are gone already: it reads none of them, or reads a missing one as empty.
 */
#[Internal]
interface RemovalGuard
{
    /**
     * What stops the removal of `$module` now; empty unless this guard's module has a reason to refuse it.
     *
     * @return array<string, int> what blocks (a plural noun phrase, such as "document(s) with …") => how many, without empty entries
     */
    public function blockers(string $module): array;
}

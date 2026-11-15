<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;

/**
 * The references one module keeps in the data of another that no foreign key shows — the media pins: an owner
 * module pins blobs under its own `<module>.<what>` owner type. `vaulter:module:uninstall` reads them from the module
 * that holds them (its manifest implements {@see HoldsReferences}): references kept on the data of the module being
 * removed refuse the removal, and its own are released by `--purge` only. The kernel knows no table of the holder.
 *
 * The reads ({@see keptBy()}, {@see keptOn()}) are repeated inside the transaction of the purge and right before each
 * rollback, so they report the current state, take no lock and cache nothing.
 */
#[Internal]
interface ModuleReferences
{
    /**
     * The references `$module` keeps in the data of this module.
     *
     * @return array<string, int> kind (a pin owner type) => count, without empty kinds
     */
    public function keptBy(string $module): array;

    /**
     * The references other modules keep in the data `$module` owns; empty unless `$module` is this module.
     *
     * @return array<string, int> kind (a pin owner type) => count, without empty kinds
     */
    public function keptOn(string $module): array;

    /**
     * Releases every reference `$module` keeps in the data of this module — only for the purge of `$module`, inside the
     * caller's {@see VaulterDatabase} transaction that deletes its rows.
     *
     * @return int the number of references released
     */
    public function releaseKeptBy(string $module): int;
}

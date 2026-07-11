<?php

declare(strict_types=1);

namespace Vaulter\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

use function Illuminate\Support\enum_value;

use Vaulter\Support\Config;

/**
 * Every default Vaulter model reads its database connection from
 * `config('vaulter.connection')` (F-P2.3-02) instead of always resolving to the
 * host's default connection. `Config::connection()` returns null when the key is
 * unset, and `null` is exactly what `Model::getConnectionName()` already returned
 * before this trait existed — a host that never sets the key sees no change in
 * behaviour, only a host that DOES set it gets every Vaulter-owned table's model
 * routed to that named connection, matching where {@see
 * \Vaulter\Schema\PanelTablesBuilder} created the table.
 *
 * Host apps that swap in their own model (config('vaulter.models.*')) simply omit
 * this trait if they want a different connection resolution strategy.
 *
 * @mixin Model
 */
trait ResolvesVaulterConnection
{
    #[\Override]
    public function getConnectionName(): ?string
    {
        // Mirrors Model::getConnectionName()'s own enum_value() resolution (a
        // model MAY set $connection to a backed/unit enum) before falling back to
        // the package-wide config key, so an explicit per-model override still wins.
        $name = enum_value($this->connection);

        return is_string($name) ? $name : Config::connection();
    }
}

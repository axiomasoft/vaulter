<?php

declare(strict_types=1);

namespace Vaulter\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Override;
use Packman\Attributes\Api;
use Vaulter\Configuration\DatabaseOptions;

use function Illuminate\Support\enum_value;

/**
 * Every default Vaulter model reads its database connection from
 * `configuration key vaulter.database.connection` instead of always resolving to the
 * host's default connection. `app(\Vaulter\Configuration\DatabaseOptions::class)->connection` returns null when the key is
 * unset, and `null` is exactly what `Model::getConnectionName()` already returned
 * before this trait existed — a host that never sets the key sees no change in
 * behaviour, only a host that DOES set it gets every Vaulter-owned table's model
 * routed to that named connection, matching where {@see
 * \Vaulter\Schema\SchemaHelpers::schemaConnection()} created the table.
 *
 * Host apps that swap in their own model (configuration key vaulter-drive.models.*) simply omit
 * this trait if they want a different connection resolution strategy.
 *
 * @mixin Model
 */
#[Api]
trait ResolvesVaulterConnection
{
    #[Override]
    public function getConnectionName(): ?string
    {
        // Mirrors Model::getConnectionName()'s own enum_value() resolution (a
        // model MAY set $connection to a backed/unit enum) before falling back to
        // the package-wide config key, so an explicit per-model override still wins.
        $name = enum_value($this->connection);

        return is_string($name) ? $name : app(DatabaseOptions::class)->connection;
    }
}

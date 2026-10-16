<?php

declare(strict_types=1);

namespace Vaulter\Testing\Tenancy;

use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;

#[Internal]
final class MutableTenantResolver implements TenantResolver
{
    public int|string|null $tenant = 'A';

    public function current(): int|string|null
    {
        return $this->tenant;
    }

    /** Testbench rolls historical host-key migrations back to bigint. */
    public static function clearFixtureStamps(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        foreach (['drives', 'nodes', 'blobs', 'files', 'file_revisions', 'node_grants', 'node_shares', 'activity', 'upload_sessions'] as $entity) {
            $table = app(DatabaseConfig::class)->table($entity);
            if ($schema->hasTable($table) && $schema->hasColumn($table, 'tenant_id')) {
                $db->table($table)->whereNotNull('tenant_id')->update(['tenant_id' => null]);
            }
        }
    }
}

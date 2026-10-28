<?php

declare(strict_types=1);

namespace Vaulter\Tenant;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

/** Read-only check of the actual host schema, performed on an explicit setup/catalog connection. */
final readonly class SchemaGuard
{
    public function __construct(private Repository $config, private DatabaseManager $databases) {}

    public function verify(): void
    {
        if ($this->config->get('vaulter.ids.host_keys') !== 'string') {
            throw new \InvalidArgumentException('identity_encoding_unsupported: vaulter.ids.host_keys must be string.');
        }
        $connection = $this->config->get('vaulter-tenant.schema_connection');
        $columns = $this->config->get('vaulter-tenant.tenant_columns');
        if (!is_string($connection) || $connection === '' || $connection === 'tenant' || !is_array($columns) || $columns === []) {
            throw new \InvalidArgumentException('Explicit schema connection and tenant columns are required.');
        }
        $schema = $this->databases->connection($connection)->getSchemaBuilder();
        foreach ($columns as $table => $name) {
            if (!is_string($table) || !is_string($name)) {
                throw new \InvalidArgumentException('Invalid tenant columns.');
            }
            $found = false;
            foreach ($schema->getColumns($table) as $column) {
                if ($column['name'] !== $name) {
                    continue;
                }
                $type = strtolower($column['type']);
                if (!preg_match('/^(?:character varying|varchar)\((\d+)\)$/D', $type, $matches) || (int) $matches[1] < 64) {
                    throw new \InvalidArgumentException('identity_encoding_unsupported: actual tenant column must be varchar(64) or wider.');
                }
                $found = true;
            }
            if (!$found) {
                throw new \InvalidArgumentException('identity_encoding_unsupported: missing actual tenant column.');
            }
        }
    }
}

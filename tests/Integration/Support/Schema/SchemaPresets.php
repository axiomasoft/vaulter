<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Support\Schema;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * The schema-relevant configuration matrix of snapshot-configs.json: id strategy × host-key type × table
 * prefix × tenancy. Every other setting (features, drivers, scan mode) leaves the DDL untouched — the
 * sensitivity test of SchemaSnapshotTest holds that.
 */
final class SchemaPresets
{
    public const string FIXTURES = __DIR__.'/../../../../packages/core/tests/Fixtures/Schema';

    /** @return array<string, array{ids: string, host_keys: string, table_prefix: string, tenancy: bool}> */
    public static function all(): array
    {
        $file = self::FIXTURES.'/snapshot-configs.json';
        $config = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $axes = $config['axes'];
        $presets = [];

        foreach ($axes['ids'] as $ids) {
            foreach ($axes['host_keys'] as $hostKeys) {
                foreach ($axes['table_prefix'] as $prefixName => $prefix) {
                    foreach ($axes['tenancy'] as $tenancyName => $tenancy) {
                        $id = strtr($config['id'], ['{ids}' => $ids, '{host_keys}' => $hostKeys, '{table_prefix}' => $prefixName, '{tenancy}' => $tenancyName]);
                        $presets[$id] = ['ids' => $ids, 'host_keys' => $hostKeys, 'table_prefix' => $prefix, 'tenancy' => $tenancy];
                    }
                }
            }
        }

        return $presets;
    }

    /** @return array{ids: string, host_keys: string, table_prefix: string, tenancy: bool} */
    public static function get(string $id): array
    {
        return self::all()[$id] ?? throw new RuntimeException('Unknown schema preset ['.$id.'].');
    }

    /** The preset the running application's configuration resolves to. */
    public static function current(Repository $config): string
    {
        $resolved = [
            'ids' => (string) $config->get('vaulter.ids.strategy'),
            'host_keys' => (string) $config->get('vaulter.ids.host_keys'),
            'table_prefix' => (string) $config->get('vaulter.database.table_prefix'),
            'tenancy' => (bool) $config->get('vaulter.tenancy.enabled'),
        ];

        foreach (self::all() as $id => $preset) {
            if ($preset === $resolved) {
                return $id;
            }
        }

        throw new RuntimeException('The configuration matches no schema preset: '.json_encode($resolved));
    }

    /** Environment variables that make a fresh application resolve to the preset. @return list<string> */
    public static function environment(string $id): array
    {
        $preset = self::get($id);

        return [
            'VAULTER_ID_STRATEGY='.$preset['ids'],
            'VAULTER_HOST_KEYS='.$preset['host_keys'],
            'VAULTER_TABLE_PREFIX='.$preset['table_prefix'],
            'VAULTER_TENANT_ENABLED='.($preset['tenancy'] ? 'true' : 'false'),
        ];
    }
}

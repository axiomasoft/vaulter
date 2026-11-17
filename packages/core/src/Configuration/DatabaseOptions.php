<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable database connection and table-prefix configuration (`vaulter.database`).
 */
#[Api(since: '1.0')]
final readonly class DatabaseOptions implements OptionsFactory
{
    private const string PREFIX_PATTERN = '/\A[a-z][a-z0-9_]{0,20}_\z/';

    public function __construct(
        public ?string $connection = null,
        public string $tablePrefix = 'v_',
        public bool $writeGuard = true,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter.database'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['connection', 'table_prefix', 'write_guard'], $path);

        $rawConnection = $config['connection'] ?? null;
        $connection = null;
        if ($rawConnection !== null) {
            if (!is_string($rawConnection)) {
                $validator->add(path: $path.'.connection', message: 'must be a connection name string or null');
            } elseif ($rawConnection !== '') {
                $connection = $rawConnection;
            }
        }

        $rawPrefix = $config['table_prefix'] ?? 'v_';
        $tablePrefix = 'v_';
        if (!is_string($rawPrefix) || preg_match(self::PREFIX_PATTERN, $rawPrefix) !== 1) {
            $validator->add(path: $path.'.table_prefix', message: 'must be a lowercase snake prefix ending with "_"');
        } else {
            $tablePrefix = $rawPrefix;
        }

        $rawWriteGuard = $config['write_guard'] ?? true;
        $writeGuard = true;
        if (!is_bool($rawWriteGuard)) {
            $validator->add(path: $path.'.write_guard', message: 'must be a boolean');
        } else {
            $writeGuard = $rawWriteGuard;
        }

        $validator->failIfAny();

        return new self(
            connection: $connection,
            tablePrefix: $tablePrefix,
            writeGuard: $writeGuard,
        );
    }

    public function table(string $name): string
    {
        return $this->tablePrefix.$name;
    }
}

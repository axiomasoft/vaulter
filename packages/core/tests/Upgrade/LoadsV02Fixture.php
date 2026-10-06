<?php

declare(strict_types=1);

namespace Vaulter\Tests\Upgrade;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use RuntimeException;
use Symfony\Component\Process\Process;
use Vaulter\Database\VaulterDatabase;

trait LoadsV02Fixture
{
    protected function loadV02Fixture(string $strategy, bool $preflightRed = false): void
    {
        if (!in_array($strategy, ['bigint', 'ulid'], true)) {
            throw new RuntimeException('Unknown v0.2 fixture strategy.');
        }

        $connection = app(VaulterDatabase::class)->connection();
        $config = $connection->getConfig();

        if (!$this->app->environment('testing') || $connection->getDriverName() !== 'pgsql'
            || !is_string($config['database']) || !str_ends_with($config['database'], '_test')
            || $connection->transactionLevel() !== 0) {
            throw new RuntimeException('Fixture loading requires an unwrapped PostgreSQL *_test database in APP_ENV=testing.');
        }

        $path = dirname(__DIR__, 4).'/tests/fixtures/v0.2.0/pgsql-'.$strategy.($preflightRed ? '-preflight-red' : '').'.sql';
        if (!is_file($path)) {
            throw new RuntimeException('Missing committed fixture: '.$path);
        }

        config()->set('vaulter.ids.strategy', $strategy);
        config()->set('vaulter.ids.host_keys', 'string');
        $connection->statement('DROP SCHEMA public CASCADE');
        $connection->statement('CREATE SCHEMA public');
        $connection->disconnect();

        (new Process([
            'psql', '-X', '-q', '-v', 'ON_ERROR_STOP=1',
            '-h', (string) $config['host'], '-p', (string) $config['port'],
            '-U', (string) $config['username'], '-d', $config['database'], '-f', $path,
        ], env: ['PGPASSWORD' => (string) $config['password'], 'PGCONNECT_TIMEOUT' => '5']))->mustRun();

        RefreshDatabaseState::$migrated = false;
    }
}

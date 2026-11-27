<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\RlsPolicyBuilder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Database\WaitForLsn;
use Vaulter\Tenancy\Tenancy;
use Vaulter\Tests\TestCase;

uses(TestCase::class);

it('builds idempotent PG RLS, view security_invoker, and role DDL statements', function (): void {
    $builder = app(RlsPolicyBuilder::class);

    $tableSql = $builder->statementsForTable('v_outbox', 'bigint');
    expect($tableSql)->toContain('ALTER TABLE "public"."v_outbox" ENABLE ROW LEVEL SECURITY')
        ->and($tableSql)->toContain('ALTER TABLE "public"."v_outbox" FORCE ROW LEVEL SECURITY')
        ->and(implode("\n", $tableSql))->toContain('AS RESTRICTIVE FOR ALL TO "vaulter_runtime"')
        ->and(implode("\n", $tableSql))->toContain("(SELECT NULLIF(current_setting('vaulter.tenant_id', true), '')::bigint)")
        ->and($builder->statementForView('v_outbox_summary'))->toBe('ALTER VIEW "public"."v_outbox_summary" SET (security_invoker = true)');

    $roleSql = implode("\n", $builder->statementsForRoles(['v_outbox'], ['v_outbox_summary']));
    expect($roleSql)->toContain('vaulter_runtime')
        ->and($roleSql)->toContain('vaulter_migrator')
        ->and($roleSql)->toContain('NOBYPASSRLS');
});

it('validates WaitForLsn LSN format and timeout bounds and handles simulated PG 19 vs PG 18 fallback', function (): void {
    $primary = new class(new PDO('sqlite::memory:')) extends Connection
    {
        public int $queries = 0;

        public function getDriverName(): string
        {
            return 'pgsql';
        }

        public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
        {
            $this->queries++;
            if (str_contains($query, 'server_version_num')) {
                return [(object) ['version_num' => 180004]];
            }

            return [(object) ['source' => 'primary']];
        }
    };

    $standby19Success = new class(new PDO('sqlite::memory:')) extends Connection
    {
        public string $lastWaitSql = '';

        public function getDriverName(): string
        {
            return 'pgsql';
        }

        public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
        {
            if (str_contains($query, 'server_version_num')) {
                return [(object) ['version_num' => 190000]];
            }
            if (str_starts_with($query, 'WAIT FOR LSN')) {
                $this->lastWaitSql = $query;

                return [(object) ['status' => 'success']];
            }

            return [(object) ['source' => 'standby']];
        }
    };

    $standby19Timeout = new class(new PDO('sqlite::memory:')) extends Connection
    {
        public function getDriverName(): string
        {
            return 'pgsql';
        }

        public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
        {
            if (str_contains($query, 'server_version_num')) {
                return [(object) ['version_num' => 190000]];
            }
            if (str_starts_with($query, 'WAIT FOR LSN')) {
                return [(object) ['status' => 'timeout']];
            }

            return [(object) ['source' => 'standby']];
        }
    };

    $dbManager = Mockery::mock(DatabaseManager::class);
    $dbManager->shouldReceive('connection')->with('pgsql')->andReturn($primary);
    $dbManager->shouldReceive('connection')->with('standby_ok')->andReturn($standby19Success);
    $dbManager->shouldReceive('connection')->with('standby_timeout')->andReturn($standby19Timeout);
    $dbManager->shouldReceive('connection')->with('standby_18')->andReturn($primary);

    $waiter = new WaitForLsn(new DatabaseOptions(connection: 'pgsql'), $dbManager);

    expect(fn (): bool => $waiter->wait($standby19Success, 'invalid-lsn'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): bool => $waiter->wait($standby19Success, '0/16B6C50', 0))->toThrow(InvalidArgumentException::class)
        ->and($waiter->supportsWaitForLsn($primary))->toBeFalse()
        ->and($waiter->supportsWaitForLsn($standby19Success))->toBeTrue();

    $okResult = $waiter->readAfterLsn('0/16B6C50', static fn (Connection $c): array => $c->select('SELECT 1'), 'standby_ok', 'pgsql', 120);
    expect($okResult[0]->source)->toBe('standby')
        ->and($standby19Success->lastWaitSql)->toBe("WAIT FOR LSN '0/16B6C50' WITH (MODE 'standby_replay', TIMEOUT '120ms', NO_THROW)");

    $timeoutResult = $waiter->readAfterLsn('0/16B6C50', static fn (Connection $c): array => $c->select('SELECT 1'), 'standby_timeout', 'pgsql', 120);
    expect($timeoutResult[0]->source)->toBe('primary');

    $pg18Result = $waiter->readAfterLsn('0/16B6C50', static fn (Connection $c): array => $c->select('SELECT 1'), 'standby_18', 'pgsql', 120);
    expect($pg18Result[0]->source)->toBe('primary');
});

it('installs PG RLS idempotently, isolates vaulter_runtime with InitPlan, and falls back WaitForLsn on PG 18', function (): void {
    $database = app(VaulterDatabase::class);
    $connection = $database->connection();

    $connection->unprepared('DROP VIEW IF EXISTS v_rls_probe_view');
    $connection->unprepared('DROP TABLE IF EXISTS v_rls_probe');
    $connection->unprepared('CREATE TABLE v_rls_probe (id bigint PRIMARY KEY, tenant_id bigint NOT NULL, label text NOT NULL)');
    $connection->unprepared('CREATE INDEX v_rls_probe_tenant_idx ON v_rls_probe (tenant_id)');
    $connection->unprepared('CREATE VIEW v_rls_probe_view AS SELECT id, tenant_id, label FROM v_rls_probe');

    try {
        $connection->table('v_rls_probe')->insert([
            ['id' => 1, 'tenant_id' => 101, 'label' => 't101'],
            ['id' => 2, 'tenant_id' => 202, 'label' => 't202'],
        ]);

        // Idempotent CLI install (run twice).
        $this->artisan('vaulter:rls:install', ['--roles' => true])->assertExitCode(0);
        $this->artisan('vaulter:rls:install', ['--roles' => true])->assertExitCode(0);

        $rlsFlags = $connection->selectOne(
            "SELECT relrowsecurity, relforcerowsecurity FROM pg_catalog.pg_class WHERE relname = 'v_rls_probe'",
        );
        $viewOptions = $connection->selectOne(
            "SELECT reloptions FROM pg_catalog.pg_class WHERE relname = 'v_rls_probe_view'",
        );

        expect((bool) $rlsFlags->relrowsecurity)->toBeTrue()
            ->and((bool) $rlsFlags->relforcerowsecurity)->toBeTrue()
            ->and((string) $viewOptions->reloptions)->toContain('security_invoker=true');

        config()->set('vaulter.tenancy.enabled', true);
        config()->set('vaulter.tenancy.rls', true);
        $resolver = new class implements TenantResolver
        {
            public int|string|null $tenant = null;

            public function current(): int|string|null
            {
                return $this->tenant;
            }
        };
        $this->app->instance(TenantResolver::class, $resolver);

        // Tenant 101 isolation under vaulter_runtime.
        $resolver->tenant = 101;
        $visible101 = $database->transaction(function ($conn): array {
            $conn->unprepared('SET LOCAL ROLE vaulter_runtime');
            $setting = $conn->selectOne("SELECT current_setting('vaulter.tenant_id', true) AS tid");
            expect($setting->tid)->toBe('101');

            return $conn->table('v_rls_probe')->orderBy('id')->pluck('id')->all();
        });
        expect($visible101)->toBe([1]);

        // Cross-tenant write rejected by RLS WITH CHECK under vaulter_runtime.
        expect(fn () => $database->transaction(function ($conn): void {
            $conn->unprepared('SET LOCAL ROLE vaulter_runtime');
            $conn->table('v_rls_probe')->insert(['id' => 3, 'tenant_id' => 202, 'label' => 'cross']);
        }))->toThrow(QueryException::class);

        // EXPLAIN confirms InitPlan for AS RESTRICTIVE policy.
        $explainText = $database->transaction(function ($conn): string {
            $conn->unprepared('SET LOCAL ROLE vaulter_runtime');
            $conn->unprepared('SET LOCAL enable_seqscan = off');
            $rows = $conn->select('EXPLAIN (FORMAT TEXT) SELECT id FROM v_rls_probe');

            return implode("\n", array_map(static fn (object $r): string => (string) $r->{'QUERY PLAN'}, $rows));
        });
        expect($explainText)->toContain('InitPlan');

        // Fail-closed after transaction: no session GUC leak.
        $postTx = Tenancy::without(fn (): array => $database->transaction(function ($conn): array {
            $conn->unprepared('SET LOCAL ROLE vaulter_runtime');

            return $conn->table('v_rls_probe')->pluck('id')->all();
        }));
        expect($postTx)->toBe([]);

        // A valid primary LSN is always available. WAIT FOR LSN exists from PostgreSQL 19 (MIN_PG_VERSION_NUM);
        // 18 and earlier fall back to the primary.
        $waiter = app(WaitForLsn::class);
        $lsn = $waiter->currentPrimaryLsn();
        $version = (int) $connection->selectOne("SELECT current_setting('server_version_num')::int AS version_num")->version_num;
        expect($lsn)->toMatch('/^[0-9A-Fa-f]+\/[0-9A-Fa-f]+$/')
            ->and($waiter->supportsWaitForLsn($connection))->toBe($version >= WaitForLsn::MIN_PG_VERSION_NUM);
    } finally {
        $connection->unprepared('DROP VIEW IF EXISTS v_rls_probe_view');
        $connection->unprepared('DROP TABLE IF EXISTS v_rls_probe');
    }
})->group('engine:pgsql', 'database');

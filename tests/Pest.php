<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Load\Tests\LoadTestCase;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Testing\Support\TestGroups;
use Vaulter\Tests\Fixtures\HostKeyMysqlTestCase;
use Vaulter\Tests\IdStrategy\BigintStrategyTestCase;
use Vaulter\Tests\IdStrategy\UlidStrategyTestCase;
use Vaulter\Tests\IdStrategy\Uuid7StrategyTestCase;
use Vaulter\Tests\ObservabilityDocuments\P83DocumentsTestCase;
use Vaulter\Tests\Support\OpenApi\OpenApiTestCase;
use Vaulter\Tests\TablePrefixTestCase;
use Vaulter\Tests\TestCase;
use Vaulter\Tests\UlidTestCase;
use Vaulter\Tests\Webhooks\WebhooksTestCase;

$core = dirname(__DIR__).'/packages/core/tests';

// Zone groups (tests/zones.json is the single source): every core sub-zone tags its tests `zone:<name>` so that
// `scripts/t --changed` can select one zone of the core suite. Group-only bindings: the TestCase bindings below
// stay exactly as they are (a second uses(TestCase) on the same path is forbidden by Pest).
$leafZones = [];
foreach (json_decode((string) file_get_contents(__DIR__.'/zones.json'), true, flags: JSON_THROW_ON_ERROR)['zones'] as $zoneName => $zone) {
    if (($zone['parent'] ?? null) === 'core') {
        pest()->group('zone:'.$zoneName)->in(...array_map(static fn (string $path): string => dirname(__DIR__).'/'.$path, $zone['tests']));
        $leafZones[$zoneName] = array_map(static fn (string $path): string => dirname(__DIR__).'/'.$path, $zone['tests']);
    }
}

// Test groups (P1.5): `database` (+ `database:<zone>`), `serial`, `extended`, `stand` and the engine-only `engine:*`, declared in
// <tests>/groups.php of the core suite and of the load suite. The core zone of a `database` path is the leaf zone whose tests[]
// contains it; the other packages bind their own groups from their own Pest.php (a split package has no tests/zones.json).
TestGroups::bind($core, static function (string $path) use ($leafZones): string {
    foreach ($leafZones as $zoneName => $tests) {
        foreach ($tests as $entry) {
            if ($path === $entry || str_starts_with($path, $entry.'/')) {
                return $zoneName;
            }
        }
    }

    throw new LogicException("No core zone owns the database test {$path}; add it to tests/zones.json");
});
TestGroups::bind(dirname(__DIR__).'/load/tests', 'load');

// Drive + Uploads suites moved to the vaulter-drive package (P2 #5 / ADR-0015, D1);
// they run under that package's own Pest suite. The shared DriveTestHelpers stays in
// core/tests/Drive (a plain builder used by many kernel suites), it is not a test case.
uses(OpenApiTestCase::class)->in($core.'/OpenApi');

uses(TestCase::class)->in(
    $core.'/Http',
    $core.'/Observability/Binding',
    $core.'/Observability/Tracing',
);

// Database-free (P1.5): these files run on the booted application without a schema. A file joins this list only after it
// stays green under WithoutDatabaseSchema (a test that reaches the database fails there) — see
// plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P1.5/classification.md.
uses(WithoutDatabaseSchema::class)->in(
    $core.'/Http/Problems',
    $core.'/Observability/Binding',
    $core.'/Observability/Tracing/TraceRecorderBindingTest.php',
    $core.'/Arch/CommandNamingTest.php',
    $core.'/Unit/NodeNameTest.php',
);

uses(TestCase::class, RefreshDatabase::class)->in(
    $core.'/Observability/P83',
    $core.'/Observability/P84',
    $core.'/Observability/P85',
);

uses(P83DocumentsTestCase::class, RefreshDatabase::class)->in(
    $core.'/ObservabilityDocuments',
);

uses(TestCase::class, RefreshDatabase::class)->in(
    $core.'/Feature',
    $core.'/Providers',
    $core.'/Support',
    $core.'/Testing',
    $core.'/Configuration',
    $core.'/Console',
    $core.'/Doctor',
    $core.'/Recipes',
    $core.'/WriteGuard',
);

// The webhook suites boot the opt-in webhooks module switched on (D22); the default boot keeps it off.
uses(WebhooksTestCase::class, RefreshDatabase::class)->in(
    $core.'/Webhooks',
);

uses(TestCase::class, RefreshDatabase::class)->in(...glob($core.'/Schema/*Test.php'));

// Document + Comment (+ the NodeCommentSchemaTest schema check and the TipTap unit
// tests) moved to the vaulter-documents package (P2 #5 / ADR-0015, D2); they run under
// that package's own Pest suite.

// The ForkRace harness (P0.2): forked children race on their OWN PostgreSQL
// connections, so fixtures must be committed — DatabaseMigrations, not
// RefreshDatabase (its wrapping transaction is invisible to another connection).
uses(TestCase::class, DatabaseMigrations::class)->in(
    $core.'/Concurrency',
);

uses(UlidTestCase::class, RefreshDatabase::class)->in(
    $core.'/Ulid',
);

uses(TablePrefixTestCase::class, RefreshDatabase::class)->in(
    $core.'/TablePrefix',
);

// The strategy unit + resolver tests boot the package under the bigint strategy;
// the VaulterSchema shape tests run once per strategy from their own subfolders.
uses(BigintStrategyTestCase::class, RefreshDatabase::class)->in(
    $core.'/IdStrategy/IdStrategyTest.php',
    $core.'/IdStrategy/IdStrategyResolverTest.php',
    $core.'/IdStrategy/Bigint',
);

uses(UlidStrategyTestCase::class, RefreshDatabase::class)->in(
    $core.'/IdStrategy/Ulid',
);

uses(Uuid7StrategyTestCase::class, RefreshDatabase::class)->in(
    $core.'/IdStrategy/Uuid7',
);

uses(LoadTestCase::class)->in(
    dirname(__DIR__).'/load/tests/Health',
    dirname(__DIR__).'/load/tests/Observability',
    dirname(__DIR__).'/load/tests/Feature',
);

uses(HostKeyMysqlTestCase::class)->in($core.'/HostKeyMysql');

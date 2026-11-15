<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Integration\Tests\ObservabilityDocuments\P83DocumentsTestCase;
use Vaulter\Integration\Tests\Support\OpenApi\OpenApiTestCase;
use Vaulter\Integration\Tests\TablePrefixTestCase;
use Vaulter\Integration\Tests\UlidTestCase;
use Vaulter\Load\Tests\LoadTestCase;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Testing\Support\TestGroups;
use Vaulter\Tests\Fixtures\HostKeyMysqlTestCase;
use Vaulter\Tests\IdStrategy\BigintStrategyTestCase;
use Vaulter\Tests\IdStrategy\UlidStrategyTestCase;
use Vaulter\Tests\IdStrategy\Uuid7StrategyTestCase;
use Vaulter\Tests\TestCase;

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
uses(OpenApiTestCase::class)->in(dirname(__DIR__).'/tests/Integration/OpenApi/OpenApiDocumentTest.php', dirname(__DIR__).'/tests/Integration/OpenApi/OpenApiExamplesTest.php', dirname(__DIR__).'/tests/Integration/OpenApi/OpenApiOperationPoliciesTest.php', dirname(__DIR__).'/tests/Integration/OpenApi/OpenApiProblemsTableTest.php', dirname(__DIR__).'/tests/Integration/OpenApi/OpenApiRouteCoverageTest.php', dirname(__DIR__).'/tests/Integration/OpenApi/OpenApiValidationEnabledTest.php', dirname(__DIR__).'/tests/Integration/OpenApi/OpenApiValidatorSelfTest.php', dirname(__DIR__).'/tests/Integration/OpenApi/RouteListSnapshotTest.php');

uses(TestCase::class)->in(dirname(__DIR__).'/packages/core/tests/Http/PreconditionsTest.php', dirname(__DIR__).'/packages/core/tests/Http/Problems/FrameworkExceptionMappingTest.php', dirname(__DIR__).'/packages/core/tests/Observability/Binding/MetricsRecorderBindingTest.php');
uses(Vaulter\Integration\Tests\TestCase::class)->in(dirname(__DIR__).'/tests/Integration/Http/CrossTenantV1Test.php', dirname(__DIR__).'/tests/Integration/Http/HttpKernelV1Test.php', dirname(__DIR__).'/tests/Integration/Http/LegacyRoutesRemovedTest.php', dirname(__DIR__).'/tests/Integration/Http/Problems/ErrorsReferenceTest.php', dirname(__DIR__).'/tests/Integration/Http/Problems/HostRouteUntouchedTest.php', dirname(__DIR__).'/tests/Integration/Http/Problems/ProblemCatalogLangTest.php', dirname(__DIR__).'/tests/Integration/Http/Problems/ProblemMetaRedactionTest.php', dirname(__DIR__).'/tests/Integration/Http/Problems/ProblemRendererTest.php', dirname(__DIR__).'/tests/Integration/Http/Problems/ProblemSpecRulesTest.php', dirname(__DIR__).'/tests/Integration/Observability/Tracing/TraceRecorderBindingTest.php');

// Database-free (P1.5): these files run on the booted application without a schema. A file joins this list only after it
// stays green under WithoutDatabaseSchema (a test that reaches the database fails there) — see
// plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P1.5/classification.md.
uses(WithoutDatabaseSchema::class)->in(dirname(__DIR__).'/packages/core/tests/Arch/CommandNamingTest.php', dirname(__DIR__).'/packages/core/tests/Http/Problems/FrameworkExceptionMappingTest.php', dirname(__DIR__).'/packages/core/tests/Observability/Binding/MetricsRecorderBindingTest.php');
uses(WithoutDatabaseSchema::class)->in(dirname(__DIR__).'/tests/Integration/Http/Problems/ErrorsReferenceTest.php', dirname(__DIR__).'/tests/Integration/Http/Problems/HostRouteUntouchedTest.php', dirname(__DIR__).'/tests/Integration/Http/Problems/ProblemCatalogLangTest.php', dirname(__DIR__).'/tests/Integration/Http/Problems/ProblemMetaRedactionTest.php', dirname(__DIR__).'/tests/Integration/Http/Problems/ProblemRendererTest.php', dirname(__DIR__).'/tests/Integration/Http/Problems/ProblemSpecRulesTest.php', dirname(__DIR__).'/tests/Integration/Observability/Tracing/TraceRecorderBindingTest.php', dirname(__DIR__).'/tests/Integration/Unit/NodeNameTest.php');

uses(Vaulter\Integration\Tests\TestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/Observability/P83/P83UploadSessionActiveMetricsTest.php');
uses(Vaulter\Integration\Tests\TestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/Observability/P83/P83ActivityFeedMetricsTest.php', dirname(__DIR__).'/tests/Integration/Observability/P83/P83Section51MetricsTest.php', dirname(__DIR__).'/tests/Integration/Observability/P84/P84Section52HostMetricsTest.php', dirname(__DIR__).'/tests/Integration/Observability/P85/P85Section53CoreMetricsTest.php');

uses(P83DocumentsTestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/ObservabilityDocuments/P83DocumentsMetricsTest.php');

uses(TestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/packages/core/tests/Configuration/ScheduleFrequencyTest.php', dirname(__DIR__).'/packages/core/tests/Configuration/VaulterConfigTest.php', dirname(__DIR__).'/packages/core/tests/Console/CommandsReferenceCommandTest.php', dirname(__DIR__).'/packages/core/tests/Doctor/DoctorJsonSchemaTest.php', dirname(__DIR__).'/packages/core/tests/Doctor/DoctorRunnerTest.php', dirname(__DIR__).'/packages/core/tests/Feature/NodeCommentFeatureOffTest.php', dirname(__DIR__).'/packages/core/tests/Providers/BindingsRegistrarTest.php', dirname(__DIR__).'/packages/core/tests/Providers/EventsRegistrarTest.php', dirname(__DIR__).'/packages/core/tests/Support/TenantFootgunGuardTest.php');
uses(Vaulter\Integration\Tests\TestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/Console/InstallCommandTest.php');
uses(Vaulter\Integration\Tests\TestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/Configuration/ConfigDefaultsLoadingTest.php', dirname(__DIR__).'/tests/Integration/Configuration/ConfigNormalizerTest.php', dirname(__DIR__).'/tests/Integration/Configuration/ConfigValidatorTest.php', dirname(__DIR__).'/tests/Integration/Console/ConfigReferenceCommandTest.php', dirname(__DIR__).'/tests/Integration/Console/SchedulingRegistrarTest.php', dirname(__DIR__).'/tests/Integration/Doctor/CoreDoctorChecksTest.php', dirname(__DIR__).'/tests/Integration/Doctor/DoctorReadOnlyTest.php', dirname(__DIR__).'/tests/Integration/Feature/EventTypeTest.php', dirname(__DIR__).'/tests/Integration/Feature/ServiceProviderTest.php', dirname(__DIR__).'/tests/Integration/Feature/VaulterManagerExporterHandlerSeamTest.php', dirname(__DIR__).'/tests/Integration/Providers/AccessRegistrarTest.php', dirname(__DIR__).'/tests/Integration/Recipes/PermissionDriverRecipeTest.php', dirname(__DIR__).'/tests/Integration/Support/CollaborationDefaultsGuardTest.php', dirname(__DIR__).'/tests/Integration/Support/ContractsDocFqcnGuardTest.php', dirname(__DIR__).'/tests/Integration/Support/DocsDriftGuardTest.php', dirname(__DIR__).'/tests/Integration/Support/DomainExceptionStatusTest.php', dirname(__DIR__).'/tests/Integration/Support/DriveNameRulesConfigSeamTest.php', dirname(__DIR__).'/tests/Integration/Support/EnumCaseAttributeResolverInstanceCacheTest.php', dirname(__DIR__).'/tests/Integration/Support/ReleaseReadinessGuardTest.php', dirname(__DIR__).'/tests/Integration/Support/SnapshotResourceTimestampTest.php', dirname(__DIR__).'/tests/Integration/Testing/ContractSuitesTest.php', dirname(__DIR__).'/tests/Integration/Testing/InteractsWithVaulterTest.php', dirname(__DIR__).'/tests/Integration/WriteGuard/WriteScopeTest.php');

uses(Vaulter\Integration\Tests\TestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/Schema/HostKeyColumnsSchemaTest.php');
uses(Vaulter\Integration\Tests\TestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/Schema/BlobDedupIndexTest.php', dirname(__DIR__).'/tests/Integration/Schema/CanonicalSchemaTest.php', dirname(__DIR__).'/tests/Integration/Schema/HotPathIndexesTest.php', dirname(__DIR__).'/tests/Integration/Schema/KeyColumnTypesTest.php', dirname(__DIR__).'/tests/Integration/Schema/SchemaSnapshotTest.php');

// Document + Comment (+ the NodeCommentSchemaTest schema check and the TipTap unit
// tests) moved to the vaulter-documents package (P2 #5 / ADR-0015, D2); they run under
// that package's own Pest suite.

// The ForkRace harness (P0.2): forked children race on their OWN PostgreSQL
// connections, so fixtures must be committed — DatabaseMigrations, not
// RefreshDatabase (its wrapping transaction is invisible to another connection).
uses(TestCase::class, DatabaseMigrations::class)->in(dirname(__DIR__).'/packages/core/tests/Concurrency/ForkRaceTest.php');
uses(Vaulter\Integration\Tests\TestCase::class, DatabaseMigrations::class)->in(dirname(__DIR__).'/tests/Integration/Concurrency/EventRecorderRollbackPgTest.php', dirname(__DIR__).'/tests/Integration/Concurrency/IdempotencyRaceTest.php', dirname(__DIR__).'/tests/Integration/Concurrency/OutboxFanoutRaceTest.php', dirname(__DIR__).'/tests/Integration/Concurrency/OutboxLateCommitTest.php', dirname(__DIR__).'/tests/Integration/Concurrency/OutboxLeaseTakeoverTest.php');

uses(UlidTestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/Ulid/UlidModeTest.php');

uses(TablePrefixTestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/TablePrefix/TablePrefixTest.php');

// The strategy unit + resolver tests boot the package under the bigint strategy;
// the VaulterSchema shape tests run once per strategy from their own subfolders.
uses(BigintStrategyTestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/packages/core/tests/IdStrategy/Bigint/VaulterSchemaBigintTest.php', dirname(__DIR__).'/packages/core/tests/IdStrategy/IdStrategyResolverTest.php', dirname(__DIR__).'/packages/core/tests/IdStrategy/IdStrategyTest.php');
uses(Vaulter\Integration\Tests\IdStrategy\BigintStrategyTestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/IdStrategy/Bigint/DriveMigrationsBigintTest.php');
uses(Vaulter\Integration\Tests\IdStrategy\BigintStrategyTestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/IdStrategy/Bigint/HostKeysBigintTest.php');

uses(UlidStrategyTestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/packages/core/tests/IdStrategy/Ulid/VaulterSchemaUlidTest.php');
uses(Vaulter\Integration\Tests\IdStrategy\UlidStrategyTestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/IdStrategy/Ulid/DriveMigrationsUlidTest.php');
uses(Vaulter\Integration\Tests\IdStrategy\UlidStrategyTestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/IdStrategy/Ulid/HostKeysTest.php', dirname(__DIR__).'/tests/Integration/IdStrategy/Ulid/WideNodeListingTest.php');

uses(Uuid7StrategyTestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/packages/core/tests/IdStrategy/Uuid7/VaulterSchemaUuid7Test.php');
uses(Vaulter\Integration\Tests\IdStrategy\Uuid7StrategyTestCase::class, RefreshDatabase::class)->in(dirname(__DIR__).'/tests/Integration/IdStrategy/Uuid7/DriveMigrationsUuid7Test.php');

uses(LoadTestCase::class)->in(
    dirname(__DIR__).'/load/tests/Health',
    dirname(__DIR__).'/load/tests/Observability',
    dirname(__DIR__).'/load/tests/Feature',
);

uses(HostKeyMysqlTestCase::class)->in(dirname(__DIR__).'/packages/core/tests/HostKeyMysql/HostKeyColumnsMysqlTest.php');

TestGroups::bind(__DIR__.'/Integration', 'integration');

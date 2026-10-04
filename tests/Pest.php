<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Load\Tests\LoadTestCase;
use Vaulter\Tests\ActivityGate\ActivityDisabledTestCase;
use Vaulter\Tests\Fixtures\HostKeyMysqlTestCase;
use Vaulter\Tests\IdStrategy\BigintStrategyTestCase;
use Vaulter\Tests\IdStrategy\UlidStrategyTestCase;
use Vaulter\Tests\IdStrategy\Uuid7StrategyTestCase;
use Vaulter\Tests\ObservabilityDocuments\P83DocumentsTestCase;
use Vaulter\Tests\SpatieTestCase;
use Vaulter\Tests\TablePrefixTestCase;
use Vaulter\Tests\TestCase;
use Vaulter\Tests\UlidTestCase;

$core = dirname(__DIR__).'/packages/core/tests';

// Drive + Uploads suites moved to the vaulter-drive package (P2 #5 / ADR-0015, D1);
// they run under that package's own Pest suite. The shared DriveTestHelpers stays in
// core/tests/Drive (a plain builder used by many kernel suites), it is not a test case.
uses(TestCase::class)->in(
    $core.'/Observability/Binding',
    $core.'/Observability/Tracing',
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
    $core.'/Access',
    $core.'/Activity',
    $core.'/Feature',
    $core.'/Providers',
    $core.'/Profiles',
    $core.'/Schema',
    $core.'/Sharing',
    $core.'/Support',
    $core.'/Webhooks',
);

// The spatie-driver tests boot the package alongside spatie/laravel-permission with
// the v8 teams feature; kept in their own top-level folder so the Access binding
// above does not also claim them (Pest forbids two test cases for one path).
uses(SpatieTestCase::class, RefreshDatabase::class)->in(
    $core.'/Spatie',
);

// The activity-feature-OFF tests boot the package with 'activity' disabled so the
// subscriber is never registered; kept in their own top-level folder so the Activity
// binding above does not also claim them (Pest forbids two test cases for one path).
uses(ActivityDisabledTestCase::class, RefreshDatabase::class)->in(
    $core.'/ActivityGate',
);

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

// Upgrade tests own the fixture schema; no wrapping transaction or automatic migrations.
uses(TestCase::class)->in($core.'/Upgrade');

uses(HostKeyMysqlTestCase::class)->in($core.'/HostKeyMysql');

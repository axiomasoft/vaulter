<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Tests\ActivityGate\ActivityDisabledTestCase;
use Vaulter\Tests\IdStrategy\BigintStrategyTestCase;
use Vaulter\Tests\IdStrategy\UlidStrategyTestCase;
use Vaulter\Tests\IdStrategy\Uuid7StrategyTestCase;
use Vaulter\Tests\SpatieTestCase;
use Vaulter\Tests\TablePrefixTestCase;
use Vaulter\Tests\TestCase;
use Vaulter\Tests\UlidTestCase;

$core = dirname(__DIR__).'/packages/core/tests';

// Drive + Uploads suites moved to the vaulter-drive package (P2 #5 / ADR-0015, D1);
// they run under that package's own Pest suite. The shared DriveTestHelpers stays in
// core/tests/Drive (a plain builder used by many kernel suites), it is not a test case.
uses(TestCase::class, RefreshDatabase::class)->in(
    $core.'/Access',
    $core.'/Activity',
    $core.'/Feature',
    $core.'/Panels',
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

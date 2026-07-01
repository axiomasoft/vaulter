<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Tests\ActivityGate\ActivityDisabledTestCase;
use Vaulter\Tests\Comment\CommentsTestCase;
use Vaulter\Tests\Document\DocumentTestCase;
use Vaulter\Tests\IdStrategy\BigintStrategyTestCase;
use Vaulter\Tests\IdStrategy\UlidStrategyTestCase;
use Vaulter\Tests\IdStrategy\Uuid7StrategyTestCase;
use Vaulter\Tests\SpatieTestCase;
use Vaulter\Tests\TablePrefixTestCase;
use Vaulter\Tests\TestCase;
use Vaulter\Tests\UlidTestCase;

$core = dirname(__DIR__).'/packages/core/tests';

uses(TestCase::class, RefreshDatabase::class)->in(
    $core.'/Access',
    $core.'/Activity',
    $core.'/Cas',
    $core.'/Drive',
    $core.'/Feature',
    $core.'/Panels',
    $core.'/Schema',
    $core.'/Sharing',
    $core.'/Uploads',
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

// The DocDrive (M4a) tests boot the package with the 'document_versions' feature ON
// so the v_node_document_versions schema is migrated. Kept in their own top-level
// folder so the base binding above does not also claim them.
uses(DocumentTestCase::class, RefreshDatabase::class)->in(
    $core.'/Document',
);

// The node-comment (ADR-0014 шаг 1) HTTP tests boot the package with the 'comments'
// feature ON so the routes register at boot; kept in their own top-level folder so the
// base binding never also claims them.
uses(CommentsTestCase::class, RefreshDatabase::class)->in(
    $core.'/Comment',
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

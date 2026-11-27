<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Tests\ActivityGate\ActivityDisabledTestCase;
use Vaulter\Drive\Tests\PanelTestCase;
use Vaulter\Drive\Tests\SpatieTestCase;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Testing\Support\TestGroups;

uses(TestCase::class, RefreshDatabase::class)->in(
    __DIR__.'/Access',
    __DIR__.'/Sharing',
    __DIR__.'/Drive',
    __DIR__.'/Uploads',
    __DIR__.'/Feature',
    __DIR__.'/Observability',
    __DIR__.'/PublicApi',
    __DIR__.'/WriteGuard',
    __DIR__.'/Doctor',
    __DIR__.'/Testing',
    __DIR__.'/Content',
    __DIR__.'/Activity',
);

uses(TestCase::class)->in(__DIR__.'/Providers', __DIR__.'/Http', __DIR__.'/Console', __DIR__.'/Modules');

uses(TestCase::class, RefreshDatabase::class)->in(__DIR__.'/Recipes');

// ProfileRuntimeTest binds its own TestCase in the file (Pest forbids two bases for one path).
uses(TestCase::class, RefreshDatabase::class)->in(...array_values(array_filter(
    glob(__DIR__.'/Profiles/*Test.php') ?: [],
    static fn (string $path): bool => basename($path) !== 'ProfileRuntimeTest.php',
)));

// The sequence carrier and the scan-evidence schema tests bind their own TestCase in the file (Pest forbids two bases for one path).
uses(TestCase::class, RefreshDatabase::class)->in(...array_values(array_filter(
    glob(__DIR__.'/Schema/*Test.php') ?: [],
    static fn (string $path): bool => !in_array(basename($path), ['NodeSequenceSchemaTest.php', 'ScanEvidenceSchemaTest.php'], true),
)));

// The activity-feature-OFF tests boot the package with 'activity' disabled so the subscriber is never registered; kept in
// their own top-level folder so the Activity binding above does not also claim them (Pest forbids two test cases for one path).
uses(ActivityDisabledTestCase::class, RefreshDatabase::class)->in(__DIR__.'/ActivityGate');

// Panels live in their own directory because they need a DIFFERENT TestCase: the panel must
// be in the config BEFORE boot (ProfileRegistry is resolved in packageBooted), and Pest
// allows exactly one base TestCase per directory.
uses(PanelTestCase::class, RefreshDatabase::class)->in(__DIR__.'/Panels');

// The spatie-driver tests boot the package alongside spatie/laravel-permission with the v8 teams feature; kept in their own
// folder so the Access binding above does not also claim them (Pest forbids two test cases for one path).
uses(SpatieTestCase::class, RefreshDatabase::class)->in(__DIR__.'/Spatie');

// The concurrency slice runs on COMMITTED rows (no wrapping transaction) so a forked
// child on its own connection can see the session — RefreshDatabase's per-test
// transaction would hide it. DatabaseMigrations migrates fresh and commits.
uses(TestCase::class, DatabaseMigrations::class)->in(__DIR__.'/Concurrency');

uses(TestCase::class)->in(__DIR__.'/Configuration');

uses(TestCase::class)->in(__DIR__.'/Arch');

// Database-free: these files run on the booted application without a schema; each one stayed green under
// WithoutDatabaseSchema (a test that reaches the database fails there).
uses(WithoutDatabaseSchema::class)->in(
    __DIR__.'/Arch',
    __DIR__.'/Configuration',
    __DIR__.'/Console',
    __DIR__.'/Modules',
    __DIR__.'/Providers',
    __DIR__.'/Http/DriveProblemCodesTest.php',
);

// Test groups: database, serial, extended, stand and the engine-only engine:* — see groups.php.
TestGroups::bind(__DIR__, 'drive');

<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Tests\PanelTestCase;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Testing\Support\TestGroups;

uses(TestCase::class, RefreshDatabase::class)->in('Drive', 'Uploads', 'Feature', 'Observability', 'PublicApi', 'WriteGuard', 'Doctor', 'Testing');

uses(TestCase::class)->in('Providers', 'Http', 'Console', 'Modules');

uses(TestCase::class, RefreshDatabase::class)->in('Recipes');

// Панели живут отдельной директорией, потому что им нужен ДРУГОЙ TestCase: панель обязана
// попасть в конфиг ДО boot'а (ProfileRegistry резолвится в packageBooted с P4.4), а Pest
// разрешает ровно один базовый TestCase на директорию.
uses(PanelTestCase::class, RefreshDatabase::class)->in('Panels');

// The concurrency slice runs on COMMITTED rows (no wrapping transaction) so a forked
// child on its own connection can see the session — RefreshDatabase's per-test
// transaction would hide it. DatabaseMigrations migrates fresh and commits.
uses(TestCase::class, DatabaseMigrations::class)->in('Concurrency');

uses(TestCase::class)->in('Configuration');

uses(TestCase::class)->in('Arch');

// Database-free (P1.5): these files run on the booted application without a schema; each one stayed green under
// WithoutDatabaseSchema (a test that reaches the database fails there) — see
// plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P1.5/classification.md.
uses(WithoutDatabaseSchema::class)->in('Arch', 'Configuration', 'Console', 'Modules', 'Providers', 'Http/DriveProblemCodesTest.php');

// Test groups (P1.5): database, serial, extended, stand and the engine-only engine:* — see groups.php.
TestGroups::bind(__DIR__, 'drive');

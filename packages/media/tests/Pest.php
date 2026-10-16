<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Media\Tests\TestCase;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Testing\Support\TestGroups;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Observability', 'Doctor');

uses(TestCase::class)->in('Cas');

uses(TestCase::class, RefreshDatabase::class)->in('Recipes');
$unwrapped = ['BlobScopeKeyTest.php', 'ScopeKeyReconcileTest.php', 'BlobPinTransactionTest.php', 'PinSweepRaceTest.php'];
foreach (glob(__DIR__.'/Cas/*Test.php') as $file) {
    uses(in_array(basename($file), $unwrapped, true)
        ? DatabaseMigrations::class
        : RefreshDatabase::class)->in($file);
}

uses(TestCase::class)->in('Configuration', 'Console', 'Support');

// Database-free (P1.5): these files run on the booted application without a schema; each one stayed green under
// WithoutDatabaseSchema (a test that reaches the database fails there) — see
// plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P1.5/classification.md.
uses(WithoutDatabaseSchema::class)->in('Configuration', 'Console', 'Support');

// Test groups (P1.5): database, serial, extended, stand and the engine-only engine:* — see groups.php.
TestGroups::bind(__DIR__, 'media');

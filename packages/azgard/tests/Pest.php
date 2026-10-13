<?php

declare(strict_types=1);

use AzGuard\Testing\InteractsWithAzGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Azgard\Tests\Access\AccessTestCase;
use Vaulter\Azgard\Tests\RealAzgard\RealAzgardTestCase;
use Vaulter\Azgard\Tests\RealAzgardIsolated\IsolatedRealAzgardTestCase;
use Vaulter\Azgard\Tests\TestCase;
use Vaulter\Tests\Support\TestGroups;

// The real-ecosystem e2e (real AzGuard v1, DB-backed grants) uses its own case.
uses(RealAzgardTestCase::class, RefreshDatabase::class, InteractsWithAzGuard::class)->in('RealAzgard');
uses(IsolatedRealAzgardTestCase::class, RefreshDatabase::class, InteractsWithAzGuard::class)->in('RealAzgardIsolated');

// The adapter / provider / Vaulter-resolver unit + integration tests (no azguard DB).
uses(TestCase::class)->in('AdapterTest.php', 'ActorGuardTest.php', 'ProviderBindingTest.php', 'VaulterResolverIntegrationTest.php');

uses(AccessTestCase::class, RefreshDatabase::class)->in('Access');
uses(TestCase::class)->in('ConfigurationTest.php');

uses(TestCase::class)->in('Doctor');

// Test groups (P1.5): database, serial, extended, stand and the engine-only engine:* — see groups.php. This package does not
// autoload the core test classes, so the helper comes through the path-repo symlink of core.
require_once dirname(__DIR__).'/vendor/axioma-studio/vaulter-core/tests/Support/TestGroups.php';

TestGroups::bind(__DIR__, 'azgard');

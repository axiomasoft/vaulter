<?php

declare(strict_types=1);

use AzGuard\Testing\InteractsWithAzGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\AzGuard\Tests\Access\AccessTestCase;
use Vaulter\AzGuard\Tests\RealAzGuard\RealAzGuardTestCase;
use Vaulter\AzGuard\Tests\RealAzGuardIsolated\IsolatedRealAzGuardTestCase;
use Vaulter\AzGuard\Tests\TestCase;
use Vaulter\Testing\Support\TestGroups;

// The real-ecosystem e2e (real AzGuard v1, DB-backed grants) uses its own case.
uses(RealAzGuardTestCase::class, RefreshDatabase::class, InteractsWithAzGuard::class)->in('RealAzGuard');
uses(IsolatedRealAzGuardTestCase::class, RefreshDatabase::class, InteractsWithAzGuard::class)->in('RealAzGuardIsolated');

// The adapter / provider / Vaulter-resolver unit + integration tests (no azguard DB).
uses(TestCase::class)->in('AdapterTest.php', 'ActorGuardTest.php', 'ProviderBindingTest.php', 'VaulterResolverIntegrationTest.php');

uses(AccessTestCase::class, RefreshDatabase::class)->in('Access');
uses(TestCase::class)->in('ConfigurationTest.php');

uses(TestCase::class)->in('Doctor');

// Test groups (P1.5): database, serial, extended, stand and the engine-only engine:* — see groups.php. The helper is part of the core Testing kit.

TestGroups::bind(__DIR__, 'azguard');

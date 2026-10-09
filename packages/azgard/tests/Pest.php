<?php

declare(strict_types=1);

use AzGuard\Testing\InteractsWithAzGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Azgard\Tests\Access\AccessTestCase;
use Vaulter\Azgard\Tests\RealAzgard\RealAzgardTestCase;
use Vaulter\Azgard\Tests\TestCase;

// The real-ecosystem e2e (real AzGuard v1, DB-backed grants) uses its own case.
uses(RealAzgardTestCase::class, RefreshDatabase::class, InteractsWithAzGuard::class)->in('RealAzgard');

// The adapter / provider / Vaulter-resolver unit + integration tests (no azguard DB).
uses(TestCase::class)->in('AdapterTest.php', 'ProviderBindingTest.php', 'VaulterResolverIntegrationTest.php');

uses(AccessTestCase::class, RefreshDatabase::class)->in('Access');
uses(TestCase::class)->in('ConfigurationTest.php');

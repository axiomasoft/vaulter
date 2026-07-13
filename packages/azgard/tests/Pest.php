<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Azgard\Tests\RealAzgard\RealAzgardTestCase;
use Vaulter\Azgard\Tests\TestCase;

// The real-ecosystem e2e (real azguard + azguard-context, DB-backed) uses its own case.
uses(RealAzgardTestCase::class, RefreshDatabase::class)->in('RealAzgard');

// The adapter / provider / Vaulter-resolver unit + integration tests (no azguard DB).
uses(TestCase::class)->in('AdapterTest.php', 'ProviderBindingTest.php', 'VaulterResolverIntegrationTest.php');

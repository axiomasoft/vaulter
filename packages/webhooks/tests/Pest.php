<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Testing\Support\TestGroups;
use Vaulter\Webhooks\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Webhooks', 'Http', 'Feature', 'Outbox', 'Console', 'Configuration', 'Modules', 'Schema');

// Committed rows: the forked races and the commit-time tests need a connection of their own to see the fixtures,
// and RefreshDatabase's wrapping transaction would hide them. DatabaseMigrations migrates fresh and commits.
uses(TestCase::class, DatabaseMigrations::class)->in('Events', 'Concurrency');

uses(TestCase::class)->in('Observability');

// Test groups: database, serial, extended, stand and the engine-only engine:* — see groups.php.
TestGroups::bind(__DIR__, 'webhooks');

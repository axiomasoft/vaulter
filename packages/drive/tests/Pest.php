<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Drive', 'Uploads', 'Feature');

// The concurrency slice runs on COMMITTED rows (no wrapping transaction) so a forked
// child on its own connection can see the session — RefreshDatabase's per-test
// transaction would hide it. DatabaseMigrations migrates fresh and commits.
uses(TestCase::class, DatabaseMigrations::class)->in('Concurrency');

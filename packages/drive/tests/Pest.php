<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Tests\PanelTestCase;
use Vaulter\Drive\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Drive', 'Uploads', 'Feature', 'Observability', 'PublicApi');

uses(TestCase::class)->in('Providers');

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

<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Tests\TablePrefixTestCase;
use Vaulter\Tests\TestCase;
use Vaulter\Tests\UlidTestCase;

$core = dirname(__DIR__).'/packages/core/tests';

uses(TestCase::class, RefreshDatabase::class)->in(
    $core.'/Feature',
);

uses(UlidTestCase::class, RefreshDatabase::class)->in(
    $core.'/Ulid',
);

uses(TablePrefixTestCase::class, RefreshDatabase::class)->in(
    $core.'/TablePrefix',
);

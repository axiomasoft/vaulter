<?php

declare(strict_types=1);

use DocVault\Tests\TestCase;
use DocVault\Tests\UlidTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

$core = dirname(__DIR__).'/packages/core/tests';

uses(TestCase::class, RefreshDatabase::class)->in(
    $core.'/Feature',
);

uses(UlidTestCase::class, RefreshDatabase::class)->in(
    $core.'/Ulid',
);

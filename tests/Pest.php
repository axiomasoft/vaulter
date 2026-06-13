<?php

declare(strict_types=1);

use DocVault\Tests\TestCase;

$core = dirname(__DIR__).'/packages/core/tests';

uses(TestCase::class)->in(
    $core.'/Feature',
);

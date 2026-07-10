<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Tests\TestCase;

// Скептик-верификация F-P2.3-02. Отдельный Pest.php (как в artifacts/repro/P2.3/) —
// иначе дублирование uses(TestCase::class, RefreshDatabase::class) и в файле теста,
// и в родительском Pest.php даёт двойной migrate ("table already exists"), что и
// произошло при прогоне существующего C6NoConnectionParamTest.php напрямую.
uses(TestCase::class, RefreshDatabase::class)->in(__DIR__);

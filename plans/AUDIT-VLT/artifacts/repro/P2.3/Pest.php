<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Tests\TestCase;

// Repro-стенд item'а P2.3 (AUDIT-VLT). Живёт вне packages/*/tests (D6) — грузит
// пакетный Vaulter\Tests\TestCase через autoload-dev (Vaulter\Tests\ =>
// packages/core/tests/), а не отдельный дублирующий TestCase.
uses(TestCase::class, RefreshDatabase::class)->in(__DIR__);

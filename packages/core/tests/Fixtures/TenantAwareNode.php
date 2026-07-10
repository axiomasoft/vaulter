<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures;

use Vaulter\Models\Drive\Node;
use Vaulter\Support\TenantFootgunGuard;

/**
 * A bare Node subclass — no behaviour change, no scope added. It exists ONLY to
 * be a non-default `config('vaulter.models.node')` value: F-P1.9-01's boot-time
 * footgun guard ({@see TenantFootgunGuard::check()})
 * fires when `vaulter.tenant.enabled=true` AND the node model is still the
 * package default, on the theory that the default carries no tenant scope. Tests
 * that deliberately run `tenant.enabled=true` for a reason OTHER than exercising
 * that exact misconfiguration (UlidModeTest: ULID key handling + schema column
 * emission, neither of which is about query-level isolation) swap this in to
 * declare "I know, this is intentional" — the same escape hatch ADR-0003 grants a
 * real host (subclass the model). It does NOT itself enforce a TenantScope: a
 * real host wanting the isolation would add one.
 */
final class TenantAwareNode extends Node {}

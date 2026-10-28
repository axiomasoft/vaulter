<?php

declare(strict_types=1);

namespace Vaulter\Release\Contract;

use RuntimeException;

/**
 * A contract input the check cannot trust: an unknown format, version, field or adapter, a duplicate identity, a missing
 * required snapshot, an empty required glob, corrupted JSON, an unsafe path. Exit 2 — never a pass, never a BREAK.
 */
final class ContractError extends RuntimeException {}

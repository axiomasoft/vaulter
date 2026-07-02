<?php

declare(strict_types=1);

// The documents package is strict-typed end to end.
arch('strict types')
    ->expect('Vaulter\Documents')
    ->toUseStrictTypes();

// T6 — documents requires ONLY core in production; it never depends on drive or
// media (both are optional siblings over the same kernel, not peers of documents).
arch('documents never depends on drive or media (T6 — documents requires only core)')
    ->expect('Vaulter\Documents')
    ->not->toUse(['Vaulter\Drive', 'Vaulter\Media']);

<?php

declare(strict_types=1);

// The media package is strict-typed end to end.
arch('strict types')
    ->expect('Vaulter\Media')
    ->toUseStrictTypes();

// The CAS layer stays ACL-agnostic: nothing under Vaulter\Media may reach into the
// core access-control machinery. This is the structural guard behind decision D3
// (per-file ACL lives in core; media is a pure content-addressed blob layer).
arch('media never touches the access layer')
    ->expect('Vaulter\Media')
    ->not->toUse([
        'Vaulter\Access\AccessResolver',
        'Vaulter\Contracts\Access\GrantAuthoritativeDriver',
    ]);

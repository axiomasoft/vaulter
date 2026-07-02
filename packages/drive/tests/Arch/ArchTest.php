<?php

declare(strict_types=1);

// The drive package is strict-typed end to end.
arch('strict types')
    ->expect('Vaulter\Drive')
    ->toUseStrictTypes();

// T11 — drive and documents are independent siblings over core: nothing under
// Vaulter\Drive may reach into the documents engine. The Drive node read shape that
// embedded the document handler descriptor moved to core (Http\Resources\Drive\
// NodeResource) precisely so this stays true.
arch('drive never depends on documents')
    ->expect('Vaulter\Drive')
    ->not->toUse('Vaulter\Documents');

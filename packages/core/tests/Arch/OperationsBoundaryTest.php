<?php

declare(strict_types=1);
use Vaulter\Operations\ActivityOperations;

arch('core v1 controllers use Operations rather than Actions or Repositories')
    ->expect([
        'Vaulter\Http\Controllers\Activity',
        'Vaulter\Http\Controllers\Changes',
    ])->not->toUse(['Vaulter\Actions', 'Vaulter\Repositories']);

// Access and Share operations moved to vaulter-drive (P4.8); Activity stays until P4.10 and reads only the access types that moved.
arch('core activity Operations depend on the drive package only through the moved access types')
    ->expect([
        ActivityOperations::class,
    ])->not->toUse('Vaulter\Drive')
    ->ignoring(['Vaulter\Drive\Access', 'Vaulter\Drive\Enums\Access']);

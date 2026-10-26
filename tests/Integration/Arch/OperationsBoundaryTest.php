<?php

declare(strict_types=1);
use Vaulter\Drive\Operations\ActivityOperations;

arch('core v1 controllers use Operations rather than Actions or Repositories')
    ->expect([
        'Vaulter\Drive\Http\Controllers\Activity',
        'Vaulter\Drive\Http\Controllers\Changes',
    ])->not->toUse(['Vaulter\Actions', 'Vaulter\Repositories', 'Vaulter\Drive\Actions', 'Vaulter\Drive\Repositories']);

// Activity Operations moved to vaulter-drive (P4.10); they read through the activity repositories, never the write Actions.
arch('core activity Operations depend on the drive package only through the moved access types')
    ->expect([
        ActivityOperations::class,
    ])->not->toUse(['Vaulter\Drive\Actions', 'Vaulter\Actions']);

<?php

declare(strict_types=1);
use Vaulter\Operations\AccessOperations;
use Vaulter\Operations\ActivityOperations;
use Vaulter\Operations\ShareOperations;

arch('core v1 controllers use Operations rather than Actions or Repositories')
    ->expect([
        'Vaulter\Http\Controllers\Access',
        'Vaulter\Http\Controllers\Sharing',
        'Vaulter\Http\Controllers\Activity',
        'Vaulter\Http\Controllers\Changes',
    ])->not->toUse(['Vaulter\Actions', 'Vaulter\Repositories']);

arch('core access share and activity Operations have no drive package dependency')
    ->expect([
        AccessOperations::class,
        ShareOperations::class,
        ActivityOperations::class,
    ])->not->toUse('Vaulter\Drive');

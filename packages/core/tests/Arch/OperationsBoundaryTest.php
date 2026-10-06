<?php

declare(strict_types=1);
use Vaulter\Http\Controllers\Access\GrantController;
use Vaulter\Http\Controllers\Activity\ActivityController;
use Vaulter\Http\Controllers\Sharing\ShareController;
use Vaulter\Operations\AccessOperations;
use Vaulter\Operations\ActivityOperations;
use Vaulter\Operations\ShareOperations;

arch('migrated core controllers use Operations rather than Actions or Repositories')
    ->expect([
        GrantController::class,
        ShareController::class,
        ActivityController::class,
    ])->not->toUse(['Vaulter\Actions', 'Vaulter\Repositories']);

arch('core access share and activity Operations have no drive package dependency')
    ->expect([
        AccessOperations::class,
        ShareOperations::class,
        ActivityOperations::class,
    ])->not->toUse('Vaulter\Drive');

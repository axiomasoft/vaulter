<?php

declare(strict_types=1);
use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Resources\NodeShareResource;

arch('migrated Filament resources use Operations rather than Actions or Repositories')
    ->expect([
        NodeGrantResource::class,
        NodeShareResource::class,
        ActivityResource::class,
    ])->not->toUse(['Vaulter\Actions', 'Vaulter\Repositories']);

<?php

declare(strict_types=1);
use Illuminate\Http\Request;

arch('all package code declares strict types')
    ->expect('Vaulter')
    ->toUseStrictTypes();

arch('no debug helpers leak into the package')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('actions are final')
    ->expect('Vaulter\Actions')
    ->toBeFinal();

arch('value objects are readonly')
    ->expect('Vaulter\ValueObjects')
    ->toBeReadonly();

arch('data transfer objects are readonly')
    ->expect('Vaulter\Data')
    ->toBeReadonly();

arch('events are readonly')
    ->expect('Vaulter\Events')
    ->toBeReadonly();

arch('contracts are interfaces')
    ->expect('Vaulter\Contracts')
    ->toBeInterfaces();

arch('actions never depend on the HTTP request')
    ->expect('Vaulter\Actions')
    ->not->toUse(Request::class);

arch('repositories never depend on the HTTP request')
    ->expect('Vaulter\Repositories')
    ->not->toUse(Request::class);

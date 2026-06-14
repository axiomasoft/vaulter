<?php

declare(strict_types=1);
use Illuminate\Http\Request;

arch('all package code declares strict types')
    ->expect('DocVault')
    ->toUseStrictTypes();

arch('no debug helpers leak into the package')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('actions are final')
    ->expect('DocVault\Actions')
    ->toBeFinal();

arch('value objects are readonly')
    ->expect('DocVault\ValueObjects')
    ->toBeReadonly();

arch('data transfer objects are readonly')
    ->expect('DocVault\Data')
    ->toBeReadonly();

arch('events are readonly')
    ->expect('DocVault\Events')
    ->toBeReadonly();

arch('contracts are interfaces')
    ->expect('DocVault\Contracts')
    ->toBeInterfaces();

arch('actions never depend on the HTTP request')
    ->expect('DocVault\Actions')
    ->not->toUse(Request::class);

arch('repositories never depend on the HTTP request')
    ->expect('DocVault\Repositories')
    ->not->toUse(Request::class);

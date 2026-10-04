<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Models\Drive\Node;
use Vaulter\Policies\NodePolicy;
use Vaulter\Providers\AccessRegistrar;

it('registers the configured permission driver and node policy', function (): void {
    $registrar = new AccessRegistrar;
    $registrar->register($this->app);
    $registrar->boot($this->app);

    expect(app(PermissionDriver::class))->toBeInstanceOf(PermissionDriver::class)
        ->and(Gate::getPolicyFor(Node::class))->toBeInstanceOf(NodePolicy::class);
});

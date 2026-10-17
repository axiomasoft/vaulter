<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Policies\NodePolicy;
use Vaulter\Drive\Providers\AccessRegistrar;

it('registers the configured permission driver and node policy', function (): void {
    $registrar = new AccessRegistrar;
    $registrar->register($this->app);
    $registrar->boot($this->app);

    expect(app(PermissionDriver::class))->toBeInstanceOf(PermissionDriver::class)
        ->and(Gate::getPolicyFor(Node::class))->toBeInstanceOf(NodePolicy::class);
});

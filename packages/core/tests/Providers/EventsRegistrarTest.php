<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Events\Sharing\NodeShareCreated;
use Vaulter\Providers\EventsRegistrar;

it('subscribes activity and outbox listeners for kernel events', function (): void {
    (new EventsRegistrar)->boot($this->app);

    expect(Event::hasListeners(NodeShareCreated::class))->toBeTrue();
});

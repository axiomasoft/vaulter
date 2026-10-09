<?php

declare(strict_types=1);

use Vaulter\Events\EventRecorder;
use Vaulter\Providers\EventsRegistrar;

it('binds one event recorder for the application', function (): void {
    (new EventsRegistrar)->register($this->app);

    expect($this->app->make(EventRecorder::class))->toBe($this->app->make(EventRecorder::class));
});

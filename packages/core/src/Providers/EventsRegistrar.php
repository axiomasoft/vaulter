<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Vaulter\Activity\RecordsActivity;
use Vaulter\Support\Config;
use Vaulter\Webhooks\RecordsOutbox;

final class EventsRegistrar
{
    public function boot(Application $app): void
    {
        $this->registerActivitySubscriber();
        $this->registerOutboxSubscriber();
    }

    private function registerActivitySubscriber(): void
    {
        if (!Config::featureEnabled('activity')) {
            return;
        }

        Event::subscribe(RecordsActivity::class);
    }

    private function registerOutboxSubscriber(): void
    {
        if (!Config::featureEnabled('reliability')) {
            return;
        }

        Event::subscribe(RecordsOutbox::class);
    }
}

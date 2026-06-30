<?php

declare(strict_types=1);

namespace Vaulter\Filament\Tests\Fixtures;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Factory as ViewFactory;

/**
 * Test-only harness aid for re-enabling full Livewire page-render assertions.
 *
 * Livewire's test render makes a real Kernel request with middleware DISABLED, so
 * neither StartSession nor ShareErrorsFromSession runs and the shared 'errors' bag
 * is never set — and a nested @livewire render hook then blows up reading it. This
 * provider shares a valid ViewErrorBag (with an empty default MessageBag) onto every
 * resolved view factory. It is NOT registered by default (the resource behaviour is
 * covered render-free); register it in TestCase::getPackageProviders() when wiring up
 * live page-render tests. Production hosts run the real middleware and never need it.
 */
final class ShareErrorBagProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->shareNow();

        /** @var ViewFactory $view */
        $view = $this->app->make('view');

        $view->composer('*', function (): void {
            $this->shareNow();
        });
    }

    private function shareNow(): void
    {
        /** @var ViewFactory $view */
        $view = $this->app->make('view');

        $shared = $view->getShared()['errors'] ?? null;

        if ($shared instanceof ViewErrorBag && $shared->hasBag('default')) {
            return;
        }

        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag);

        $view->share('errors', $errors);
    }
}

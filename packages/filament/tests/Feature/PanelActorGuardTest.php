<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Auth\GenericUser;
use Vaulter\Filament\Support\PanelActor;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;

/*
 * F-P1.10-02 (Minor) — PanelActor must resolve the acting admin through the
 * ACTIVE PANEL's own auth guard, not the application's global default guard.
 * Before the fix PanelActor called `Auth::user()` (the default guard) directly;
 * on a panel configured with a non-default guard, the AccessResolver would then
 * evaluate a DIFFERENT identity than the one actually signed into the panel.
 * Red-before (D7): with the pre-fix `Auth::user()` call, PanelActor::id() below
 * would resolve the DEFAULT-guard user (or null), never the panel-guard user.
 */
it('resolves the acting admin through the panel guard, not the global default guard, F-P1.10-02', function (): void {
    config()->set('auth.guards.panel_guard', ['driver' => 'session', 'provider' => 'users']);

    $defaultUser = DriveBuilder::user(1);
    $panelUser = DriveBuilder::user(2);

    // Two DIFFERENT identities signed in on two DIFFERENT guards simultaneously.
    $this->actingAs($defaultUser); // the application's global default guard
    $this->actingAs($panelUser, 'panel_guard'); // the panel's OWN guard

    // Point the registered panel at the non-default guard (mutates the SAME Panel
    // instance FilamentManager::getDefaultPanel() resolves, via the shared
    // ProfileRegistry — see FilamentManager::getPanel()/getDefaultPanel()).
    Filament::getPanel('admin')->authGuard('panel_guard');

    expect(PanelActor::id())->toBe($panelUser->getKey())
        ->and(PanelActor::id())->not->toBe($defaultUser->getKey());

    expect(PanelActor::current()?->getAuthIdentifier())->toBe($panelUser->getKey());
});

it('resolves the same identity as the default guard when the panel uses no explicit guard (control, F-P1.10-02)', function (): void {
    $defaultUser = DriveBuilder::user(1);

    $this->actingAs($defaultUser);

    expect(PanelActor::id())->toBe($defaultUser->getKey());
});

it('rejects an authenticated identity that cannot be authorized', function (): void {
    auth()->guard()->setUser(new GenericUser(['id' => 99]));

    expect(PanelActor::current())->toBeNull()
        ->and(PanelActor::id())->toBeNull();
});

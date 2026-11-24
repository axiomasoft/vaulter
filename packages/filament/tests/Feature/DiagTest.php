<?php

declare(strict_types=1);

use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;

/*
 * Live page-render smoke test — kept SKIPPED, with the investigation resolved.
 *
 * A full render of the Filament 5 NodeResource list page dies inside Livewire's
 * SupportValidation::render(): it builds `(new ViewErrorBag)->put('default', $component->
 * getErrorBag())`, and the component's getErrorBag() returns null under Testbench, so
 * ViewErrorBag::put() type-errors on the null bag.
 *
 * The original hypothesis was "the middleware-less Livewire::test() request never runs
 * ShareErrorsFromSession". That is DISPROVEN here: driving the page over a REAL HTTP
 * request through the panel middleware stack (this test's `$this->get(...)`) reproduces
 * the identical null-bag error. So the fault is a Livewire-internal error-bag lifecycle
 * snag in the Filament 5 + Livewire + Testbench combination, not our harness — it is not
 * fixable from this package without patching Livewire. The load-bearing behaviour (action
 * wiring, authorization, token secrecy, config, page/nav declarations) is covered render-
 * free across the per-resource tests. Re-enable once an upstream Livewire fix lands.
 */
it('renders the node list page', function (): void {
    $this->actingAs(DriveBuilder::user());

    $this->get(NodeResource::getUrl('index'))->assertOk();
});

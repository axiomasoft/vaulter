<?php

declare(strict_types=1);

// P2.2 C7/C8 repro — VaulterFilamentServiceProvider's own publish tag, run via
// packages/filament/vendor/bin/pest (root vendor/ does NOT autoload the filament
// package — the root composer.json only requires core/media/drive/documents).
// Invoke as:
//   /usr/bin/php8.4 packages/filament/vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/P2.2/PublishTagsFilamentTest.php

use Illuminate\Support\ServiceProvider;
use Vaulter\Tests\P22Repro\PublishTagsFilamentBase;

require __DIR__.'/PublishTagsFilamentBase.php';

uses(PublishTagsFilamentBase::class);

it('lists the filament provider publish tag (core + filament only)', function () {
    $groups = ServiceProvider::publishableGroups();

    dump('filament publishableGroups() = '.json_encode($groups, JSON_PRETTY_PRINT));

    expect($groups)->toContain('vaulter-filament-config');
});

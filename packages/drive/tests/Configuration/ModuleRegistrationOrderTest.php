<?php

declare(strict_types=1);

use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\Configuration\DedupScope;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Tests\Concerns\FreshApplication;

// The module kernel compiles after every provider registered: a package provider listed before the core
// one only offers its manifest, and the module config is merged and normalized once the graph is sealed.

it('retains module defaults and applies host settings when modules register before core', function (): void {
    $app = FreshApplication::boot(
        ['vaulter-drive' => ['uploads' => ['max_file_size' => 2048]], 'vaulter-media' => ['dedup' => ['scope' => 'drive']]],
        before: [VaulterDriveServiceProvider::class, VaulterMediaServiceProvider::class],
    );

    expect($app->make(DriveConfig::class)->maxFileSize())->toBe(2048)
        ->and($app->make(MediaConfig::class)->dedupScope())->toBe(DedupScope::Drive)
        ->and($app['config']->get('vaulter-drive.models.upload_part'))->not->toBeNull();
});

<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Contracts\Drive\DriveHandleFactory as DriveHandleFactoryContract;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\DriveHandleFactory;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Providers\BindingsRegistrar;
use Vaulter\Drive\Uploads\UploadSessionManager;

it('merges upload model defaults without replacing host model overrides', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $config = new Repository(['vaulter' => ['models' => [
        'upload_session' => 'Host\\UploadSession',
        'node' => 'Host\\Node',
    ]]]);
    $app->instance('config', $config);

    (new BindingsRegistrar($app))->register();

    expect($config->get('vaulter.models.upload_session'))->toBe('Host\\UploadSession')
        ->and($config->get('vaulter.models.upload_part'))->toBe(UploadPart::class)
        ->and($config->get('vaulter.models.node'))->toBe('Host\\Node');
});

it('registers the factory and preserves singleton service lifetimes in a fresh container', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $config = new Repository;
    $app->instance('config', $config);
    $app->instance(VaulterDatabase::class, $this->app->make(VaulterDatabase::class));
    $app->instance(StorageDriver::class, $this->app->make(StorageDriver::class));

    (new BindingsRegistrar($app))->register();

    $factory = $app->make(DriveHandleFactoryContract::class);
    $provisioner = $app->make(DriveProvisioner::class);
    $uploads = $app->make(UploadSessionManager::class);

    expect($factory)->toBeInstanceOf(DriveHandleFactory::class)
        ->and($app->make(DriveHandleFactoryContract::class))->not->toBe($factory)
        ->and($provisioner)->toBeInstanceOf(DriveProvisioner::class)
        ->and($app->make(DriveProvisioner::class))->toBe($provisioner)
        ->and($uploads)->toBeInstanceOf(UploadSessionManager::class)
        ->and($app->make(UploadSessionManager::class))->toBe($uploads)
        ->and($config->get('vaulter.models.upload_session'))->toBe(UploadSession::class)
        ->and($config->get('vaulter.models.upload_part'))->toBe(UploadPart::class);
});

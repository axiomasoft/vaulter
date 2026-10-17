<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Contracts\DriveHandleFactory as DriveHandleFactoryContract;
use Vaulter\Drive\DriveModule;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Providers\BindingsRegistrar;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Events\EventRecorder;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Modules\ModuleApplier;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;

/** What the module kernel does with the drive module's config declaration. */
function applyDriveConfig(Application $app): void
{
    $builder = new ModuleBuilder(new ModuleId('drive'));
    (new DriveModule)->register($builder);
    $applier = new ModuleApplier($app);
    foreach ($builder->configs() as $root => $file) {
        $applier->config($root, $file);
    }
    $applier->normalizeConfig();
}

it('fills upload model defaults during normalization without replacing host overrides', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $config = new Repository([
        'vaulter-drive' => ['models' => ['upload_session' => 'Host\\UploadSession', 'node' => 'Host\\Node']],
    ]);
    $app->instance('config', $config);
    $app->singleton(ConfigNormalizer::class);

    // The module kernel merges the drive module's config and runs the shared normalizer once the graph is sealed.
    applyDriveConfig($app);

    expect($config->get('vaulter-drive.models.upload_session'))->toBe('Host\\UploadSession')
        ->and($config->get('vaulter-drive.models.upload_part'))->toBe(UploadPart::class)
        ->and($config->get('vaulter-drive.models.node'))->toBe('Host\\Node');
});

it('preserves singleton service lifetimes in a fresh container', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $config = new Repository;
    $app->instance('config', $config);
    $app->instance(VaulterDatabase::class, $this->app->make(VaulterDatabase::class));
    $app->instance(EventRecorder::class, $this->app->make(EventRecorder::class));
    $app->instance(StorageDriver::class, $this->app->make(StorageDriver::class));
    $app->singleton(ConfigNormalizer::class);

    applyDriveConfig($app);
    (new BindingsRegistrar)->register($app);

    $provisioner = $app->make(DriveProvisioner::class);
    $uploads = $app->make(UploadSessionManager::class);

    // The drive handle factory is the drive module's capability (DriveModule::provides), not the registrar's binding.
    expect($app->bound(DriveHandleFactoryContract::class))->toBeFalse()
        ->and($provisioner)->toBeInstanceOf(DriveProvisioner::class)
        ->and($app->make(DriveProvisioner::class))->toBe($provisioner)
        ->and($uploads)->toBeInstanceOf(UploadSessionManager::class)
        ->and($app->make(UploadSessionManager::class))->toBe($uploads)
        ->and($config->get('vaulter-drive.models.upload_session'))->toBe(UploadSession::class)
        ->and($config->get('vaulter-drive.models.upload_part'))->toBe(UploadPart::class);
});

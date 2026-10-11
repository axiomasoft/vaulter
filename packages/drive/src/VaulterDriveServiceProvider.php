<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Closure;
use Illuminate\Contracts\Container\Container;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Doctor\DoctorRegistry;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Doctor\DrivesDeletingCheck;
use Vaulter\Drive\Doctor\DrivesUsageCheck;
use Vaulter\Drive\Doctor\IntlCheck;
use Vaulter\Drive\Doctor\ScanObligationsCheck;
use Vaulter\Drive\Doctor\ScanPolicyCheck;
use Vaulter\Drive\Doctor\ScanScannerCheck;
use Vaulter\Drive\Doctor\ScanScopeCheck;
use Vaulter\Drive\Doctor\UploadsTmpCheck;
use Vaulter\Drive\Http\Problems\DriveProblemCodes;
use Vaulter\Drive\Providers\BindingsRegistrar;
use Vaulter\Drive\Providers\HttpRegistrar;
use Vaulter\Drive\Providers\SchedulingRegistrar;
use Vaulter\Http\Problems\ProblemCatalog;

final class VaulterDriveServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('vaulter-drive')->hasConfigFile()->hasTranslations();

        (new HttpRegistrar)->configure($package);
    }

    public function packageRegistered(): void
    {
        (new BindingsRegistrar($this->app))->register();
        $this->app->singleton(DriveProblemCodes::class);
        $this->app->tag(DriveProblemCodes::class, ProblemCatalog::TAG);
        $this->app->bind('vaulter.config_validator.drive', static fn (Container $app): Closure => static function () use ($app): void {
            $config = $app->make(DriveConfig::class);
            $config->model('upload_session');
            $config->model('upload_part');
            $config->validateScanWork();
        });
        $this->app->tag('vaulter.config_validator.drive', ConfigValidCheck::TAG);
        $this->app->tag([
            UploadsTmpCheck::class, IntlCheck::class,
            ScanScannerCheck::class, ScanPolicyCheck::class, ScanObligationsCheck::class, ScanScopeCheck::class,
            DrivesDeletingCheck::class, DrivesUsageCheck::class,
        ], DoctorRegistry::TAG);
    }

    public function packageBooted(): void
    {
        $config = $this->app->make(DriveConfig::class);
        $config->model('upload_session');
        $config->model('upload_part');
        (new SchedulingRegistrar)->boot($this->app);
    }
}

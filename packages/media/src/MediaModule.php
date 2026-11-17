<?php

declare(strict_types=1);

namespace Vaulter\Media;

use Override;
use Packman\Attributes\Api;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Configuration\MediaOptions;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\Doctor\BlobIntegrityCheck;
use Vaulter\Media\Doctor\BlobPinsCheck;
use Vaulter\Media\Errors\MediaProblemDefinitions;
use Vaulter\Media\Schema\MediaSchema;
use Vaulter\Media\Schema\MediaSchemaSets;

/**
 * The media extension plugin (`vaulter.media`): CAS store engine, blob pins,
 * storage driver and optional content scanner capabilities, error catalog, and diagnostics.
 */
#[Api(since: '1.0')]
final class MediaModule implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithDiagnostics, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesCapabilities;
    use ProvidesDiagnostics;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'vaulter.media',
            version: '1.0.0',
            requires: ['vaulter.core'],
            resourceRoot: dirname(__DIR__).'/resources',
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function configRoot(): string
    {
        return 'vaulter-media';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__).'/config/vaulter-media.php';
    }

    #[Override]
    public function optionsClass(): string
    {
        return MediaOptions::class;
    }

    #[Override]
    public function tablePrefix(): string
    {
        return 'v_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return ['blobs', 'blob_references'];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__).'/database/migrations';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [MediaSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaComponents(): array
    {
        return [MediaSchema::class];
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            ContentScanner::class => Cardinality::OptionalSingle->value,
            StorageDriver::class => Cardinality::RequiredSingle->value,
        ];
    }

    /** @return array<class-string, class-string|array{config: string, default?: class-string|null}> */
    #[Override]
    public function providedCapabilities(): array
    {
        return [
            ContentScanner::class => ['config' => 'vaulter-drive.scan.scanner'],
            StorageDriver::class => ['config' => 'vaulter-media.storage_driver', 'default' => FlysystemStorageDriver::class],
        ];
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    #[Override]
    public function slotContributions(): array
    {
        return [
            ['slot' => DoctorCheck::class, 'id' => 'media.blobs.pins', 'implementation' => BlobPinsCheck::class, 'key' => 'media.blobs.pins'],
            ['slot' => DoctorCheck::class, 'id' => 'media.blobs.integrity', 'implementation' => BlobIntegrityCheck::class, 'key' => 'media.blobs.integrity'],
        ];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [
            BlobPinsCheck::class,
            BlobIntegrityCheck::class,
        ];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [MediaProblemDefinitions::class];
    }
}

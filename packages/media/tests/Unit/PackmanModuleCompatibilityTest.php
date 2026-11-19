<?php

declare(strict_types=1);

namespace Vaulter\Media\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use PHPUnit\Framework\TestCase;
use stdClass;
use Vaulter\CoreModule;
use Vaulter\Media\Configuration\MediaOptions;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\Doctor\BlobIntegrityCheck;
use Vaulter\Media\Doctor\BlobPinsCheck;
use Vaulter\Media\MediaModule;
use Vaulter\Media\Providers\BindingsRegistrar;
use Vaulter\Media\Providers\SchedulingRegistrar;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\ModuleCompiler;

final class PackmanModuleCompatibilityTest extends TestCase
{
    public function test_media_compiles_against_the_core_packman_doctor_slot(): void
    {
        $graph = (new ModuleCompiler)->compileOffered([CoreModule::class, MediaModule::class], []);

        self::assertInstanceOf(CompiledModules::class, $graph);
        self::assertTrue($graph->isActive('media'));
        self::assertContains(BindingsRegistrar::class, $graph->bindings);
        self::assertTrue(is_a(BlobPinsCheck::class, DoctorCheck::class, true));
        self::assertTrue(is_a(BlobIntegrityCheck::class, DoctorCheck::class, true));
        self::assertArrayHasKey(DoctorCheck::class, $graph->contributions);
    }

    public function test_core_plugin_has_a_canonical_existing_resource_root(): void
    {
        $root = (new CoreModule)->manifest()->resourceRoot;

        self::assertNotNull($root);
        self::assertSame(realpath($root), $root);
        self::assertDirectoryExists($root);
        self::assertStringNotContainsString('/../', $root);
    }

    public function test_media_defaults_resolve_current_implementation_contracts(): void
    {
        $config = MediaOptions::fromArray([]);

        self::assertTrue(is_a($config->contentStore(), ContentStore::class, true));
        self::assertTrue(is_a($config->storageDriver(), StorageDriver::class, true));
        self::assertTrue(is_a($config->blobStore(), BlobStore::class, true));
    }

    public function test_media_rejects_an_unrelated_configured_implementation(): void
    {
        $this->expectException(InvalidConfiguration::class);

        MediaOptions::fromArray(['blob_store' => stdClass::class])->blobStore();
    }

    public function test_media_schedule_rejects_malformed_configuration(): void
    {
        $this->expectException(InvalidConfiguration::class);

        SchedulingRegistrar::frequencies(new Repository(['vaulter-media' => ['schedule' => 'hourly']]));
    }

    public function test_core_compiles_once_through_the_public_packman_registry(): void
    {
        $previous = Container::getInstance();
        $app = new Application(dirname(__DIR__, 4));
        $app->instance('config', new Repository);

        try {
            (new ExtensionRegistrar)->register($app);
            Container::setInstance($previous);
            ExtensionRegistrar::builtInFor($app, CoreModule::class);
            $registry = $app->make(PluginInspector::class);

            self::assertCount(1, $registry->describe());
            self::assertCount(6, $registry->slots());
            self::assertCount(15, $registry->slotContributions()[DoctorCheck::class]);
        } finally {
            Container::setInstance($previous);
        }
    }
}

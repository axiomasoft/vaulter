<?php

declare(strict_types=1);

namespace Vaulter\Documents\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Documents\Console\MakeDocumentTypeCommand;
use Vaulter\Documents\Console\MakeExporterCommand;
use Vaulter\Documents\DocumentsModule;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Http\Problems\DocumentsProblemCodes;
use Vaulter\Documents\Operations\DocumentsOperationPolicies;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\VaulterExtensionFacets;

/**
 * Registers Packman plugin/extension facets and Vaulter module runtime metadata for {@see DocumentsModule}.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class DocumentsPackageRegistrar implements PackageRegistrar
{
    /** @var list<string> */
    private const array REPLACEMENT_MAPS = [
        'vaulter-documents.tiptap.extra_attributes',
        'vaulter-documents.tiptap.url_attributes',
    ];

    #[Override]
    public function register(Application $app): void
    {
        /** @var array<string, mixed> $preservedMaps */
        $preservedMaps = [];
        $config = $app->bound('config') ? $app->make('config') : null;
        if ($config instanceof Repository) {
            foreach (self::REPLACEMENT_MAPS as $key) {
                if ($config->has($key)) {
                    $preservedMaps[$key] = $config->get($key);
                }
            }
        }

        VaulterExtensionFacets::register($app, DocumentsModule::class, dirname(__DIR__, 2).'/composer.json');

        if ($config instanceof Repository) {
            foreach ($preservedMaps as $key => $value) {
                $config->set($key, $value);
            }
        }

        $root = dirname(__DIR__, 2);

        ModuleCatalog::of($app)->offerPlugin(new PluginModuleSpec(
            plugin: DocumentsModule::class,
            activation: Activation::Auto,
            protocolVersion: '1.0',
            routes: [$root.'/routes/documents.php'],
            commands: [
                MakeDocumentTypeCommand::class,
                MakeExporterCommand::class,
            ],
            bindings: [BindingsRegistrar::class],
            events: [DocumentEventType::class],
            problems: [DocumentsProblemCodes::class],
            operations: [DocumentsOperationPolicies::class],
        ));
    }

    #[Override]
    public function boot(Application $app): void {}
}

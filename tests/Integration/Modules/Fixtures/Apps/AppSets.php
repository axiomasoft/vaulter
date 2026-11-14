<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\Apps;

use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;
use Vaulter\Webhooks\VaulterWebhooksServiceProvider;

/**
 * The application sets of the install matrix: core; +media; +drive; +documents; +collaboration; +webhooks; +azguard; +filament —
 * each downward-closed by the package graph (D6): collaboration needs the documents, webhooks, azguard and filament need the drive, the
 * drive needs the media.
 *
 * The azguard and filament packages are not a dev dependency of the core (the azguard bridge pulls the external AzGuard
 * package, the Filament admin the Filament framework): their manifests and offering providers need neither, so the matrix
 * makes the two source trees loadable by its own PSR-4 map unless Composer already does.
 */
final class AppSets
{
    /** The module ids of every package of the monorepo that offers one. */
    public const array ALL_MODULES = ['core', 'media', 'drive', 'documents', 'collaboration', 'webhooks', 'azguard', 'filament'];

    /** Modules that mount routes of their own. */
    public const array ROUTE_MODULES = ['drive', 'documents', 'collaboration', 'webhooks'];

    private const array SIBLING_PACKAGES = [
        'Vaulter\\AzGuard\\' => 'azguard',
        'Vaulter\\Filament\\' => 'filament',
    ];

    private static bool $registered = false;

    /** @return array<string, AppSet> set id => the set, smallest first */
    public static function all(): array
    {
        self::makeSiblingPackagesLoadable();

        $core = [VaulterServiceProvider::class];
        $media = [...$core, VaulterMediaServiceProvider::class];
        $drive = [...$media, VaulterDriveServiceProvider::class];
        $documents = [...$drive, VaulterDocumentsServiceProvider::class];

        $sets = [
            new AppSet('core', $core, ['core'], 'core', null),
            new AppSet('core+media', $media, ['core', 'media'], 'media', 'core'),
            new AppSet('core+media+drive', $drive, ['core', 'media', 'drive'], 'drive', 'core+media', requiredOff: 'media'),
            new AppSet('+documents', $documents, ['core', 'media', 'drive', 'documents'], 'documents', 'core+media+drive', requiredOff: 'drive'),
            new AppSet('+collaboration', [...$documents, VaulterCollaborationServiceProvider::class], ['core', 'media', 'drive', 'documents', 'collaboration'], 'collaboration', '+documents', ['vaulter.modules.collaboration' => true], 'documents'),
            new AppSet('+webhooks', [...$drive, VaulterWebhooksServiceProvider::class], ['core', 'media', 'drive', 'webhooks'], 'webhooks', 'core+media+drive', ['vaulter.modules.webhooks' => true], 'drive'),
            new AppSet('+azguard', [...$drive, 'Vaulter\\AzGuard\\VaulterAzGuardServiceProvider'], ['core', 'media', 'drive', 'azguard'], 'azguard', 'core+media+drive', requiredOff: 'drive'),
            new AppSet('+filament', [...$drive, 'Vaulter\\Filament\\VaulterFilamentServiceProvider'], ['core', 'media', 'drive', 'filament'], 'filament', 'core+media+drive', requiredOff: 'drive'),
        ];

        return array_combine(array_map(static fn (AppSet $set): string => $set->id, $sets), $sets);
    }

    /** Every package at once: the schema of the whole installation, and the one the snapshots of the schema oracle describe. */
    public static function full(): AppSet
    {
        $all = self::all();

        return new AppSet(
            'full',
            array_values(array_unique(array_merge(...array_values(array_map(static fn (AppSet $set): array => $set->providers, $all))))),
            self::ALL_MODULES,
            'filament',
            null,
            ['vaulter.modules.webhooks' => true, 'vaulter.modules.collaboration' => true],
        );
    }

    public static function get(string $id): AppSet
    {
        return self::all()[$id];
    }

    /** @return array<string, array{AppSet}> Pest dataset: every set */
    public static function dataset(): array
    {
        return array_map(static fn (AppSet $set): array => [$set], self::all());
    }

    /** @return array<string, array{AppSet}> Pest dataset: the sets that add a module over a parent set */
    public static function childrenDataset(): array
    {
        return array_map(static fn (AppSet $set): array => [$set], array_filter(self::all(), static fn (AppSet $set): bool => $set->parent !== null));
    }

    /** @return array<string, array{AppSet}> Pest dataset: the sets whose top needs a module besides the core */
    public static function dependentDataset(): array
    {
        return array_map(static fn (AppSet $set): array => [$set], array_filter(self::all(), static fn (AppSet $set): bool => $set->requiredOff !== null));
    }

    /** @return array<string, array{AppSet}> Pest dataset: the sets whose top mounts routes */
    public static function routedDataset(): array
    {
        return array_map(static fn (AppSet $set): array => [$set], array_filter(self::all(), static fn (AppSet $set): bool => in_array($set->top, self::ROUTE_MODULES, true)));
    }

    private static function makeSiblingPackagesLoadable(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // Appended: a Composer map (a require-dev of the core, once one exists) wins.
        spl_autoload_register(static function (string $class): void {
            foreach (self::SIBLING_PACKAGES as $prefix => $package) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }
                $file = dirname(__DIR__, 5).'/packages/'.$package.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
    }
}

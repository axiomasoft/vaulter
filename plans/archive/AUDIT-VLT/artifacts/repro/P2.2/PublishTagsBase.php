<?php

declare(strict_types=1);

namespace Vaulter\Tests\P22Repro;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * P2.2 C7/C8/C9 repro — the FOUR providers autoloadable from the root vendor/ (the
 * root composer.json only requires vaulter-core/media/drive/documents — filament and
 * permissions-azgard are separate packages with their OWN vendor/ trees, neither of
 * which requires the other three siblings together; PublishTagsFilamentTest.php /
 * source-only verdict for azgard cover those two separately — see the finding's
 * scope note).
 *
 * This Laravel/Testbench version (13.17 / Testbench 10-11) has NO `vendor:publish
 * --list` flag (verified: only --existing/--force/--all/--provider/--tag exist;
 * running with no selector under -n throws
 * Laravel\Prompts\NonInteractiveValidationException, since the picker is an
 * interactive multiselect with no default — see the finding's "Не проверено"
 * section for the literal transcript). The FACTUAL runtime source backing that
 * picker — and what `vendor:publish --tag=X` / `--provider=X` resolve against — is
 * Illuminate\Support\ServiceProvider::$publishGroups / $publishes, exposed via the
 * public statics publishableGroups()/publishableProviders()/pathsToPublish(). This
 * repro reads those directly after a real boot (not by grepping ->name() calls in
 * source, per the item's Code Guidance ban).
 */
abstract class PublishTagsBase extends OrchestraTestCase
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterDocumentsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}

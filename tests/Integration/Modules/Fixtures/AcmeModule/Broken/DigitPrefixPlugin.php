<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\Broken;

use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Valid Packman dotted ID starting with a digit, which fails Vaulter's ModuleId pattern and must be caught as a graph reason.
 */
final class DigitPrefixPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: '9bad.plugin', version: '1.0.0', requires: ['vaulter.core']);
    }

    public function register(PluginRegistrar $registrar): void {}
}

<?php

declare(strict_types=1);

namespace Vaulter\Testing\Concerns;

use Illuminate\Foundation\Application;
use Packman\Attributes\Internal;

/**
 * Boots the package with opt-in modules switched on (`vaulter.modules.<id> = true`) before the application is built.
 * A test case names them in {@see $enabledModules}; a test file, which cannot set a property of its generated class,
 * uses a trait of the package that owns the module and overrides {@see modulesToEnable()}.
 */
#[Internal]
trait EnablesModules
{
    /** @var list<string> the module ids to switch on */
    protected array $enabledModules = [];

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach ($this->modulesToEnable() as $id) {
            $app['config']->set('vaulter.modules.'.$id, true);
        }
    }

    /** @return list<string> */
    protected function modulesToEnable(): array
    {
        return $this->enabledModules;
    }
}

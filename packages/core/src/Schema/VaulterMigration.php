<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Container\Container;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Laravel\PackmanMigration;
use Vaulter\Configuration\DatabaseOptions;

/**
 * Base migration for Vaulter schema sets extending {@see PackmanMigration}.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
abstract class VaulterMigration extends PackmanMigration
{
    #[Override]
    public function getConnection(): ?string
    {
        $container = Container::getInstance();

        if ($container->bound(DatabaseOptions::class)) {
            return $container->make(DatabaseOptions::class)->connection;
        }

        return null;
    }

    #[Override]
    protected function table(string $logical): string
    {
        return Container::getInstance()->make(DatabaseOptions::class)->table($logical);
    }
}

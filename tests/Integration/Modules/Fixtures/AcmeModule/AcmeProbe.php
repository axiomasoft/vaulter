<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule;

/**
 * What the AcmeModule did, seen from outside the container: every runtime class of the module reports its
 * construction here, so a test proves "never resolved" instead of "no route listed".
 */
final class AcmeProbe
{
    /** @var list<class-string> classes constructed, in order */
    public static array $resolved = [];

    /** @var list<string> */
    public static array $log = [];

    public static function reset(): void
    {
        self::$resolved = [];
        self::$log = [];
    }

    /** @param class-string $class */
    public static function resolved(string $class): void
    {
        self::$resolved[] = $class;
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Tests\Outbox\Fixtures;

/** A consumer whose id differs from the id of its contribution. */
final class MisnamedConsumer extends ScriptedConsumer
{
    public const string ID = 'test.other-name';
}

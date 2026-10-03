<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures;

use Vaulter\Tests\TestCase;

/** Host-key DDL can run independently of the existing MySQL tree-schema defect. */
class HostKeyMysqlTestCase extends TestCase
{
    #[\Override]
    protected function defineDatabaseMigrations(): void {}
}

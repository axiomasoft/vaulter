<?php

declare(strict_types=1);

use Vaulter\Integration\Tests\Schema\DriveSchemaAssertions;

it('применяет базовые Drive-миграции под uuid7-стратегией', function (): void {
    DriveSchemaAssertions::assertAllTablesAndColumns();
});

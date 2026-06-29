<?php

declare(strict_types=1);

use Vaulter\Tests\Schema\DriveSchemaAssertions;

it('применяет базовые Drive-миграции под bigint-стратегией', function (): void {
    DriveSchemaAssertions::assertAllTablesAndColumns();
});

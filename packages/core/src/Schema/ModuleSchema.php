<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Schema\ValueObjects\KeyColumnExpectation;

/**
 * The tables one module owns: their entity keys in dependency order and the DDL that creates and drops each of them. A
 * module's migrations call `create()`/`drop()` of its own schema class; nobody else knows its tables.
 */
#[Spi(SpiKind::Implement)]
interface ModuleSchema
{
    /** @return list<string> the entity keys of the tables of the module, a table after the ones it references */
    public function entities(): array;

    /** @return list<KeyColumnExpectation> the key columns of the module's tables and the kind each must have (`vaulter:doctor` checks them against ids.strategy and ids.host_keys) */
    public function keyColumns(): array;

    public function create(string $entity): void;

    public function drop(string $entity): void;
}

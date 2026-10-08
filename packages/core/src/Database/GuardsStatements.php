<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Illuminate\Support\Collection;
use Vaulter\Exceptions\Database\WriteOutsideOperationException;

/**
 * Makes every statement of a query builder that changes rows check the
 * {@see WriteScope} first, so mass update/delete/upsert/increment cannot bypass what
 * the model events would have caught (they fire for neither).
 *
 * @internal
 */
trait GuardsStatements
{
    private ?WriteScope $writeScope = null;

    /** Bind the scope the statements are checked against; an unbound builder is not guarded. */
    public function guardedBy(WriteScope $scope): static
    {
        $this->writeScope = $scope;

        return $this;
    }

    /** A fresh statement builder must keep the scope of the model it came from. */
    #[\Override]
    public function newQuery(): static
    {
        /** @var static $query */
        $query = parent::newQuery();
        $query->writeScope = $this->writeScope;

        return $query;
    }

    /**
     * No Override attribute: older supported Laravel versions lack this statement.
     * On versions that provide it, it writes directly instead of calling insert().
     *
     * @param  array<mixed>                                            $values
     * @param  non-empty-array<non-empty-string>                       $returning
     * @param  non-empty-string|non-empty-array<non-empty-string>|null $uniqueBy
     * @return Collection<int, object>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null): Collection
    {
        $this->guard();

        return parent::insertOrIgnoreReturning($values, $returning, $uniqueBy);
    }

    /** @param array<mixed> $values */
    #[\Override]
    public function insert(array $values): bool
    {
        $this->guard();

        return parent::insert($values);
    }

    /** @param array<mixed> $values */
    #[\Override]
    public function insertOrIgnore(array $values): int
    {
        $this->guard();

        return parent::insertOrIgnore($values);
    }

    /** @param array<string, mixed> $values */
    #[\Override]
    public function insertGetId(array $values, $sequence = null): int|string
    {
        $this->guard();

        return parent::insertGetId($values, $sequence);
    }

    /** @param list<string> $columns */
    #[\Override]
    public function insertUsing(array $columns, $query): int
    {
        $this->guard();

        return parent::insertUsing($columns, $query);
    }

    /** @param list<string> $columns */
    #[\Override]
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        $this->guard();

        return parent::insertOrIgnoreUsing($columns, $query);
    }

    /** @param array<string, mixed> $values */
    #[\Override]
    public function update(array $values): int
    {
        $this->guard();

        return parent::update($values);
    }

    /** @param array<string, mixed> $values */
    #[\Override]
    public function updateFrom(array $values): int
    {
        $this->guard();

        return parent::updateFrom($values);
    }

    /**
     * @param array<mixed>                                            $values
     * @param non-empty-array<int, non-empty-string>|non-empty-string $uniqueBy
     * @param array<mixed>|null                                       $update
     */
    #[\Override]
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null): int
    {
        $this->guard();

        return parent::upsert($values, $uniqueBy, $update);
    }

    /** @param array<string, mixed> $extra */
    #[\Override]
    public function increment($column, $amount = 1, array $extra = []): int
    {
        $this->guard();

        return parent::increment($column, $amount, $extra);
    }

    /** @param array<string, mixed> $extra */
    #[\Override]
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $this->guard();

        return parent::decrement($column, $amount, $extra);
    }

    #[\Override]
    public function delete($id = null): int
    {
        $this->guard();

        return parent::delete($id);
    }

    #[\Override]
    public function truncate(): void
    {
        $this->guard();

        parent::truncate();
    }

    /** @throws WriteOutsideOperationException */
    private function guard(): void
    {
        $this->writeScope?->assertOpen('A row of table '.(is_string($this->from) ? $this->from : 'of a query expression'));
    }
}

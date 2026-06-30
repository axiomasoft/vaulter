<?php

declare(strict_types=1);

namespace Vaulter\Cas;

use Illuminate\Support\Facades\DB;

/**
 * The per-(scope, sha256) liveness lock (H.3 safety invariant). EVERY transaction
 * that changes the liveness of a content address — upload-commit, GC-mark,
 * GC-sweep — takes this lock as its FIRST statement and makes every liveness
 * decision from rows read AFTER the lock. That is what makes online GC safe
 * against a concurrent re-commit (a commit that wins the lock resurrects a
 * tombstoned blob before the sweep can see it; a sweep that wins the lock
 * re-checks edge existence before deleting).
 *
 * Per driver:
 *  - pgsql : pg_advisory_xact_lock(hashtext(scope||sha256)) — a real mutex held
 *            for the whole transaction, the GC-vs-upload backbone.
 *  - mysql : there is no transaction-advisory lock that survives like PG's, so we
 *            rely on SELECT … FOR UPDATE on the blob row inside the caller's
 *            transaction; this helper is a no-op and BlobStore locks the row.
 *  - sqlite: single-writer — the transaction itself serialises; no-op here.
 *
 * The UNIQUE (scope, sha256) constraint is the portable backbone that holds on
 * ALL three drivers even when this advisory lock is a no-op.
 */
final class BlobLock
{
    /**
     * Take the per-(scope, sha256) advisory lock for the current transaction.
     * Must be called as the first statement inside DB::transaction().
     */
    public function acquire(string $scope, string $sha256): void
    {
        if (!$this->isPostgres()) {
            // MySQL relies on SELECT … FOR UPDATE (the caller); SQLite on the
            // single-writer transaction. Nothing to take here.
            return;
        }

        // hashtext() folds the variable-length key into the int4 advisory-lock
        // namespace; xact-scoped so it releases at COMMIT/ROLLBACK automatically.
        DB::statement(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            [$scope.'|'.$sha256],
        );
    }

    /**
     * Whether the active connection uses pessimistic row locks (FOR UPDATE) as the
     * lock mechanism — i.e. MySQL/MariaDB. PG uses the advisory lock above; SQLite
     * uses single-writer and ignores FOR UPDATE.
     */
    public function usesRowLock(): bool
    {
        return in_array($this->driver(), ['mysql', 'mariadb'], strict: true);
    }

    public function isPostgres(): bool
    {
        return $this->driver() === 'pgsql';
    }

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}

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

    /**
     * Whether a critical section that takes this lock but performs NO database WRITE
     * is still serialised against a concurrent transaction by THIS driver.
     *
     * The orphan prune is exactly such a section (it re-reads under the lock, then
     * deletes BYTES off disk — no row write). It is safe only when the lock alone
     * orders it against a concurrent BlobStore::commit of the same content:
     *
     *  - pgsql : pg_advisory_xact_lock() is a real mutex held for the whole tx — a
     *            commit of the same (scope,sha256) blocks on it ⇒ serialised.
     *  - mysql : findByStorageKey(lock: true) issues SELECT … FOR UPDATE, so the
     *            recheck contends with the concurrent INSERT ⇒ serialised.
     *  - sqlite: acquire() is a no-op AND FOR UPDATE is ignored, so a read-only
     *            critical section never engages SQLite's single-writer lock — a
     *            concurrent commit can INSERT the owning row between the recheck and
     *            the byte delete. NOT serialised; the prune must be refused (the GC
     *            is unaffected — it does a real markDeleting() write that forces the
     *            write lock).
     */
    public function serializesCriticalSection(): bool
    {
        return $this->isPostgres() || $this->usesRowLock();
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

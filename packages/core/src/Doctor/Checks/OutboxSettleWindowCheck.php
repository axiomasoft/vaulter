<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\OutboxConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

/**
 * The outbox settle window must outlast the longest Vaulter transaction: reconciliation trusts only events
 * older than the window, so a transaction the database lets run longer could commit a smaller seq that
 * reconciliation has already passed. The window is compared with the server's transaction limits — PostgreSQL
 * idle_in_transaction_session_timeout and statement_timeout, MySQL innodb_lock_wait_timeout. SQLite serialises
 * writers, so it has nothing to compare. The only I/O of the outbox settings happens here.
 */
#[Internal]
final readonly class OutboxSettleWindowCheck implements DoctorCheck
{
    public function __construct(
        private OutboxConfig $config,
        private VaulterDatabase $database,
    ) {}

    public function key(): string
    {
        return 'core.outbox.settle_window';
    }

    public function title(): string
    {
        return 'Outbox settle window';
    }

    public function run(DoctorContext $context): array
    {
        $window = $this->config->settleWindowSeconds();
        try {
            [$limits, $unbounded] = $this->limits();
        } catch (Throwable $exception) {
            return [DoctorFinding::warning(code: 'core.outbox.settle_window_unknown', message: 'The transaction limits of the database could not be read: '.$exception->getMessage())];
        }

        $longer = array_filter($limits, static fn (int $seconds): bool => $seconds > $window);
        if ($longer !== []) {
            return [DoctorFinding::warning(
                code: 'core.outbox.settle_window_short',
                message: sprintf(
                    'vaulter.outbox.settle_window_seconds (%d) is shorter than the database limit %s.',
                    $window,
                    implode(', ', array_map(static fn (string $name, int $seconds): string => $name.' = '.$seconds.'s', array_keys($longer), array_values($longer))),
                ),
                hint: 'raise vaulter.outbox.settle_window_seconds above the longest transaction the database allows',
                evidence: ['settle_window_seconds' => $window, 'limits' => json_encode($limits, JSON_THROW_ON_ERROR)],
            )];
        }

        if ($unbounded !== []) {
            return [DoctorFinding::warning(
                code: 'core.outbox.settle_window_unbounded',
                message: sprintf('The database does not limit %s: a transaction may outlive vaulter.outbox.settle_window_seconds (%d).', implode(', ', $unbounded), $window),
                hint: 'set idle_in_transaction_session_timeout and statement_timeout below vaulter.outbox.settle_window_seconds',
                evidence: ['settle_window_seconds' => $window, 'unbounded' => implode(', ', $unbounded)],
            )];
        }

        return [DoctorFinding::ok(code: 'core.outbox.settle_window_ok', message: 'The settle window outlasts the transaction limits of the database.', evidence: ['settle_window_seconds' => $window, 'limits' => json_encode($limits, JSON_THROW_ON_ERROR)])];
    }

    /**
     * The server's transaction-bounding settings that are set, in seconds, and the ones left unbounded (0 = no limit).
     *
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private function limits(): array
    {
        $connection = $this->database->connection();
        $limits = [];
        $unbounded = [];
        switch ($this->database->driver()) {
            case 'pgsql':
                foreach (['idle_in_transaction_session_timeout', 'statement_timeout'] as $setting) {
                    $row = (array) ($connection->selectOne('SELECT setting, unit FROM pg_settings WHERE name = ?', [$setting]) ?? []);
                    $value = is_numeric($row['setting'] ?? null) ? (int) $row['setting'] : 0;
                    if ($value > 0) {
                        $limits[$setting] = ($row['unit'] ?? 'ms') === 's' ? $value : max(intdiv($value, 1000), 1);
                    } else {
                        $unbounded[] = $setting;
                    }
                }
                break;
            case 'mysql':
            case 'mariadb':
                $row = (array) ($connection->selectOne('SELECT @@innodb_lock_wait_timeout AS value') ?? []);
                $value = is_numeric($row['value'] ?? null) ? (int) $row['value'] : 0;
                if ($value > 0) {
                    $limits['innodb_lock_wait_timeout'] = $value;
                }
                break;
        }

        return [$limits, $unbounded];
    }
}

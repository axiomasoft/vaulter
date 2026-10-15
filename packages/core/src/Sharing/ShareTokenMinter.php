<?php

declare(strict_types=1);

namespace Vaulter\Sharing;

use Closure;
use Illuminate\Database\QueryException;
use RuntimeException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\SharingConfig;
use Vaulter\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Support\ShareToken;

/**
 * Generates a share token and hands its hash and prefix to a writer, retrying on the
 * (astronomically unlikely) token_hash UNIQUE collision rather than ever returning
 * a link that does not match what was stored. Every attempt runs in its own savepoint,
 * so the retry works inside the caller's transaction on PostgreSQL too. The raw token
 * leaves only through the returned value.
 */
#[Internal]
final readonly class ShareTokenMinter
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(private SharingConfig $config, private ShareTokenGenerator $tokens, private VaulterDatabase $database) {}

    /**
     * @template T
     *
     * @param  Closure(string $tokenHash, string $tokenPrefix): T $persist
     * @return array{0: T, 1: string}                             the writer's result and the raw token
     */
    public function mint(Closure $persist): array
    {
        $prefixLength = $this->config->prefixLength();

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $token = $this->tokens->generate();

            try {
                // A savepoint: on PostgreSQL a unique violation aborts the enclosing transaction
                // unless it is rolled back to a savepoint taken before the failed statement.
                return [$this->database->transaction(static fn () => $persist(ShareToken::hash($token), ShareToken::prefix($token, $prefixLength))), $token];
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new RuntimeException('Vaulter could not mint a unique share token after '.self::MAX_ATTEMPTS.' attempts.');
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        // 23000 / 23505 are the SQLSTATE integrity-constraint-violation codes
        // (MySQL/SQLite use 23000, Postgres 23505 for unique).
        $state = $exception->errorInfo[0] ?? $exception->getCode();

        return in_array(is_scalar($state) ? (string) $state : '', ['23000', '23505'], strict: true);
    }
}

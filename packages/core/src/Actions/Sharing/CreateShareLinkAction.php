<?php

declare(strict_types=1);

namespace Vaulter\Actions\Sharing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Vaulter\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Data\Sharing\MintedShareLink;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\Sharing\NodeShareCreated;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Sharing\NodeShareStoreRepository;
use Vaulter\Support\Config;
use Vaulter\Support\ShareToken;

/**
 * Mint a bearer share-link on a node (§2.7 / M2c) — the SEPARATE public sharing
 * channel. The raw token is generated here, returned ONCE via the result and NEVER
 * persisted: only its sha256 hash (token_hash, UNIQUE) and a short token_prefix are
 * stored. Any password is hashed before it touches the DB. The write runs in a
 * transaction and emits NodeShareCreated.
 *
 * A link is never minted immortal by omission (P1.2 / F-P1.3-03, S4.1): when the
 * caller supplies no `expiresAt`, the Action stamps `now() + Config::shareDefaultTtlHours()`
 * at mint time (NOT a DB column default, so it stays host-config-overridable and
 * every direct repository write in a test still goes through the same rule when it
 * routes through this Action). An explicit `expiresAt` above
 * `Config::shareMaxTtlHours()` is rejected by request validation
 * (StoreShareLinkRequest) before it reaches here — the Action does not re-clamp it.
 *
 * Authorization (the actor may Share the node) is enforced by the caller
 * (controller via AccessResolver Ability::Share) before reaching the Action — the
 * Action mints + persists, it does not re-authorize.
 */
final readonly class CreateShareLinkAction
{
    /**
     * How many times to retry on the (astronomically unlikely) token_hash UNIQUE
     * collision before failing loud rather than silently returning a wrong link.
     */
    private const int MAX_MINT_ATTEMPTS = 5;

    public function __construct(
        private ShareTokenGenerator $tokens,
        private NodeShareStoreRepository $shares,
        private VaulterDatabase $database,
    ) {}

    public function execute(Node $node, ShareLinkInput $data): MintedShareLink
    {
        $passwordHash = $data->password === null || $data->password === ''
            ? null
            : Hash::make($data->password);

        // F-P1.3-03: an omitted expiry defaults to the configured TTL, stamped at
        // mint time (not a DB default) so it stays host-overridable.
        $expiresAt = $data->expiresAt instanceof Carbon
            ? $data->expiresAt
            : Carbon::now()->addHours(Config::shareDefaultTtlHours());

        return $this->database->transaction(function () use ($node, $data, $passwordHash, $expiresAt): MintedShareLink {
            $minted = $this->mint(
                node: $node,
                data: $data,
                passwordHash: $passwordHash,
                expiresAt: $expiresAt,
            );

            event(new NodeShareCreated(share: $minted->share, actorId: $data->grantedBy));

            return $minted;
        });
    }

    /**
     * Generate a unique raw token (retrying on the token_hash UNIQUE collision) and
     * persist its hash + prefix. Returns the raw token alongside the row exactly
     * once — it is never recoverable afterward.
     */
    private function mint(Node $node, ShareLinkInput $data, ?string $passwordHash, Carbon $expiresAt): MintedShareLink
    {
        $prefixLength = Config::sharePrefixLength();

        for ($attempt = 0; $attempt < self::MAX_MINT_ATTEMPTS; $attempt++) {
            $token = $this->tokens->generate();

            try {
                $share = $this->shares->create(
                    node: $node,
                    tokenHash: ShareToken::hash($token),
                    tokenPrefix: ShareToken::prefix($token, $prefixLength),
                    permissions: $data->permissions,
                    level: $data->level->value,
                    scope: $data->scope->value,
                    passwordHash: $passwordHash,
                    requireLogin: $data->requireLogin,
                    allowDiscovery: $data->allowDiscovery,
                    expiresAt: $expiresAt,
                    maxUses: $data->maxUses,
                    grantedBy: $data->grantedBy,
                );
            } catch (QueryException $exception) {
                // A token_hash collision (or any unique violation on this row) means
                // the freshly-minted token already exists — retry with a new one.
                if ($this->isUniqueViolation($exception)) {
                    continue;
                }

                throw $exception;
            }

            return new MintedShareLink(share: $share, token: $token);
        }

        throw new RuntimeException(
            'Vaulter could not mint a unique share token after '.self::MAX_MINT_ATTEMPTS.' attempts.',
        );
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        // 23000 / 23505 are the SQLSTATE integrity-constraint-violation codes
        // (MySQL/SQLite use 23000, Postgres 23505 for unique).
        $state = $exception->errorInfo[0] ?? $exception->getCode();
        $sqlState = is_scalar($state) ? (string) $state : '';

        return in_array($sqlState, ['23000', '23505'], strict: true);
    }
}

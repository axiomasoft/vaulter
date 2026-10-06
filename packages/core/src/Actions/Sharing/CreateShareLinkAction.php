<?php

declare(strict_types=1);

namespace Vaulter\Actions\Sharing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;
use Vaulter\Configuration\SharingConfig;
use Vaulter\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Data\Sharing\CreateShareLinkInput;
use Vaulter\Data\Sharing\MintedShareLink;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\Permission;
use Vaulter\Events\Sharing\NodeShareCreated;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Sharing\NodeShareStoreRepository;
use Vaulter\Support\ShareToken;

/**
 * Mint a bearer share-link on a node (§2.7 / M2c) — the SEPARATE public sharing
 * channel. The raw token is generated here, returned ONCE via the result and NEVER
 * persisted: only its sha256 hash (token_hash, UNIQUE) and a short token_prefix are
 * stored. Any password is hashed before it touches the DB. The write runs in a
 * transaction and emits NodeShareCreated.
 *
 * A link is never minted immortal by omission (P1.2 / F-P1.3-03, S4.1): when the
 * caller supplies no `expiresAt`, the Action stamps `now() + $this->sharingConfig->defaultTtlHours()`
 * at mint time (NOT a DB column default, so it stays host-config-overridable and
 * every direct repository write in a test still goes through the same rule when it
 * routes through this Action). An explicit `expiresAt` above
 * `$this->sharingConfig->maxTtlHours()` is rejected both by request validation and by the Action before minting.
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
        private readonly SharingConfig $sharingConfig,
        private ShareTokenGenerator $tokens,
        private NodeShareStoreRepository $shares,
        private VaulterDatabase $database,
        private NodeLocator $locator,
    ) {}

    public function execute(OperationContext $context, CreateShareLinkInput $input): MintedShareLink
    {
        $node = $this->locator->find(context: $context, key: $input->node);
        $data = $input->link;
        if ($data->permissions < 0 || ($data->permissions & ~Permission::all()) !== 0 || $data->level === GrantLevel::Commenter || ($data->permissions & Permission::Comment->value) !== 0) {
            throw new InvalidArgumentException('Invalid anonymous share permissions.');
        }
        if ($data->maxUses !== null && $data->maxUses < 1) {
            throw new InvalidArgumentException('A share use budget must be positive.');
        }
        if ($data->password !== null && strlen($data->password) > 255) {
            throw new InvalidArgumentException('Share password is too long.');
        }
        if ($data->expiresAt !== null && ($data->expiresAt->lte(Carbon::now()) || $data->expiresAt->gt(Carbon::now()->addHours($this->sharingConfig->maxTtlHours())))) {
            throw new InvalidArgumentException('Share expiry must be within the configured TTL.');
        }
        $passwordHash = $data->password === null || $data->password === ''
            ? null
            : Hash::make($data->password);

        // F-P1.3-03: an omitted expiry defaults to the configured TTL, stamped at
        // mint time (not a DB default) so it stays host-overridable.
        $expiresAt = $data->expiresAt instanceof Carbon
            ? $data->expiresAt
            : Carbon::now()->addHours($this->sharingConfig->defaultTtlHours());

        return $this->database->transaction(function () use ($context, $node, $data, $passwordHash, $expiresAt): MintedShareLink {
            $minted = $this->mint(
                context: $context,
                node: $node,
                data: $data,
                passwordHash: $passwordHash,
                expiresAt: $expiresAt,
            );

            event(new NodeShareCreated(share: $minted->share, actorId: $context->actor->ref()->id, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $minted;
        });
    }

    /**
     * Generate a unique raw token (retrying on the token_hash UNIQUE collision) and
     * persist its hash + prefix. Returns the raw token alongside the row exactly
     * once — it is never recoverable afterward.
     */
    private function mint(OperationContext $context, Node $node, ShareLinkInput $data, ?string $passwordHash, Carbon $expiresAt): MintedShareLink
    {
        $prefixLength = $this->sharingConfig->prefixLength();

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
                    grantedBy: $context->actor->ref()->id,
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

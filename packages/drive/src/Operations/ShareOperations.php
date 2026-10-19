<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Closure;
use DateTimeInterface;
use Illuminate\Encryption\Encrypter;
use Illuminate\Pagination\CursorPaginator;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Actions\Sharing\CreateShareLinkAction;
use Vaulter\Drive\Actions\Sharing\RevokeShareLinkAction;
use Vaulter\Drive\Actions\Sharing\RotateShareLinkAction;
use Vaulter\Drive\Actions\Sharing\UpdateShareLinkAction;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\Sharing\ShareOperations as Contract;
use Vaulter\Drive\Data\Sharing\CreateShareLinkInput;
use Vaulter\Drive\Data\Sharing\RevokeShareInput;
use Vaulter\Drive\Data\Sharing\RotateShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkUpdate;
use Vaulter\Drive\Data\Sharing\UpdateShareLinkInput;
use Vaulter\Drive\Data\Snapshots\Mapping\AccessSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\MintedShareLink;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Idempotency\AccessReplayAuthorizer;
use Vaulter\Drive\Idempotency\RevokeReceipt;
use Vaulter\Drive\Idempotency\ShareReceiptCodec;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;

#[Api]
final readonly class ShareOperations implements Contract
{
    #[Internal]
    public function __construct(private DriveContext $context, private NodeLocator $locator, private OperationGuard $guard, private DriveConfig $config, private CreateShareLinkAction $createLink, private RevokeShareLinkAction $deleteLink, private UpdateShareLinkAction $updateLink, private RotateShareLinkAction $rotateLink, private AccessSnapshotMapper $mapper, private OperationRunner $runner, private Encrypter $encrypter) {}

    /** @return list<ShareLinkSnapshot> */
    public function list(NodeSnapshot|string|int $node): array
    {
        $node = $this->authorized($node, mutation: false);
        $class = $this->config->model('node_share');

        return array_values($class::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn (NodeShare $share): ShareLinkSnapshot => $this->mapper->share($share))->all());
    }

    public function create(NodeSnapshot|string|int $node, ShareLinkInput $input): MintedShareLink
    {
        $nodeId = self::idOf($node);
        // An append (D47(4)); the receipt holds the token only as ciphertext (ID5).
        $idempotency = $this->idempotent($nodeId, [
            'node' => $nodeId, 'role' => $input->role, 'audience' => $input->audience->value, 'scope' => $input->scope->value,
            'expires_at' => self::time($input->expiresAt), 'password' => $this->secret($input->password), 'max_uses' => $input->maxUses,
        ]);

        return $this->write('create', function () use ($node, $input): MintedShareLink {
            $node = $this->authorized($node);
            $minted = $this->createLink->execute(context: $this->context, input: new CreateShareLinkInput(node: $node, link: $input));

            return new MintedShareLink(link: $this->mapper->share($minted->share), token: $minted->token);
        }, $idempotency);
    }

    public function update(NodeSnapshot|string|int $node, ShareLinkSnapshot|string|int $share, ShareLinkUpdate $changes, ?int $expectedVersion = null): ShareLinkSnapshot
    {
        $nodeId = self::idOf($node);
        $idempotency = $this->idempotent($nodeId, [
            'node' => $nodeId, 'share' => self::shareId($share), 'expires_at' => self::time($changes->expiresAt), 'password' => $this->secret($changes->password),
            'max_uses' => $changes->maxUses, 'audience' => $changes->audience?->value, 'remove_password' => $changes->removePassword,
            'remove_max_uses' => $changes->removeMaxUses, 'expected_version' => $expectedVersion,
        ]);

        return $this->write('update', function () use ($node, $share, $changes, $expectedVersion): ShareLinkSnapshot {
            $node = $this->authorized($node);

            return $this->mapper->share($this->updateLink->execute(context: $this->context, input: new UpdateShareLinkInput(node: $node, share: $this->owned($node, $share), changes: $changes, expectedVersion: $expectedVersion)));
        }, $idempotency);
    }

    public function rotate(NodeSnapshot|string|int $node, ShareLinkSnapshot|string|int $share, ?int $expectedVersion = null): MintedShareLink
    {
        $nodeId = self::idOf($node);
        $idempotency = $this->idempotent($nodeId, ['node' => $nodeId, 'share' => self::shareId($share), 'expected_version' => $expectedVersion]);

        return $this->write('rotate', function () use ($node, $share, $expectedVersion): MintedShareLink {
            $node = $this->authorized($node);
            $minted = $this->rotateLink->execute(context: $this->context, input: new RotateShareLinkInput(node: $node, share: $this->owned($node, $share), expectedVersion: $expectedVersion));

            return new MintedShareLink(link: $this->mapper->share($minted->share), token: $minted->token);
        }, $idempotency);
    }

    public function revoke(NodeSnapshot|string|int $node, ShareLinkSnapshot|string|int $share, ?int $expectedVersion = null): void
    {
        $nodeId = self::idOf($node);
        // The receipt keeps the link's id and the node the right was proven on (ID2), never the link itself.
        $idempotency = $this->idempotent($nodeId, ['node' => $nodeId, 'share' => self::shareId($share), 'expected_version' => $expectedVersion]);
        $this->write('revoke', function () use ($node, $share, $expectedVersion): RevokeReceipt {
            $node = $this->authorized($node);
            $link = $this->owned($node, $share);
            $this->deleteLink->execute(context: $this->context, input: new RevokeShareInput(node: $node, share: $link, expectedVersion: $expectedVersion));

            return new RevokeReceipt(kind: RevokeReceipt::SHARE, id: self::shareId($share), driveId: (string) $this->context->driveId(), nodeId: (string) $node->id, ability: Ability::Share->value);
        }, $idempotency);
    }

    /** @internal Existing HTTP list pagination.
     * @return CursorPaginator<int, ShareLinkSnapshot>
     */
    #[Internal]
    public function paginate(NodeSnapshot|string|int $node, int $perPage = 50): CursorPaginator
    {
        $node = $this->authorized($node, mutation: false);
        $class = $this->config->model('node_share');

        return $class::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(perPage: $perPage)->through(fn (NodeShare $share): ShareLinkSnapshot => $this->mapper->share($share));
    }

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    private function write(string $verb, Closure $attempt, IdempotentWrite $idempotency): mixed
    {
        return $this->runner->write(new OperationSpec('drive.shares.'.$verb, new ModuleId('drive')), $this->context->operation, $attempt, $idempotency);
    }

    /** @param array<string, mixed> $fingerprint */
    private function idempotent(string $nodeId, array $fingerprint): IdempotentWrite
    {
        return new IdempotentWrite(
            codec: new ShareReceiptCodec($this->encrypter),
            authorizer: AccessReplayAuthorizer::forShares($this->context, $this->locator, $this->guard, $nodeId),
            fingerprint: $fingerprint,
        );
    }

    /**
     * A password enters the fingerprint only as a keyed hash: the request hash is stored, the password never is. The
     * application key makes it useless for guessing without that key.
     */
    private function secret(?string $password): ?string
    {
        return $password === null ? null : hash_hmac('sha256', $password, hash_hmac('sha256', 'vaulter.share-password', $this->encrypter->getKey(), true));
    }

    private static function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(DateTimeInterface::RFC3339_EXTENDED);
    }

    private static function idOf(NodeSnapshot|string|int $node): string
    {
        return $node instanceof NodeSnapshot ? $node->id : (string) $node;
    }

    private static function shareId(ShareLinkSnapshot|string|int $share): string
    {
        return $share instanceof ShareLinkSnapshot ? $share->id : (string) $share;
    }

    /** The link, provided it hangs on this node; any other id looks like a missing node. */
    private function owned(Node $node, ShareLinkSnapshot|string|int $share): NodeShare
    {
        $class = $this->config->model('node_share');
        $key = $share instanceof ShareLinkSnapshot ? $share->id : $share;

        return $class::query()->where('node_id', $node->id)->whereKey($key)->first() ?? throw NodeNotFoundException::forId($node->id);
    }

    private function authorized(NodeSnapshot|string|int $node, bool $mutation = true): Node
    {
        $node = $this->locator->find(context: $this->context, key: $node);
        if ($node->trashed_at !== null) {
            throw NodeNotFoundException::forId($node->id);
        }
        $this->guard->authorize(context: $this->context, ability: Ability::Share, node: $node);
        if ($mutation) {
            $this->guard->assertMutable($this->context, $node);
        }

        return $node;
    }
}

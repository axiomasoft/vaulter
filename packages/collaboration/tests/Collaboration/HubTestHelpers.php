<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Tests\Collaboration;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Vaulter\Collaboration\CollaborationCallResolver;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Collaboration\Contracts\HostUserResolver;
use Vaulter\Collaboration\Http\Support\HubProtocol;
use Vaulter\Collaboration\Testing\Fixtures\FakeHostUserResolver;
use Vaulter\Collaboration\Tests\Support\DocumentTestHelpers;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/**
 * The hub's side of the protocol for tests: room tokens shaped exactly like
 * HocuspocusCollaborationDriver mints them, and requests signed the way
 * packages/collaboration/hub/src/webhook.js signs them. An unsigned or token-less request never
 * reaches the controller.
 */
final class HubTestHelpers
{
    public const string WEBHOOK_SECRET = 'webhook-shared-secret';

    public const string JWT_SECRET = 'jwt-signing-secret-that-is-at-least-32-bytes';

    public static function configure(): void
    {
        config()->set('vaulter-drive.access.permission_driver', 'database');
        config()->set('vaulter-collaboration.driver', 'hocuspocus');
        config()->set('vaulter-collaboration.hub.webhook_secret', self::WEBHOOK_SECRET);
        config()->set('vaulter-collaboration.hub.jwt_secret', self::JWT_SECRET);
        config()->set('vaulter-collaboration.hub.timestamp_tolerance', 300);

        // The hub calls carry only the token subject; materialise it without a host
        // user provider.
        app()->bind(HostUserResolver::class, static fn (): FakeHostUserResolver => new FakeHostUserResolver);
    }

    public static function document(
        ?string $level = RoleRegistry::WRITER,
        int $actorId = 1,
        ?DocumentContent $content = null,
        string $type = 'vaulter/rich-text',
        int $schemaVersion = 1,
    ): Node {
        $drive = DocumentTestHelpers::drive();

        $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
            storage: $drive->model(),
            name: 'Spec',
            content: $content ?? DocumentTestHelpers::content('Draft'),
            parent: DriveTestHelpers::model($drive->root()),
            type: $type,
            schemaVersion: $schemaVersion,
        ));

        if ($level !== null) {
            AccessTestHelpers::grant($node, $level, subjectId: $actorId);
        }

        return $node;
    }

    public static function room(Node $node, ?int $epoch = null): string
    {
        return app(CollaborationConfig::class)->room($node->drive_id, $node->getKey(), $epoch ?? self::aggregate($node)->collab_epoch);
    }

    /** @param array<string, mixed> $claims overrides of the minted claims */
    public static function token(Node $node, string $mode = 'rw', int $actorId = 1, ?int $epoch = null, array $claims = []): string
    {
        $epoch ??= self::aggregate($node)->collab_epoch;

        return JWT::encode(
            payload: [
                'sub' => (string) $actorId,
                'node' => (string) $node->getKey(),
                'drive' => (string) $node->drive_id,
                'tenant' => null,
                'epoch' => $epoch,
                'schema_version' => 1,
                'mode' => $mode,
                'room' => self::room($node, $epoch),
                'iat' => time(),
                'exp' => time() + 3600,
                ...$claims,
            ],
            key: self::JWT_SECRET,
            alg: 'HS256',
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, string>
     */
    public static function headers(array $body, ?string $token, ?int $timestamp = null, ?string $secret = null): array
    {
        $timestamp ??= time();
        $signature = CollaborationCallResolver::signature(rawBody: (string) json_encode($body), secret: $secret ?? self::WEBHOOK_SECRET, timestamp: $timestamp);

        return array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            CollaborationCallResolver::TIMESTAMP_HEADER => (string) $timestamp,
            CollaborationCallResolver::SIGNATURE_HEADER => $signature,
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * One hub call. Like the hub, every body carries a fresh nonce: two calls with the
     * same payload in the same second (authenticate, then load) must not share a
     * signature, or the second one reads as a replay.
     *
     * @param array<string, mixed> $body
     */
    public static function call(string $endpoint, array $body, ?string $token): TestResponse
    {
        $body['nonce'] = bin2hex(random_bytes(8));

        return test()->postJson('api/vaulter/v1/collaboration/hub/'.$endpoint, $body, self::headers($body, $token));
    }

    /**
     * One protocol 2 hub call: the same signed request with `X-Vaulter-Collab-Protocol` (`$protocol` null leaves the
     * header out).
     *
     * @param array<string, mixed> $body
     */
    public static function callV2(string $endpoint, array $body, ?string $token, ?string $protocol = '2'): TestResponse
    {
        $body['nonce'] = bin2hex(random_bytes(8));
        $headers = self::headers($body, $token);
        if ($protocol !== null) {
            $headers[HubProtocol::HEADER] = $protocol;
        }

        return test()->postJson('api/vaulter/v1/collaboration/hub/'.$endpoint, $body, $headers);
    }

    /**
     * A protocol 2 append body as the hub sends it: the payload is the binary update, its hash is computed unless given.
     *
     * @return array<string, mixed>
     */
    public static function appendBody(Node $node, string $payload, ?string $updateId = null, ?int $generation = null, ?string $sha256 = null, string $origin = 'client'): array
    {
        $generation ??= self::aggregate($node)->collab_epoch;

        return [
            'room' => self::room($node, $generation),
            'generation' => $generation,
            'update_id' => $updateId ?? (string) Str::uuid(),
            'sha256' => $sha256 ?? hash('sha256', $payload),
            'payload' => base64_encode($payload),
            'origin' => $origin,
        ];
    }

    /**
     * A store body as the hub sends it.
     *
     * @param  array<string, mixed>|null $content
     * @return array<string, mixed>
     */
    public static function storeBody(Node $node, string $state, ?array $content = null, bool $seed = false, ?int $epoch = null, ?int $stateVersion = null): array
    {
        return array_filter([
            'room' => self::room($node, $epoch),
            'state' => base64_encode($state),
            'content' => $content ?? self::tiptap('From hub'),
            'seed' => $seed ?: null,
            'state_version' => $stateVersion,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function tiptap(string $text): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    public static function aggregate(Node $node): Document
    {
        return Document::query()->where('node_id', $node->getKey())->firstOrFail();
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use InvalidArgumentException;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/**
 * The receipt of `uploads.initiate`: the session as it was when it was opened, the fields a client is given
 * (upload id, protocol, offset, size, target) — never the staged bytes, their storage key or a blob. A replay returns
 * the same session handle without opening a second one. Version 1.
 */
#[Internal]
final class UploadSessionCodec implements ReceiptCodec
{
    public const string KIND = 'drive.upload-session';

    public function kind(): string
    {
        return self::KIND;
    }

    public function version(): int
    {
        return 1;
    }

    public function encode(mixed $result): array
    {
        $session = self::session($result);
        $key = $session->getRawOriginal($session->getKeyName());

        return [
            'id' => is_int($key) || is_string($key) ? $key : throw new LogicException('[Vaulter] An upload session receipt needs a persisted session.'),
            'upload_id' => $session->upload_id,
            'drive_id' => $session->drive_id,
            'protocol' => $session->protocol->value,
            'status' => $session->status->value,
            'received_bytes' => $session->received_bytes,
            'total_size_bytes' => $session->total_size_bytes,
            'chunk_size' => $session->chunk_size,
            'mime_type' => $session->mime_type,
            'target_node_id' => $session->target_node_id,
            'target_parent_id' => $session->target_parent_id,
            'target_name' => $session->target_name,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ];
    }

    public function decode(array $body, int $version): UploadSession
    {
        if ($version !== 1) {
            throw new LogicException(sprintf('[Vaulter] The receipt codec %s does not know version %d.', self::KIND, $version));
        }
        $class = app(DriveConfig::class)->model('upload_session');

        return (new $class)->newFromBuilder($body);
    }

    /** A revision upload is about the file it targets; a new file has no node yet, so its replay is authorized in the parent. */
    public function resource(mixed $result): ?ResourceRef
    {
        $target = self::session($result)->target_node_id;

        return $target === null ? null : new ResourceRef('node', (string) $target);
    }

    private static function session(mixed $result): UploadSession
    {
        return $result instanceof UploadSession ? $result : throw new InvalidArgumentException(sprintf('[Vaulter] %s encodes an UploadSession, %s given.', self::KIND, get_debug_type($result)));
    }
}

<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Core\Protocol;

use InvalidArgumentException;

/**
 * Target: packages/core/src/Protocol/RequestFingerprint.php in vaulter-ai. v_ai_owner_routes.request_fingerprint and
 * v_ai_text_commands.request_fingerprint = SHA-256 of the JCS object {"command","digest","proposal_id"}.
 * The three values are restricted to printable ASCII without quote and backslash, so sorted-key json_encode
 * produces exactly the RFC 8785 bytes.
 */
final class RequestFingerprint
{
    public static function of(string $command, string $proposalId, string $digestHex): string
    {
        foreach ([$command, $proposalId, $digestHex] as $value) {
            if (preg_match('/^[\x20\x21\x23-\x5b\x5d-\x7e]{1,255}$/', $value) !== 1) {
                throw new InvalidArgumentException('Fingerprint inputs are printable ASCII identifiers.');
            }
        }
        if (preg_match('/^[0-9a-f]{64}$/', $digestHex) !== 1) {
            throw new InvalidArgumentException('digest is 64 lowercase hex characters.');
        }

        return hash('sha256', json_encode(
            ['command' => $command, 'digest' => $digestHex, 'proposal_id' => $proposalId],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }
}

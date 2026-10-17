<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Http\Controllers\Collaboration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\CollaborationHub;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Collaboration\Exceptions\CollaborationUpdateTooLargeException;
use Vaulter\Collaboration\Http\Middleware\ResolveCollaborationCall;
use Vaulter\Collaboration\Http\Support\HubProtocol;
use Vaulter\Collaboration\Persistence\UpdateEnvelope;

/**
 * Protocol 2 append: one Yjs update of the room's generation into the document's log. Body `{room, generation,
 * update_id (uuid), sha256 (hex of the payload), payload (base64), origin: client|server}`. The answer `{generation,
 * update_id, seq, sha256, persisted: true}` is built after the commit; a retry with the same update_id and payload gets
 * the same seq. The base64 length is checked against `limits.update_bytes` before it is decoded.
 */
#[Internal]
final readonly class HubAppendController
{
    public function __construct(private CollaborationHub $hub, private CollaborationConfig $config) {}

    public function __invoke(Request $request): JsonResponse
    {
        HubProtocol::require($request);
        $call = ResolveCollaborationCall::call($request);
        $update = $this->envelope($request);
        $room = $this->hub->open($call, $request->input('room'));

        return HubProtocol::mark(new JsonResponse($this->hub->append($room, $call, $update)->ack()));
    }

    private function envelope(Request $request): UpdateEnvelope
    {
        $generation = $request->input('generation');
        $updateId = $request->input('update_id');
        $sha256 = $request->input('sha256');
        $payload = $request->input('payload');
        $origin = $request->input('origin', 'client');

        $errors = array_filter([
            'generation' => is_int($generation) && $generation >= 0 ? null : 'The generation must be a non-negative integer.',
            'update_id' => is_string($updateId) && Str::isUuid($updateId) ? null : 'The update_id must be a UUID.',
            'sha256' => is_string($sha256) && preg_match('/^[0-9a-fA-F]{64}$/', $sha256) === 1 ? null : 'The sha256 must be 64 hex digits.',
            'payload' => is_string($payload) && $payload !== '' ? null : 'The payload must be a non-empty base64 string.',
            'origin' => in_array($origin, ['client', 'server'], true) ? null : 'The origin must be client or server.',
        ]);
        if ($errors !== [] || !is_int($generation) || !is_string($updateId) || !is_string($sha256) || !is_string($payload) || !is_string($origin)) {
            throw ValidationException::withMessages(array_map(static fn (string $message): array => [$message], $errors));
        }

        // The size is known from the encoded length: refuse before decoding allocates the update.
        $max = $this->config->updateBytes();
        $encodedMax = 4 * (int) ceil($max / 3);
        if (strlen($payload) > $encodedMax) {
            throw new CollaborationUpdateTooLargeException(intdiv(strlen($payload), 4) * 3, $max);
        }
        $decoded = base64_decode($payload, strict: true);
        if ($decoded === false || $decoded === '') {
            throw ValidationException::withMessages(['payload' => ['The payload must be valid base64-encoded binary.']]);
        }
        if (strlen($decoded) > $max) {
            throw new CollaborationUpdateTooLargeException(strlen($decoded), $max);
        }

        return new UpdateEnvelope(generation: $generation, updateId: strtolower($updateId), payload: $decoded, claimedSha256: $sha256, origin: $origin);
    }
}

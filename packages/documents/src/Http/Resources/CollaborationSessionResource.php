<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;

/**
 * What a client needs to join a document's room: the room of this generation, its mode
 * (`write` or `read`) and the short-lived token the hub checks again on every call. The
 * token is shown once, so the response is never stored.
 */
#[Internal]
final class CollaborationSessionResource extends JsonResource
{
    public function __construct(CollaborationSession $session)
    {
        parent::__construct($session);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var CollaborationSession $session */
        $session = $this->resource;

        return [...$session->toArray(), 'options' => (object) $session->options];
    }
}

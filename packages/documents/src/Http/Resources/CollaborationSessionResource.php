<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;

/**
 * What a client needs to join a document's room: the room of this generation, its mode
 * (`rw` or `ro`) and the short-lived token the hub checks again on every call. The
 * token is shown once, so the response is never stored.
 */
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

        return $session->toArray();
    }
}

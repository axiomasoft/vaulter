<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources\Sharing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\MintedShareLink;

/**
 * A share link right after it was created or rotated: the only response that carries
 * the raw token and the URL built from it. The token is stored hashed and cannot be
 * shown again.
 *
 * @mixin MintedShareLink
 */
#[Internal]
final class MintedShareResource extends JsonResource
{
    /** @param string $urlBase the public share URL base the token is appended to */
    public function __construct(MintedShareLink $minted, private readonly string $urlBase)
    {
        parent::__construct($minted);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var MintedShareLink $minted */
        $minted = $this->resource;

        return [
            ...(new ShareResource($minted->link))->toArray($request),
            'token' => $minted->token,
            'url' => rtrim($this->urlBase, '/').'/'.$minted->token,
        ];
    }
}

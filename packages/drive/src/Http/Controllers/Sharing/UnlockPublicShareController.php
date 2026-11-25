<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Sharing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Packman\Attributes\Internal;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Sharing\ShareNotFoundException;
use Vaulter\Drive\Http\Middleware\ResolveShareToken;
use Vaulter\Drive\Http\Resources\Sharing\SharedNodeResource;
use Vaulter\Drive\Http\Support\SharedNodeResolver;
use Vaulter\Drive\Sharing\ShareDownloadGrant;
use Vaulter\Support\Identity;

/**
 * Trades a share password, sent in the POST body or the X-Share-Password header, for a
 * download URL of one file that works without it for a few minutes: what a plain
 * `<a href>` needs, since it can send neither a header nor a body. The body's `node`
 * picks a file inside a shared folder, as on the download route. The same checks as a
 * download apply first, so a previewer link or a folder gets no URL.
 */
#[Internal]
final readonly class UnlockPublicShareController
{
    public function __construct(private SharedNodeResolver $nodes, private ShareDownloadGrant $grants) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = ResolveShareToken::context($request);
        if (!$context->allows(Ability::View)) {
            throw ShareNotFoundException::opaque();
        }
        if (!$context->allows(Ability::Download)) {
            throw PermissionDeniedException::forAbility(Ability::Download->value);
        }
        $selected = $request->post('node');
        $node = $this->nodes->node($context, is_string($selected) && $selected !== '' ? $selected : null);
        if ($node->kind !== NodeKind::File) {
            throw ShareNotFoundException::opaque();
        }
        $resource = SharedNodeResource::opaqueId(Identity::key($node->getKey()));
        $now = Carbon::now();
        // Without an application key nothing can be signed, and an unsigned grant would be no proof.
        $grant = $resource === null ? null : $this->grants->issue($context->share, $resource, $now);
        if ($grant === null) {
            throw ShareNotFoundException::opaque();
        }
        $token = $request->route('token');

        return new JsonResponse(['data' => [
            'download_url' => route('vaulter.v1.public-shares.download', [
                'token' => $token,
                'node' => $resource,
                ShareDownloadGrant::PARAMETER => $grant,
            ], false),
            'expires_at' => Carbon::createFromTimestamp($this->grants->expiry($context->share, $now))->toIso8601ZuluString(),
        ]]);
    }
}

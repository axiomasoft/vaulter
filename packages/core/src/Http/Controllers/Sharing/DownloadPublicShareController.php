<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Sharing;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Sharing\ShareNotFoundException;
use Vaulter\Http\Middleware\ResolveShareToken;
use Vaulter\Http\Support\ContentResponder;
use Vaulter\Http\Support\SharedNodeResolver;

/**
 * The bytes of a shared file (`?node=` picks a listed child of a shared folder). A link
 * whose role cannot download (a previewer) gets 403: it may show the file, never hand
 * out the original.
 */
#[Internal]
final readonly class DownloadPublicShareController
{
    public function __construct(private SharedNodeResolver $nodes, private ContentResponder $content) {}

    public function __invoke(Request $request): Response
    {
        $context = ResolveShareToken::context($request);
        if (!$context->allows(Ability::View)) {
            throw ShareNotFoundException::opaque();
        }
        if (!$context->allows(Ability::Download)) {
            throw PermissionDeniedException::forAbility(Ability::Download->value);
        }
        $selected = $request->query('node');
        $node = $this->nodes->node($context, is_string($selected) && $selected !== '' ? $selected : null);
        if ($node->kind !== NodeKind::File) {
            throw ShareNotFoundException::opaque();
        }

        return $this->content->share($context, $node, $this->nodes->head($context, $node));
    }
}

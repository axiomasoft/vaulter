<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Files;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Packman\Attributes\Internal;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Delete a revision that is neither the head nor kept forever (Manage), releasing its bytes. */
#[Internal]
final readonly class DeleteFileRevisionController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $node, string $revision): Response
    {
        $this->contexts->forDrive($request, $this->resolver->driveOfNode($node))->files()->deleteRevision($node, $revision);

        return new Response(status: 204);
    }
}

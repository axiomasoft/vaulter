<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Access;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/** Revoke one grant; a grant of another node is as unknown as a missing one. */
#[Internal]
final readonly class RevokeGrantController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $node, string $grant): Response
    {
        $this->contexts->forDrive($request, $this->resolver->driveOfNode($node))->access()->revoke($node, $grant);

        return new Response(status: 204);
    }
}

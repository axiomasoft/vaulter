<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Sharing;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

#[Internal]
final readonly class RevokeShareController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $node, string $share): Response
    {
        $this->contexts->forDrive($request, $this->resolver->driveOfNode($node))->shares()->revoke($node, $share);

        return new Response(status: 204);
    }
}

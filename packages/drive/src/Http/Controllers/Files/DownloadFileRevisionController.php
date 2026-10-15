<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Files;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Http\Support\ContentResponder;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/** The bytes of one revision of a file (Download), checked exactly like the head. */
#[Internal]
final readonly class DownloadFileRevisionController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private ContentResponder $content) {}

    public function __invoke(Request $request, string $node, string $revision): Response
    {
        return $this->content->file($request, $this->contexts->forDrive($request, $this->resolver->driveOfNode($node)), $node, $revision);
    }
}

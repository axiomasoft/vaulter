<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Files;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Http\Support\ContentResponder;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/**
 * The bytes of a file's head revision (Download). A caller who may only view the file
 * gets 403 and a revision that is not scanned clean 423, both before any byte.
 */
#[Internal]
final readonly class DownloadFileController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private ContentResponder $content) {}

    public function __invoke(Request $request, string $node): Response
    {
        return $this->content->file($request, $this->contexts->forDrive($request, $this->resolver->driveOfNode($node)), $node, null);
    }
}

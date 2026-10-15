<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Files;

use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Http\Resources\FileRevisionResource;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Enums\Access\Ability;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/** Copy an older revision forward as the new head (Edit); If-Match is required. */
#[Internal]
final readonly class RestoreFileRevisionController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(Request $request, string $node, string $revision): FileRevisionResource
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $expected = $this->nodes->expectedSequence($request, $drive, $node, Ability::Edit);

        return new FileRevisionResource($drive->files()->restoreRevision($node, $revision, $expected));
    }
}

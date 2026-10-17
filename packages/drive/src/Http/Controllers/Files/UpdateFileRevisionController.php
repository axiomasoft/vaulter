<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Files;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Http\Requests\Files\UpdateFileRevisionRequest;
use Vaulter\Drive\Http\Resources\FileRevisionResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Keep a revision forever or hand it back to the retention policy (Edit). */
#[Internal]
final readonly class UpdateFileRevisionController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(UpdateFileRevisionRequest $request, string $node, string $revision): FileRevisionResource
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));

        return new FileRevisionResource($drive->files()->setRevisionRetention($node, $revision, $request->keepForever()));
    }
}

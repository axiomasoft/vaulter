<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Documents;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Packman\Attributes\Internal;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Drive\Http\Support\ContentResponder;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/**
 * Render a document's working state — or the checkpoint named by `?revision=` — in the
 * given format as a download (Download). Rendering never creates a revision.
 */
#[Internal]
final readonly class ExportDocumentController
{
    public function __construct(
        private HttpResourceResolver $resolver,
        private HttpOperationContextFactory $contexts,
        private DocumentsManager $documents,
        private ContentResponder $content,
    ) {}

    public function __invoke(Request $request, string $node, string $format): Response
    {
        Validator::make($request->query(), ['revision' => ['sometimes', 'string', 'max:191']])->validate();
        $revision = $request->query('revision');
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $export = $this->documents->in($drive)->export($node, $format, is_string($revision) ? $revision : null);

        return $this->content->export($export->bytes, $export->filename, $export->mimeType, $export->lossy);
    }
}

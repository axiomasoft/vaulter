<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Vaulter\Contracts\Documents\DocumentExporter;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Requests\ExportDocumentRequest;
use Vaulter\Models\Documents\Document;
use Vaulter\Repositories\Access\DocumentVisibilityService;
use Vaulter\Repositories\Documents\DocumentReadRepository;
use Vaulter\ValueObjects\Documents\TipTapContent;
use Vaulter\VaulterManager;

final class ExportController extends Controller
{
    public function __construct(
        private readonly DocumentReadRepository $documents,
        private readonly DocumentVisibilityService $visibility,
        private readonly VaulterManager $manager,
    ) {}

    public function show(ExportDocumentRequest $request, int|string $documentId): JsonResponse
    {
        $document = $this->documents->findOrFail($documentId);

        if (!$this->visibility->can($request->user(), 'view', $document)) {
            throw PermissionDeniedException::forAction('view');
        }

        $exporter = $this->manager->exporter($request->exportFormat());
        $content = TipTapContent::fromArray($this->contentArray($document));

        return new JsonResponse([
            'data' => [
                'format' => $exporter->format(),
                'content' => $exporter->export($content),
                'filename' => $this->filename($document, $exporter),
                'mime_type' => $exporter->mimeType(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function contentArray(Document $document): array
    {
        $content = $document->content;

        if (is_array($content) && ($content['type'] ?? null) === 'doc') {
            return $content;
        }

        return ['type' => 'doc', 'content' => []];
    }

    private function filename(Document $document, DocumentExporter $exporter): string
    {
        $slug = Str::slug($document->title);

        return ($slug !== '' ? $slug : 'document').'.'.$exporter->extension();
    }
}

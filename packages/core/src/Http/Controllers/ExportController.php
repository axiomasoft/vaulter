<?php

declare(strict_types=1);

namespace DocVault\Http\Controllers;

use DocVault\Enums\ExportFormat;
use DocVault\Exceptions\PermissionDeniedException;
use DocVault\Http\Requests\ExportDocumentRequest;
use DocVault\Models\Document;
use DocVault\Repositories\DocumentReadRepository;
use DocVault\Repositories\DocumentVisibilityService;
use DocVault\Support\TipTapRenderer;
use DocVault\ValueObjects\TipTapContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class ExportController extends Controller
{
    public function __construct(
        private readonly DocumentReadRepository $documents,
        private readonly DocumentVisibilityService $visibility,
        private readonly TipTapRenderer $renderer,
    ) {}

    public function show(ExportDocumentRequest $request, int|string $documentId): JsonResponse
    {
        $document = $this->documents->findOrFail($documentId);

        if (!$this->visibility->can($request->user(), 'view', $document)) {
            throw PermissionDeniedException::forAction('view');
        }

        $format = $request->exportFormat();
        $content = TipTapContent::fromArray($this->contentArray($document));

        return new JsonResponse([
            'data' => [
                'format' => $format->value,
                'content' => $this->renderer->render($content, $format),
                'filename' => $this->filename($document, $format),
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

    private function filename(Document $document, ExportFormat $format): string
    {
        $slug = Str::slug($document->title);

        return ($slug !== '' ? $slug : 'document').'.'.$format->extension();
    }
}

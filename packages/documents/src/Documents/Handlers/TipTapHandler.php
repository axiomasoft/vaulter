<?php

declare(strict_types=1);

namespace Vaulter\Documents\Documents\Handlers;

use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\Documents\Enums\Documents\DocumentType;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\HandlerCapabilities;
use Vaulter\ValueObjects\Handlers\HandlerUi;

/**
 * The built-in rich-text handler (master plan §7 level 1): the TipTap/Yjs editor
 * for document nodes whose payload is the structural TipTap JSON in
 * v_node_document_versions. It wraps the EXISTING TipTapContent / TipTapRenderer
 * subsystem — the node-attached document path reuses the same renderer, so HTML/MD
 * export and the editor stay one source of truth.
 *
 * It claims the Vaulter document mime family ("application/vnd.vaulter.document+json"
 * plus the legacy "text/markdown" preview) and the docx-like / markdown extensions
 * a rich-text editor opens. Capabilities: editable + previewable + COLLABORATIVE
 * (the Yjs/Hocuspocus seam, completed in M4b). It surfaces the per-DocumentType
 * TipTap extension list as a prop so the client mounts the right toolbar.
 */
final class TipTapHandler implements FileHandler
{
    public const int PRIORITY = 100;

    /**
     * The canonical mime a Vaulter document node carries — the structural TipTap
     * JSON envelope. A node created through CreateDocumentNodeAction is stamped with
     * this, so a document node always routes here regardless of a title extension.
     */
    public const string DOCUMENT_MIME = 'application/vnd.vaulter.document+json';

    public function id(): string
    {
        return 'tiptap';
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return [
            self::DOCUMENT_MIME,
            'text/markdown',
            // docx-like documents resolve here as the editable rich-text surface
            // (the OOXML→TipTap import itself is a registerable pipe, M4b+).
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
        ];
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['md', 'markdown', 'doc', 'docx', 'vdoc'];
    }

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function capabilities(FileDescriptor $file): HandlerCapabilities
    {
        return new HandlerCapabilities(
            editable: true,
            previewable: true,
            downloadable: true,
            thumbnailable: false,
            collaborative: true,
            richText: true,
        );
    }

    public function ui(FileDescriptor $file): HandlerUi
    {
        return new HandlerUi(
            component: 'VaulterDocEditor',
            route: 'documents.show',
            props: [
                'format' => $this->formatFor($file),
                'extensions' => $this->tiptapExtensionsFor($file),
            ],
        );
    }

    /**
     * The DocumentType the editor opens the file as — derived from the extension
     * (markdown for .md), else rich text. This is the built-in SUGAR over the
     * registry (H.5): the handler resolved by mime/extension, not gated by the enum.
     */
    private function formatFor(FileDescriptor $file): string
    {
        return match ($file->normalizedExtension()) {
            'md', 'markdown' => DocumentType::Markdown->value,
            default => DocumentType::RichText->value,
        };
    }

    /**
     * The TipTap extension set the client mounts for this file's format — read off
     * the DocumentType metadata so the editor toolbar matches.
     *
     * @return list<string>
     */
    private function tiptapExtensionsFor(FileDescriptor $file): array
    {
        $type = match ($file->normalizedExtension()) {
            'md', 'markdown' => DocumentType::Markdown,
            default => DocumentType::RichText,
        };

        return $type->tiptapExtensions();
    }
}

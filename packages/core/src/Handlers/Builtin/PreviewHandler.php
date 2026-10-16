<?php

declare(strict_types=1);

namespace Vaulter\Handlers\Builtin;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

/**
 * The built-in preview handler (level 4): PDFs and images render a
 * read-only preview and a thumbnail, and download. It claims "application/pdf" and
 * the "image/*" wildcard.
 *
 * SAFE-TO-INLINE (hardening): `previewable` advertises that a preview EXISTS, it
 * does NOT assert the bytes are safe to inline unsandboxed. This handler deliberately
 * does NOT claim text/html or image/svg+xml — an inline HTML/SVG preview is an XSS
 * vector unless sandboxed, so those fall through to DownloadOnly here. A host that
 * wants sandboxed HTML/SVG preview registers its own handler with an explicit
 * sandbox prop; core never inlines active content by default.
 */
#[Internal]
final class PreviewHandler implements FileHandler
{
    public const int PRIORITY = 80;

    public function key(): string
    {
        return 'vaulter/preview';
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return [
            'application/pdf',
            'image/*',
        ];
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'bmp'];
    }

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return new ContentCapabilities(
            editable: false,
            previewable: true,
            downloadable: true,
            thumbnailable: true,
            collaborative: false,
        );
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        return new ViewerDescriptor(
            key: 'vaulter/pdf-preview',
            props: [
                'mode' => $this->modeFor($file),
                // Active content is never inlined; the client must sandbox a
                // preview iframe. Core only ever marks pdf/image inline-safe.
                'inline_safe' => true,
            ],
        );
    }

    private function modeFor(FileDescriptor $file): string
    {
        $mime = $file->canonicalMime();

        if ($mime === 'application/pdf' || $file->normalizedExtension() === 'pdf') {
            return 'pdf';
        }

        return 'image';
    }
}

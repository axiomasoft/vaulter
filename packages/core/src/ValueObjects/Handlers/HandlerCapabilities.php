<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Handlers;

/**
 * What a document handler declares it can do with a given file (master plan §4.5).
 * Immutable; a handler computes one of these per FileDescriptor, and the
 * DocumentHandlerRegistry filters resolutions by the requested {@see
 * \Vaulter\Documents\Enums\Documents\Capability}. downloadable defaults to true (anything can be
 * downloaded as bytes); the richer flags are opt-in.
 *
 * NOTE on previewable vs safe-to-inline (H.5 hardening): `previewable` means a
 * handler CAN render a preview, NOT that the bytes are safe to inline unsandboxed.
 * HTML/SVG are previewable but must NEVER be inlined into the app origin without a
 * sandbox (see PreviewHandler) — the client decides the sandboxing, the capability
 * only advertises that a preview exists.
 */
final readonly class HandlerCapabilities
{
    /**
     * @param bool $richText Whether this handler's editable content is a TipTap rich-text
     *                       doc ({"type":"doc"}). Defaults TRUE — the historical shape. A
     *                       non-rich-text editor (e.g. the spreadsheet handler) sets it
     *                       FALSE, and the content write path then stores its JSON snapshot
     *                       verbatim (OpaqueDocumentContent) instead of validating and
     *                       projecting it as TipTap.
     */
    public function __construct(
        public bool $editable = false,
        public bool $previewable = false,
        public bool $downloadable = true,
        public bool $thumbnailable = false,
        public bool $collaborative = false,
        public bool $richText = true,
    ) {}

    /**
     * The download-only baseline (the null-object handler's capabilities): bytes
     * out, nothing else.
     */
    public static function downloadOnly(): self
    {
        return new self(downloadable: true);
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'editable' => $this->editable,
            'previewable' => $this->previewable,
            'downloadable' => $this->downloadable,
            'thumbnailable' => $this->thumbnailable,
            'collaborative' => $this->collaborative,
            'rich_text' => $this->richText,
        ];
    }
}

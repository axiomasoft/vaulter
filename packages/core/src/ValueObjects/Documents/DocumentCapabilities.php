<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Documents;

/**
 * What a document handler declares it can do with a given file (master plan §4.5).
 * Immutable; a handler computes one of these per FileDescriptor, and the
 * DocumentHandlerRegistry filters resolutions by the requested {@see
 * \Vaulter\Enums\Documents\Capability}. downloadable defaults to true (anything can be
 * downloaded as bytes); the richer flags are opt-in.
 *
 * NOTE on previewable vs safe-to-inline (H.5 hardening): `previewable` means a
 * handler CAN render a preview, NOT that the bytes are safe to inline unsandboxed.
 * HTML/SVG are previewable but must NEVER be inlined into the app origin without a
 * sandbox (see PreviewHandler) — the client decides the sandboxing, the capability
 * only advertises that a preview exists.
 */
final readonly class DocumentCapabilities
{
    public function __construct(
        public bool $editable = false,
        public bool $previewable = false,
        public bool $downloadable = true,
        public bool $thumbnailable = false,
        public bool $collaborative = false,
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
        ];
    }
}

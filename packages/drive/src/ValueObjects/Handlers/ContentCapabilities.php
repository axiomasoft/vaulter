<?php

declare(strict_types=1);

namespace Vaulter\Drive\ValueObjects\Handlers;

use Vaulter\Attributes\Api;

/**
 * What a client may do with a node's content: a file handler declares it per file, a
 * document type for its documents. The content's shape is never a capability — a
 * document's type decides it.
 * Immutable; a handler computes one of these per FileDescriptor, and the
 * FileHandlerRegistry filters resolutions by the requested {@see
 * \Vaulter\Drive\Enums\Handlers\Capability}. downloadable defaults to true (anything can be
 * downloaded as bytes); the richer flags are opt-in.
 *
 * NOTE on previewable vs safe-to-inline (hardening): `previewable` means a
 * handler CAN render a preview, NOT that the bytes are safe to inline unsandboxed.
 * HTML/SVG are previewable but must NEVER be inlined into the app origin without a
 * sandbox (see PreviewHandler) — the client decides the sandboxing, the capability
 * only advertises that a preview exists.
 */
#[Api]
final readonly class ContentCapabilities
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

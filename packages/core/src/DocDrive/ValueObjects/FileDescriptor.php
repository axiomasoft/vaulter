<?php

declare(strict_types=1);

namespace Vaulter\DocDrive\ValueObjects;

/**
 * The immutable description of a file the {@see
 * \Vaulter\DocDrive\Documents\DocumentHandlerRegistry} resolves a handler for (master plan
 * §4.5). It carries everything the registry needs to route — the claimed (client)
 * mime, the server-SNIFFED detected mime, the extension and size — WITHOUT touching
 * the bytes or the database.
 *
 * SECURITY (H.5): claimedMime is the client-supplied Content-Type and is NEVER
 * trusted for routing. canonicalMime() prefers detectedMime (the server's own sniff)
 * and only falls back to claimedMime when no sniff is available — so a spoofed
 * Content-Type can never force an editable/preview handler onto hostile bytes.
 */
final readonly class FileDescriptor
{
    public function __construct(
        public int|string $nodeId,
        public string $filename,
        public ?string $extension = null,
        public ?string $claimedMime = null,
        public ?string $detectedMime = null,
        public ?string $objectHash = null,
        public int $size = 0,
    ) {}

    /**
     * Build a descriptor from a Node-like row, deriving the extension from the
     * filename when none is given. Callers pass the server-sniffed detectedMime
     * separately (it is not stored on the node) so the registry routes on truth.
     */
    public static function make(
        int|string $nodeId,
        string $filename,
        ?string $extension = null,
        ?string $claimedMime = null,
        ?string $detectedMime = null,
        ?string $objectHash = null,
        int $size = 0,
    ): self {
        return new self(
            nodeId: $nodeId,
            filename: $filename,
            extension: $extension ?? self::extensionFrom($filename),
            claimedMime: $claimedMime,
            detectedMime: $detectedMime,
            objectHash: $objectHash,
            size: $size,
        );
    }

    /**
     * The mime the registry routes on: the server-sniffed detectedMime when present
     * (the trusted value), else the claimedMime, else null. NEVER returns the
     * claimed mime over a detected one — that ordering is the spoof guard (H.5).
     */
    public function canonicalMime(): ?string
    {
        $detected = $this->normalize($this->detectedMime);

        if ($detected !== null) {
            return $detected;
        }

        return $this->normalize($this->claimedMime);
    }

    /**
     * The lower-cased extension without a leading dot (the registry's
     * extension-fallback key), or null when the file has none.
     */
    public function normalizedExtension(): ?string
    {
        if ($this->extension === null || $this->extension === '') {
            return null;
        }

        return mb_strtolower(ltrim($this->extension, '.'));
    }

    private function normalize(?string $mime): ?string
    {
        if ($mime === null) {
            return null;
        }

        // Strip any parameters (e.g. "; charset=utf-8") and case-fold the type.
        $base = trim(explode(';', $mime)[0]);

        return $base === '' ? null : mb_strtolower($base);
    }

    private static function extensionFrom(string $filename): ?string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        return $ext === '' ? null : mb_strtolower($ext);
    }
}

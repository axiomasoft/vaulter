<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Handlers;

/**
 * The format-extensibility registry seam (§4.5 / §7 / H.5) behind
 * `Vaulter::registerHandler()` / `Vaulter::handlers()`. Both the contract and the
 * concrete engine (`Vaulter\Engines\DocumentHandlerRegistry` — the SSOT resolver,
 * still `final`, H.5) are core-owned since VLT-CANON P0.4 (D10, superseding H.5/T5):
 * this binding always resolves, even without vaulter-documents installed, so a
 * drive-only host still gets preview/download resolution. Packages like
 * vaulter-documents only ADD handlers (TipTap, Univer, …) to this same registry.
 *
 * Deliberately minimal: only the two methods VaulterManager's public surface needs.
 * The richer resolve() API (mime/extension/capability routing) is an engine-internal
 * concern — {@see NodeHandlerResolver} is the core seam for per-node resolution.
 */
interface HandlerRegistry
{
    /**
     * Register a handler at runtime (the Vaulter::registerHandler seam). FQCN or
     * instance.
     *
     * @param FileHandler|class-string<FileHandler> $handler
     */
    public function register(FileHandler|string $handler): void;

    /**
     * Every registered handler (the introspection surface a host/admin lists formats
     * from), including the built-in null-object fallback.
     *
     * @return list<FileHandler>
     */
    public function all(): array;
}

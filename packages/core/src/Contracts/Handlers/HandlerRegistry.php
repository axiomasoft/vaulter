<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Handlers;

/**
 * The format-extensibility registry seam (§4.5 / §7 / H.5) behind
 * `Vaulter::registerHandler()` / `Vaulter::handlers()`. The contract lives in core
 * (VaulterManager's public entrypoint), the concrete engine ships in vaulter-documents
 * (its DocumentHandlerRegistry — the SSOT resolver, still `final`, H.5). A host that
 * has not installed vaulter-documents still type-hints against this interface;
 * resolving it fails loudly with an install hint.
 *
 * Deliberately minimal: only the two methods VaulterManager's public surface needs.
 * The richer resolve() API (mime/extension/capability routing) is a documents-internal
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

<?php

declare(strict_types=1);

namespace Vaulter\Drive\Handlers;

use Illuminate\Contracts\Container\Container;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Contracts\Handlers\FileHandler;
use Vaulter\Drive\Contracts\Handlers\FileHandlerRegistry as FileHandlerRegistryContract;
use Vaulter\Drive\Enums\Handlers\Capability;
use Vaulter\Drive\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Drive\ValueObjects\Handlers\FileDescriptor;
use Vaulter\Kernel;

/**
 * THE format-extensibility engine. Resolves the best
 * {@see FileHandler} for a {@see FileDescriptor} and a requested {@see
 * Capability}, from four cache-safe sources merged once:
 *
 *   1. the contributions of the active modules to the {@see FileHandler} slot (the drive's preview and
 *      download-only baseline);
 *   2. the tagged container binding 'vaulter.file_handlers' (FQCN-tagged, no closures) — the host's channel;
 *   3. configuration key vaulter.handlers — an FQCN list (config-cache safe);
 *   4. runtime registrations via Vaulter::handlers()->register()/register().
 *
 * Resolution order: mime-first → extension-fallback → capability-filter →
 * priority-sort → the DownloadOnlyHandler null-object (so a resolution NEVER returns
 * null). The registry is the SOURCE OF TRUTH for formats — a runtime-registered
 * format works with NO migration and NO core/enum change (the sealed NodeKind/
 * DocumentType enums are only built-in sugar, never a gate).
 *
 * SECURITY: routing is keyed on FileDescriptor::canonicalMime() — the
 * server-sniffed detectedMime, never the client claimedMime. A spoofed Content-Type
 * cannot force an editable handler onto hostile bytes.
 *
 * Resolutions are MEMOISED by (canonicalMime, extension, capability) so the hot path
 * (a folder listing of like files) sorts the handler set once. The cache is keyed on
 * the routing inputs only — distinct descriptors that route identically share a key
 * deterministically, never collide across capabilities.
 */
#[Internal]
final class FileHandlerRegistry implements FileHandlerRegistryContract
{
    /**
     * The merged handler set (built lazily, once). Each entry is a resolved
     * FileHandler instance.
     *
     * @var list<FileHandler>|null
     */
    private ?array $handlers = null;

    /**
     * Runtime-registered handlers (Vaulter::handlers()->register) — kept apart from the
     * config/tagged set so a runtime register after the first resolve re-primes the
     * merged set.
     *
     * @var list<FileHandler>
     */
    private array $runtime = [];

    /**
     * Memoised resolutions keyed by "mime|ext|capability".
     *
     * @var array<string, FileHandler>
     */
    private array $cache = [];

    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
        private readonly Container $container,
    ) {}

    /**
     * Register a handler at runtime (the Vaulter::handlers()->register seam). FQCN or
     * instance; an FQCN is resolved through the container. Re-priming the merged set
     * + clearing the resolution cache keeps a later resolve correct.
     *
     * @param FileHandler|class-string<FileHandler> $handler
     */
    public function register(FileHandler|string $handler): void
    {
        $this->runtime[] = $this->checkedInstance($handler);
        $this->handlers = null;
        $this->cache = [];
    }

    /**
     * Resolve the best handler for the file and the requested capability. NEVER
     * returns null — the DownloadOnlyHandler null-object is the guaranteed fallback.
     *
     * Algorithm: collect mime-matching handlers (canonical/sniffed mime,
     * exact then wildcard); if none, fall back to extension-matching handlers; keep
     * only those whose capabilities satisfy $capability; sort by priority desc; take
     * the first, else DownloadOnly.
     */
    public function resolve(FileDescriptor $file, Capability $capability = Capability::Download): FileHandler
    {
        $cacheKey = $this->cacheKey($file, $capability);

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        return $this->cache[$cacheKey] = $this->resolveUncached($file, $capability);
    }

    /**
     * Every handler in the merged set, priority desc (the introspection surface a
     * host/admin lists formats from). The DownloadOnly null-object is included as the
     * terminal entry.
     *
     * @return list<FileHandler>
     */
    public function all(): array
    {
        $handlers = $this->handlers();
        usort($handlers, $this->byPriorityDesc(...));

        return $handlers;
    }

    private function resolveUncached(FileDescriptor $file, Capability $capability): FileHandler
    {
        $handlers = $this->handlers();

        // Step 1 — mime-first (the canonical, server-sniffed mime; never claimed).
        // The bare "*" catch-all (DownloadOnly) is EXCLUDED here so it never short-
        // circuits the extension fallback — it is reached only via the null-object.
        $matched = $this->matchByMime($handlers, $file->canonicalMime());

        // Step 2 — extension-fallback when no specific mime handler matched.
        if ($matched === []) {
            $matched = $this->matchByExtension($handlers, $file->normalizedExtension());
        }

        // Step 3 — capability-filter: keep only handlers that can do what was asked.
        $capable = array_values(array_filter(
            $matched,
            static fn (FileHandler $handler): bool => $capability->satisfiedBy($handler->capabilities($file)),
        ));

        // Step 4 — priority-sort (desc); the highest-priority capable handler wins.
        usort($capable, $this->byPriorityDesc(...));

        // Step 5 — null-object fallback: DownloadOnly always satisfies Download and
        // sits at the floor priority, so a resolution is never null.
        return $capable[0] ?? $this->downloadOnly();
    }

    /**
     * Handlers whose mimeTypes claim the canonical mime — exact match preferred,
     * then a wildcard ("image/*"), then the catch-all ("*"). A null mime matches
     * only the catch-all.
     *
     * @param  list<FileHandler> $handlers
     * @return list<FileHandler>
     */
    private function matchByMime(array $handlers, ?string $mime): array
    {
        $exact = [];
        $wildcard = [];

        foreach ($handlers as $handler) {
            foreach ($handler->mimeTypes() as $claim) {
                $claim = mb_strtolower($claim);

                if ($mime !== null && $claim === $mime) {
                    $exact[] = $handler;

                    continue 2;
                }

                if ($this->wildcardMatches($claim, $mime)) {
                    $wildcard[] = $handler;

                    continue 2;
                }
            }
        }

        // Exact mime beats a wildcard family beats the catch-all; the priority sort
        // then orders within the winning tier.
        if ($exact !== []) {
            return $exact;
        }

        return $wildcard;
    }

    /**
     * Whether a "type/*" mime CLAIM covers the given concrete mime. The bare "*"
     * catch-all is DELIBERATELY excluded here: it would make the mime step match for
     * every file and short-circuit the extension fallback, so the catch-all handler
     * (DownloadOnly) is reached only through the null-object fallback, never the mime
     * tier.
     */
    private function wildcardMatches(string $claim, ?string $mime): bool
    {
        if ($mime === null || $claim === '*' || !str_ends_with($claim, '/*')) {
            return false;
        }

        $prefix = substr($claim, 0, -1); // "image/*" → "image/"

        return str_starts_with($mime, $prefix);
    }

    /**
     * Handlers whose extensions claim this extension. The catch-all DownloadOnly has
     * no extensions, so it is reached only through the null-object fallback, never
     * here.
     *
     * @param  list<FileHandler> $handlers
     * @return list<FileHandler>
     */
    private function matchByExtension(array $handlers, ?string $extension): array
    {
        if ($extension === null) {
            return [];
        }

        return array_values(array_filter(
            $handlers,
            static fn (FileHandler $handler): bool => in_array(
                $extension,
                array_map(static fn (string $ext): string => mb_strtolower($ext), $handler->extensions()),
                strict: true,
            ),
        ));
    }

    /**
     * The merged, instantiated handler set (tagged + config + runtime), built once.
     * A handler appears at most once per FQCN so the same class registered in two
     * sources does not double-resolve.
     *
     * @return list<FileHandler>
     */
    private function handlers(): array
    {
        if ($this->handlers !== null) {
            return $this->handlers;
        }

        $byClass = [];

        foreach ($this->container->make(Kernel::class)->extensions()->resolve(FileHandler::class) as $handler) {
            $this->assertKey($handler);
            $byClass[$handler::class] = $handler;
        }

        foreach ($this->taggedHandlers() as $handler) {
            $byClass[$handler::class] = $handler;
        }

        foreach ($this->vaulterConfig->handlers() as $class) {
            if (!isset($byClass[$class])) {
                $byClass[$class] = $this->checkedInstance($class);
            }
        }

        foreach ($this->runtime as $handler) {
            $byClass[$handler::class] = $handler;
        }

        return $this->handlers = array_values($byClass);
    }

    /**
     * The container-tagged handler set ('vaulter.file_handlers'). The tag is optional — a
     * stock install registers via config — so a missing tag yields an empty list.
     *
     * @return list<FileHandler>
     */
    private function taggedHandlers(): array
    {
        $tagged = [];

        /** @var iterable<object> $resolved */
        $resolved = $this->container->tagged('vaulter.file_handlers');

        foreach ($resolved as $handler) {
            if ($handler instanceof FileHandler) {
                $this->assertKey($handler);
                $tagged[] = $handler;
            }
        }

        return $tagged;
    }

    /**
     * @param FileHandler|class-string<FileHandler> $handler
     */
    private function checkedInstance(FileHandler|string $handler): FileHandler
    {
        if ($handler instanceof FileHandler) {
            $this->assertKey($handler);

            return $handler;
        }

        /** @var FileHandler $instance */
        $instance = $this->container->make($handler);

        $this->assertKey($instance);

        return $instance;
    }

    private function assertKey(FileHandler $handler): void
    {
        if (preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/D', $handler->key()) !== 1) {
            throw new \InvalidArgumentException('File handler key must have the format vendor/name.');
        }
    }

    private function downloadOnly(): DownloadOnlyHandler
    {
        return $this->container->make(DownloadOnlyHandler::class);
    }

    private function byPriorityDesc(FileHandler $a, FileHandler $b): int
    {
        return $b->priority() <=> $a->priority();
    }

    private function cacheKey(FileDescriptor $file, Capability $capability): string
    {
        return ($file->canonicalMime() ?? '').'|'.($file->normalizedExtension() ?? '').'|'.$capability->value;
    }
}

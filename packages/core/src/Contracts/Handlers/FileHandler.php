<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Handlers;

use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\HandlerCapabilities;
use Vaulter\ValueObjects\Handlers\HandlerUi;

/**
 * A pluggable document-processing handler — THE format-extensibility headline
 * (master plan §4.5 / §7 / H.5). A handler claims a set of mime types (exact or
 * wildcards like "image/*") and/or extensions, declares its capabilities and its
 * front-end mount descriptor per file, and the {@see
 * \Vaulter\Documents\Documents\DocumentHandlerRegistry} resolves the best one for a
 * FileDescriptor.
 *
 * Adding a NEW format is a ONE-CLASS + register operation: implement this interface
 * and register it (tagged container binding 'vaulter.handlers', config(
 * 'vaulter.handlers') FQCN list, or runtime Vaulter::registerHandler()). No
 * migration, no core change, NOT gated by the sealed NodeKind/DocumentType enum
 * (H.5 — the registry's string keys are the source of truth).
 *
 * Every new handler is a SemVer-frozen public API from its release (H.5).
 */
interface FileHandler
{
    /**
     * A stable, unique string id for this handler (e.g. 'tiptap', 'preview',
     * 'download', 'univer', a host's 'cad'). Surfaced to the client so it can pick
     * the editor/preview implementation; the registry never relies on an enum.
     */
    public function id(): string;

    /**
     * The mime types this handler claims. Exact ("application/pdf") or wildcard
     * ("image/*", or the catch-all "*"). Routing prefers the canonical (sniffed)
     * mime over the claimed one (FileDescriptor::canonicalMime).
     *
     * @return list<string>
     */
    public function mimeTypes(): array;

    /**
     * The lower-case extensions (without the dot) this handler claims — the
     * extension-fallback the registry uses when the mime did not match.
     *
     * @return list<string>
     */
    public function extensions(): array;

    /**
     * The resolution priority (higher wins) among handlers that match the same
     * FileDescriptor and capability. The DownloadOnlyHandler null-object sits at the
     * lowest priority so a more specific handler always beats it.
     */
    public function priority(): int;

    /**
     * What this handler can do with the described file — editable / previewable /
     * downloadable / thumbnailable / collaborative (§4.5). Computed per file so a
     * handler may, e.g., advertise editable only for a sub-format.
     */
    public function capabilities(FileDescriptor $file): HandlerCapabilities;

    /**
     * The front-end mount descriptor for the file: the Vue component + open route +
     * props the client routes the node to (§4.5 / §7).
     */
    public function ui(FileDescriptor $file): HandlerUi;
}

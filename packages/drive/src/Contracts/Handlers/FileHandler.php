<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Handlers;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\Drive\ValueObjects\Handlers\FileDescriptor;
use Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor;

/**
 * A pluggable document-processing handler — THE format-extensibility headline
 * . A handler claims a set of mime types (exact or
 * wildcards like "image/*") and/or extensions, declares its capabilities and its
 * front-end mount descriptor per file, and the {@see
 * \Vaulter\Drive\Handlers\FileHandlerRegistry} resolves the best one for a
 * FileDescriptor.
 *
 * Adding a NEW format is a ONE-CLASS + register operation: implement this interface
 * and register it (tagged container binding 'vaulter-drive.handlers', config(
 * 'vaulter-drive.handlers') FQCN list, or runtime Vaulter::handlers()->register()). No
 * migration, no core change, NOT gated by the sealed NodeKind/DocumentType enum
 * (the registry's string keys are the source of truth).
 *
 * Every new handler is a SemVer-frozen public API from its release.
 */
#[Spi(SpiKind::Implement)]
interface FileHandler
{
    /**
     * A stable, unique string id for this handler (e.g. 'vaulter/preview',
     * 'vaulter/download', a host's 'acme/cad'). Surfaced to the client so it can pick
     * the editor/preview implementation; the registry never relies on an enum.
     */
    public function key(): string;

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
     * downloadable / thumbnailable / collaborative. Computed per file so a
     * capabilities() depends only on MIME and extension, which identify the registry cache key.
     */
    public function capabilities(FileDescriptor $file): ContentCapabilities;

    /**
     * The front-end mount descriptor for the file: the Vue component + open route +
     * props the client routes the node to.
     */
    public function viewer(FileDescriptor $file): ViewerDescriptor;
}

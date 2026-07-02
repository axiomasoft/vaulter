<?php

declare(strict_types=1);

namespace Vaulter\DocDrive\Documents\Handlers;

use Vaulter\Contracts\Documents\DocumentHandler;
use Vaulter\DocDrive\ValueObjects\DocumentCapabilities;
use Vaulter\DocDrive\ValueObjects\FileDescriptor;
use Vaulter\DocDrive\ValueObjects\HandlerUi;

/**
 * The NULL-OBJECT handler (master plan §4.5): claims everything ("*") at the lowest
 * priority and offers download-only. It is the guaranteed terminal resolution — when
 * no richer handler matches a FileDescriptor, the registry falls back here so a
 * resolution NEVER returns null. Archives, unknown binaries and anything unhandled
 * land on this handler.
 */
final class DownloadOnlyHandler implements DocumentHandler
{
    /**
     * The floor priority — every real handler sits above this, so DownloadOnly only
     * ever wins when nothing else matched.
     */
    public const int PRIORITY = -1000;

    public function id(): string
    {
        return 'download';
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return ['*'];
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return [];
    }

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function capabilities(FileDescriptor $file): DocumentCapabilities
    {
        return DocumentCapabilities::downloadOnly();
    }

    public function ui(FileDescriptor $file): HandlerUi
    {
        return new HandlerUi(
            component: 'VaulterDownload',
            route: null,
            props: ['filename' => $file->filename],
        );
    }
}

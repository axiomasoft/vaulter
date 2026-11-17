<?php

declare(strict_types=1);

namespace Vaulter\Drive\Handlers\Builtin;

use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\Handlers\FileHandler;
use Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\Drive\ValueObjects\Handlers\FileDescriptor;
use Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor;

/**
 * The NULL-OBJECT handler: claims everything ("*") at the lowest
 * priority and offers download-only. It is the guaranteed terminal resolution — when
 * no richer handler matches a FileDescriptor, the registry falls back here so a
 * resolution NEVER returns null. Archives, unknown binaries and anything unhandled
 * land on this handler.
 */
#[Internal]
final class DownloadOnlyHandler implements FileHandler
{
    /**
     * The floor priority — every real handler sits above this, so DownloadOnly only
     * ever wins when nothing else matched.
     */
    public const int PRIORITY = -1000;

    public function key(): string
    {
        return 'vaulter/download';
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

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return ContentCapabilities::downloadOnly();
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        return new ViewerDescriptor(
            key: 'vaulter/download',
            props: ['filename' => $file->filename],
        );
    }
}

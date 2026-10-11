<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Testing;

use Vaulter\Handlers\AbstractFileHandler;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

/** Breaks the routing contract: its capabilities depend on the node, which the registry cache does not key on. */
final class BrokenFileHandler extends AbstractFileHandler
{
    public function key(): string
    {
        return 'acme/broken';
    }

    public function mimeTypes(): array
    {
        return ['application/x-broken'];
    }

    public function priority(): int
    {
        return 10;
    }

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return new ContentCapabilities(editable: $file->nodeId === 1);
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        return new ViewerDescriptor('acme/broken');
    }
}

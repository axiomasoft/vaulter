<?php

declare(strict_types=1);

namespace Vaulter\Testing\Contracts;

use PHPUnit\Framework\Assert;
use Vaulter\Attributes\Api;
use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\ValueObjects\Handlers\FileDescriptor;

/**
 * What a file handler must keep for the registry to route to it and cache the result:
 *
 * ```php
 * (new FileHandlerContractTests)->assertFileHandlerContract(new CadHandler);
 * ```
 *
 * Routing and its cache are keyed on the sniffed mime, the extension and the requested
 * capability only, so capabilities() may depend on nothing else.
 */
#[Api]
final class FileHandlerContractTests
{
    public function assertFileHandlerContract(FileHandler $handler, ?FileDescriptor $claimed = null): void
    {
        Assert::assertMatchesRegularExpression('/^[a-z0-9-]+\/[a-z0-9-]+$/D', $handler->key(), 'The key must be vendor/name.');
        Assert::assertSame($handler->key(), $handler->key(), 'The key must be stable.');

        $mimeTypes = $handler->mimeTypes();
        $extensions = $handler->extensions();
        Assert::assertTrue($mimeTypes !== [] || $extensions !== [], 'A handler must claim a mime type or an extension.');
        foreach ($mimeTypes as $claim) {
            Assert::assertMatchesRegularExpression('/^(\*|[a-z0-9][a-z0-9.+-]*\/(\*|[a-z0-9][a-z0-9.+-]*))$/D', $claim, 'A mime claim is lower-case "type/subtype", "type/*" or "*".');
        }
        foreach ($extensions as $extension) {
            Assert::assertMatchesRegularExpression('/^[a-z0-9]+$/D', $extension, 'An extension is lower-case, without the dot.');
        }

        $file = $claimed ?? $this->descriptorFor($mimeTypes, $extensions);
        $capabilities = $handler->capabilities($file);
        Assert::assertSame($capabilities->toArray(), $handler->capabilities($file)->toArray(), 'capabilities() must be deterministic.');

        $sameRouting = new FileDescriptor(
            nodeId: 'other-node',
            filename: 'renamed.'.($file->normalizedExtension() ?? 'bin'),
            extension: $file->extension,
            claimedMime: $file->claimedMime,
            detectedMime: $file->detectedMime,
            objectHash: 'other-hash',
            size: $file->size + 1,
        );
        Assert::assertSame($capabilities->toArray(), $handler->capabilities($sameRouting)->toArray(), 'capabilities() may depend only on the mime type and the extension: the registry caches by them.');

        $viewer = $handler->viewer($file);
        Assert::assertMatchesRegularExpression('/^[a-z0-9-]+\/[a-z0-9-]+$/D', $viewer->key, 'The viewer key must be vendor/name.');
        Assert::assertSame($viewer->toArray(), $handler->viewer($file)->toArray(), 'viewer() must be deterministic.');
        json_encode($viewer->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $mimeTypes
     * @param list<string> $extensions
     */
    private function descriptorFor(array $mimeTypes, array $extensions): FileDescriptor
    {
        $mime = null;
        foreach ($mimeTypes as $claim) {
            if ($claim !== '*') {
                $mime = str_ends_with($claim, '/*') ? substr($claim, 0, -1).'x-contract' : $claim;
                break;
            }
        }
        $extension = $extensions[0] ?? 'bin';

        return FileDescriptor::make(nodeId: 1, filename: 'contract.'.$extension, extension: $extension, detectedMime: $mime, size: 1);
    }
}

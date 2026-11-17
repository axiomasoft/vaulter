<?php

declare(strict_types=1);

use Vaulter\Drive\Contracts\Handlers\FileHandler;
use Vaulter\Drive\Enums\Handlers\Capability;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Handlers\FileHandlerRegistry;
use Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\Drive\ValueObjects\Handlers\FileDescriptor;
use Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor;

/*
 * Capabilities are an OPEN axis set — a handler that declares
 * only the flags it knows still resolves and serialises with the full, stable key set.
 * The content's SHAPE is not a capability (`richText` is gone): a document's
 * type decides it, so the client-facing array carries no `rich_text` key.
 */
it('serialises the stable capability keys and no content-shape flag', function (): void {
    expect(array_keys((new ContentCapabilities)->toArray()))
        ->toBe(['editable', 'previewable', 'downloadable', 'thumbnailable', 'collaborative']);
});

it('resolves a handler that declares only some flags — the rest keep their defaults', function (): void {
    Vaulter::handlers()->register(AxisBlindHandler::class);

    $file = FileDescriptor::make(nodeId: 1, filename: 'blind.axb', detectedMime: 'application/x-axis-blind');

    $handler = app(FileHandlerRegistry::class)->resolve($file, Capability::Edit);

    expect($handler->key())->toBe('test/axis-blind')
        ->and($handler->capabilities($file)->editable)->toBeTrue()
        ->and($handler->capabilities($file)->collaborative)->toBeFalse()
        ->and($handler->capabilities($file)->toArray())->not->toHaveKey('rich_text');
});

it('resolves the higher-priority handler when two EDITABLE handlers claim the same extension', function (): void {
    // The ONLYOFFICE-class conflict (xml routes to word OR cell by context): two
    // handlers both claim the same extension with different priorities — explicit
    // priority, not registration order, decides the winner.
    Vaulter::handlers()->register(LowPriorityRivalHandler::class);
    Vaulter::handlers()->register(HighPriorityRivalHandler::class);

    $file = FileDescriptor::make(nodeId: 1, filename: 'contested.riv');

    $handler = app(FileHandlerRegistry::class)->resolve($file, Capability::Edit);

    expect($handler->key())->toBe('test/rival-high');
});

/** Claims a brand-new extension and declares only `editable`. */
final class AxisBlindHandler implements FileHandler
{
    public function key(): string
    {
        return 'test/axis-blind';
    }

    /** @return list<string> */
    public function mimeTypes(): array
    {
        return ['application/x-axis-blind'];
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return ['axb'];
    }

    public function priority(): int
    {
        return 50;
    }

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return new ContentCapabilities(editable: true);
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        return new ViewerDescriptor(key: 'AxisBlindEditor');
    }
}

/** Same extension as {@see HighPriorityRivalHandler}, lower priority — must lose. */
final class LowPriorityRivalHandler implements FileHandler
{
    public function key(): string
    {
        return 'test/rival-low';
    }

    /** @return list<string> */
    public function mimeTypes(): array
    {
        return [];
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return ['riv'];
    }

    public function priority(): int
    {
        return 10;
    }

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return new ContentCapabilities(editable: true);
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        return new ViewerDescriptor(key: 'RivalLowEditor');
    }
}

/** Same extension as {@see LowPriorityRivalHandler}, higher priority — must win. */
final class HighPriorityRivalHandler implements FileHandler
{
    public function key(): string
    {
        return 'test/rival-high';
    }

    /** @return list<string> */
    public function mimeTypes(): array
    {
        return [];
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return ['riv'];
    }

    public function priority(): int
    {
        return 20;
    }

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return new ContentCapabilities(editable: true);
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        return new ViewerDescriptor(key: 'RivalHighEditor');
    }
}

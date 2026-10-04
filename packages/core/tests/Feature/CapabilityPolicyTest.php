<?php

declare(strict_types=1);

use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\Enums\Handlers\Capability;
use Vaulter\Facades\Vaulter;
use Vaulter\Handlers\FileHandlerRegistry;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

/*
 * VLT-CANON P0.5 (L4/L5): capabilities are an ADDITIVE, OPEN axis set — adding a
 * NEW capability flag must never break an existing handler, an existing resolution,
 * or the client-facing descriptor shape for a handler that doesn't know about it.
 * `richText` (P0.5) is itself proof: it was added to `ContentCapabilities` after
 * `editable`/`previewable`/… existed, and every pre-existing handler that never
 * mentions it still resolves and serialises correctly (defaulting to `false` —
 * kind-neutral, no claim without opt-in).
 */
it('defaults richText to false — a kind-neutral handler makes no rich-text claim', function (): void {
    $capabilities = new ContentCapabilities(editable: true);

    expect($capabilities->richText)->toBeFalse();
});

it('serialises richText into the client-facing capabilities array under a stable key', function (): void {
    expect((new ContentCapabilities)->toArray())->toHaveKey('rich_text')
        ->and((new ContentCapabilities)->toArray()['rich_text'])->toBeFalse();
});

it('resolves a handler that predates richText without declaring it — the axis is additive, not required', function (): void {
    // AxisBlindHandler never mentions richText at all; it must still resolve, and
    // its capabilities must still carry the (default) richText key downstream.
    Vaulter::handlers()->register(AxisBlindHandler::class);

    $file = FileDescriptor::make(nodeId: 1, filename: 'blind.axb', detectedMime: 'application/x-axis-blind');

    $handler = app(FileHandlerRegistry::class)->resolve($file, Capability::Edit);

    expect($handler->key())->toBe('test/axis-blind')
        ->and($handler->capabilities($file)->richText)->toBeFalse()
        ->and($handler->capabilities($file)->toArray())->toHaveKey('rich_text');
});

it('resolves the higher-priority handler when two EDITABLE handlers claim the same extension', function (): void {
    // The ONLYOFFICE-class conflict (xml routes to word OR cell by context): two
    // handlers both claim the same extension with different priorities — explicit
    // priority, not registration order, decides the winner.
    Vaulter::handlers()->register(LowPriorityRivalHandler::class);
    Vaulter::handlers()->register(HighPriorityRivalHandler::class);

    $file = FileDescriptor::make(nodeId: 1, filename: 'contested.riv', detectedMime: null);

    $handler = app(FileHandlerRegistry::class)->resolve($file, Capability::Edit);

    expect($handler->key())->toBe('test/rival-high');
});

/** Claims a brand-new extension and never mentions richText — the additive-axis proof. */
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

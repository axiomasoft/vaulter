<?php

declare(strict_types=1);

use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\Handlers\FileHandlerRegistry;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

it('rejects malformed handler keys before they can affect resolution', function (string $key): void {
    $handler = new class($key) implements FileHandler
    {
        public function __construct(private readonly string $handlerKey) {}

        public function key(): string
        {
            return $this->handlerKey;
        }

        public function mimeTypes(): array
        {
            return [];
        }

        public function extensions(): array
        {
            return [];
        }

        public function priority(): int
        {
            return 0;
        }

        public function capabilities(FileDescriptor $file): ContentCapabilities
        {
            return ContentCapabilities::downloadOnly();
        }

        public function viewer(FileDescriptor $file): ViewerDescriptor
        {
            return new ViewerDescriptor(key: 'test/viewer');
        }
    };

    $registry = app(FileHandlerRegistry::class);
    $before = $registry->all();
    expect(fn () => $registry->register($handler))->toThrow(InvalidArgumentException::class, 'vendor/name');
    expect($registry->all())->toBe($before);
})->with(['Preview', 'preview', 'vendor/name/more', 'Vendor/name', "vendor/name\n", 'vendor/']);

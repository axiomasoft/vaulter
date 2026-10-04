<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Handlers;

use Vaulter\Enums\Handlers\Capability;
use Vaulter\ValueObjects\Handlers\FileDescriptor;

/** The shared registry for configured, tagged and runtime file handlers. */
interface FileHandlerRegistry
{
    /** @param FileHandler|class-string<FileHandler> $handler */
    public function register(FileHandler|string $handler): void;

    public function resolve(FileDescriptor $file, Capability $capability = Capability::Download): FileHandler;

    /** @return list<FileHandler> */
    public function all(): array;
}

<?php

declare(strict_types=1);

namespace Vaulter\Contracts;

/**
 * Pluggable storage for binary attachments. Vaulter itself only stores JSON
 * content; binary files (images, etc.) are delegated to an implementation of
 * this contract.
 */
interface StorageAdapter
{
    /**
     * Persist the contents and return a public URL.
     *
     * @param string|resource $contents
     */
    public function store(string $filename, mixed $contents): string;

    public function delete(string $url): void;

    public function url(string $path): string;
}

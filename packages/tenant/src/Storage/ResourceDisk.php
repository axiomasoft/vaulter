<?php

declare(strict_types=1);

namespace Vaulter\Tenant\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Tenant\Contracts\TrackedStream;
use Tenant\Laravel\Contracts\ResourceFilesystem;

/** Reuses the P5.1 public filesystem adapter; reads expose a lease-backed sequential PHP stream. */
final readonly class ResourceDisk implements Filesystem
{
    public function __construct(private ResourceFilesystem $filesystem) {}

    public function path($path)
    {
        return $this->filesystem->path($path);
    }

    public function exists($path)
    {
        return $this->filesystem->exists($path);
    }

    public function get($path)
    {
        return $this->filesystem->get($path);
    }

    public function readStream($path)
    {
        return TrackedReadStream::open($this->filesystem->readStream($path));
    }

    public function trackedReadStream(string $path): TrackedStream
    {
        return $this->filesystem->readStream($path);
    }

    public function put($path, $contents, $options = [])
    {
        return $this->filesystem->put($path, $contents, $options);
    }

    public function putFile($path, $file = null, $options = [])
    {
        return $this->filesystem->putFile($path, $file, $options);
    }

    public function putFileAs($path, $file, $name = null, $options = [])
    {
        return $this->filesystem->putFileAs($path, $file, $name, $options);
    }

    public function writeStream($path, $resource, array $options = [])
    {
        return $this->filesystem->writeStream($path, $resource, $options);
    }

    public function getVisibility($path)
    {
        return $this->filesystem->getVisibility($path);
    }

    public function setVisibility($path, $visibility)
    {
        return $this->filesystem->setVisibility($path, $visibility);
    }

    public function prepend($path, $data)
    {
        return $this->filesystem->prepend($path, $data);
    }

    public function append($path, $data)
    {
        return $this->filesystem->append($path, $data);
    }

    public function delete($paths)
    {
        return $this->filesystem->delete($paths);
    }

    public function copy($from, $to)
    {
        return $this->filesystem->copy($from, $to);
    }

    public function move($from, $to)
    {
        return $this->filesystem->move($from, $to);
    }

    public function size($path)
    {
        return $this->filesystem->size($path);
    }

    public function lastModified($path)
    {
        return $this->filesystem->lastModified($path);
    }

    public function files($directory = null, $recursive = false)
    {
        return $this->filesystem->files($directory, $recursive);
    }

    public function allFiles($directory = null)
    {
        return $this->filesystem->allFiles($directory);
    }

    public function directories($directory = null, $recursive = false)
    {
        return $this->filesystem->directories($directory, $recursive);
    }

    public function allDirectories($directory = null)
    {
        return $this->filesystem->allDirectories($directory);
    }

    public function makeDirectory($path)
    {
        return $this->filesystem->makeDirectory($path);
    }

    public function deleteDirectory($directory)
    {
        return $this->filesystem->deleteDirectory($directory);
    }
}

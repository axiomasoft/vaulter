<?php

declare(strict_types=1);

namespace Vaulter\Database\Factories;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Drive\CreateFolderOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;

/**
 * A folder or a file created through FolderOperations / FileOperations, so a file
 * comes with its first revision, its blob and its scan obligation.
 *
 * ```php
 * $file = Node::factory()->file('hello')->in($drive)->create();
 * ```
 *
 * @extends VaulterFactory<Node>
 */
final class NodeFactory extends VaulterFactory
{
    protected $model = Node::class;

    private static int $sequence = 0;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $n = ++self::$sequence;

        return ['name' => $this->stateString('kind', 'folder') === 'file' ? "file-{$n}.txt" : "folder-{$n}"];
    }

    public function folder(): static
    {
        return $this->withVaulter(['kind' => 'folder']);
    }

    /** A file whose first revision holds $bytes. */
    public function file(string $bytes = 'fixture', ?string $mimeType = 'text/plain'): static
    {
        return $this->withVaulter(['kind' => 'file', 'bytes' => $bytes, 'mime' => $mimeType]);
    }

    /** Create under a drive's root, or inside a folder. */
    public function in(Drive|Node $container): static
    {
        return $this->withVaulter(['container' => $container]);
    }

    #[\Override]
    protected function persist(Model $draft): Model
    {
        $container = $this->stateObject('container', Model::class) ?? throw new LogicException('A node needs a place: Node::factory()->in($drive|$folder)->create().');
        $parent = $container instanceof Node ? $container : null;
        $owner = $container instanceof Node ? $container->storage : $container;
        $handle = $this->system($owner);
        $name = $draft instanceof Node ? $draft->name : throw new LogicException('A node factory makes nodes.');

        $snapshot = $this->stateString('kind', 'folder') === 'file'
            ? $handle->files()->upload(UploadSource::fromString($this->stateText('bytes', ''), $name), new UploadOptions(parent: $parent?->id, declaredMimeType: $this->stateString('mime')))
            : $handle->folders()->create($name, $parent?->id, new CreateFolderOptions);

        $class = app(VaulterConfig::class)->model('node');

        return $this->withTenant(static fn (): Model => $class::query()->whereKey($snapshot->id)->firstOrFail());
    }
}

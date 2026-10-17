<?php

declare(strict_types=1);

namespace Vaulter\Drive\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Vaulter\Attributes\Api;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Data\RevisionOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;

/**
 * A new head revision of a file, added through FileOperations::addRevision().
 *
 * ```php
 * $revision = FileRevision::factory()->for($file)->bytes('v2')->create();
 * ```
 *
 * @extends VaulterFactory<FileRevision>
 */
#[Api]
final class FileRevisionFactory extends VaulterFactory
{
    protected $model = FileRevision::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }

    /**
     * The file to add a revision to. Any other relation falls through to Eloquent.
     *
     * @param  Factory<Model>|Model $factory
     * @param  string|null          $relationship
     * @return static
     */
    #[\Override]
    public function for($factory, $relationship = null)
    {
        if ($factory instanceof Node) {
            return $this->withVaulter(['file' => $factory]);
        }

        return parent::for($factory, $relationship);
    }

    /** Add the revision to a new file in this drive. */
    public function in(Drive $drive): static
    {
        return $this->withVaulter(['drive' => $drive]);
    }

    public function bytes(string $bytes, ?string $mimeType = 'text/plain'): static
    {
        return $this->withVaulter(['bytes' => $bytes, 'mime' => $mimeType]);
    }

    #[\Override]
    protected function persist(Model $draft): Model
    {
        $file = $this->stateObject('file', Node::class);
        if ($file === null) {
            $drive = $this->stateObject('drive', Drive::class) ?? throw new LogicException('A revision needs its file: FileRevision::factory()->for($file)->create().');
            $file = NodeFactory::new()->file()->in($drive)->createOne();
        }

        $snapshot = $this->system($file->storage)->files()->addRevision(
            $file->id,
            UploadSource::fromString($this->stateText('bytes', 'revision'), $file->name),
            new RevisionOptions(declaredMimeType: $this->stateString('mime', 'text/plain')),
        );

        $class = app(VaulterConfig::class)->model('file_revision');

        return $this->withTenant(static fn (): Model => $class::query()->whereKey($snapshot->id)->firstOrFail());
    }
}

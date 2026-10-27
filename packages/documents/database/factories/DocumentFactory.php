<?php

declare(strict_types=1);

namespace Vaulter\Documents\Database\Factories;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Database\Factories\VaulterFactory;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;

/**
 * A document created through DocumentOperations::create(), so its type, codec,
 * first checkpoint and node come from the same path as a host's request.
 *
 * ```php
 * $document = Document::factory()->richText('Hello')->in($drive)->create();
 * ```
 *
 * @extends VaulterFactory<Document>
 */
#[Internal]
final class DocumentFactory extends VaulterFactory
{
    protected $model = Document::class;

    private static int $sequence = 0;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }

    /** A rich-text document with one paragraph of $text. */
    public function richText(string $text = 'fixture'): static
    {
        return $this->withVaulter([
            'type' => RichTextDocumentType::KEY,
            'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]],
        ]);
    }

    /** A document of $type holding that type's default content. */
    public function ofType(string $type): static
    {
        return $this->withVaulter(['type' => $type, 'content' => null]);
    }

    public function named(string $name): static
    {
        return $this->withVaulter(['name' => $name]);
    }

    /** Create under a drive's root, or inside a folder. */
    public function in(Drive|Node $container): static
    {
        return $this->withVaulter(['container' => $container]);
    }

    #[\Override]
    protected function persist(Model $draft): Model
    {
        $container = $this->stateObject('container', Model::class) ?? throw new LogicException('A document needs a place: Document::factory()->in($drive|$folder)->create().');
        $parent = $container instanceof Node ? $container : null;
        $node = Documents::in($this->system($container instanceof Node ? $container->storage : $container))->create(
            name: $this->stateString('name') ?? 'document-'.++self::$sequence,
            type: $this->stateString('type'),
            content: $this->stateArray('content'),
            parent: $parent?->id,
        );

        return $this->withTenant(static fn (): Model => Document::query()->where('node_id', $node->id)->firstOrFail());
    }
}

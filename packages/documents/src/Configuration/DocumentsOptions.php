<?php

declare(strict_types=1);

namespace Vaulter\Documents\Configuration;

use Illuminate\Database\Eloquent\Model;
use Override;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;

/**
 * Immutable compiled configuration snapshot for `vaulter-documents`.
 */
#[Api(since: '1.0')]
final readonly class DocumentsOptions implements OptionsFactory
{
    /**
     * @param list<string> $types
     * @param list<string> $exporters
     */
    public function __construct(
        public array $types = [RichTextDocumentType::class, MarkdownDocumentType::class],
        public string $defaultType = 'vaulter/rich-text',
        public DocumentsCollaborationOptions $collaboration = new DocumentsCollaborationOptions,
        public DocumentsContentOptions $content = new DocumentsContentOptions,
        public DocumentsRevisionsOptions $revisions = new DocumentsRevisionsOptions,
        public DocumentsCommentsOptions $comments = new DocumentsCommentsOptions,
        public array $exporters = [HtmlExporter::class, MarkdownExporter::class],
        public TipTapOptions $tiptap = new TipTapOptions,
        public DocumentsBroadcastOptions $broadcast = new DocumentsBroadcastOptions,
        public DocumentsModelsOptions $models = new DocumentsModelsOptions,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter-documents'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, [
            'types',
            'default_type',
            'collaboration',
            'content',
            'revisions',
            'comments',
            'exporters',
            'tiptap',
            'broadcast',
            'models',
        ], $path);

        $types = self::parseClassList($config['types'] ?? [], $path.'.types', 'document type classes', $validator);
        $rawDefaultType = $config['default_type'] ?? 'vaulter/rich-text';
        $defaultType = is_string($rawDefaultType) && $rawDefaultType !== '' ? $rawDefaultType : 'vaulter/rich-text';
        $collaboration = DocumentsCollaborationOptions::fromArray($config['collaboration'] ?? [], $path.'.collaboration', $validator);
        $content = DocumentsContentOptions::fromArray($config['content'] ?? [], $path.'.content', $validator);
        $revisions = DocumentsRevisionsOptions::fromArray($config['revisions'] ?? [], $path.'.revisions', $validator);
        $comments = DocumentsCommentsOptions::fromArray($config['comments'] ?? [], $path.'.comments', $validator);
        $exporters = array_values(array_unique(self::parseClassList($config['exporters'] ?? [], $path.'.exporters', 'document exporter classes', $validator)));
        $tiptap = TipTapOptions::fromArray($config['tiptap'] ?? [], $path.'.tiptap', $validator);
        $broadcast = DocumentsBroadcastOptions::fromArray($config['broadcast'] ?? [], $path.'.broadcast', $validator);
        $models = DocumentsModelsOptions::fromArray($config['models'] ?? [], $path.'.models', $validator);

        $validator->failIfAny();

        return new self(
            types: $types,
            defaultType: $defaultType,
            collaboration: $collaboration,
            content: $content,
            revisions: $revisions,
            comments: $comments,
            exporters: $exporters,
            tiptap: $tiptap,
            broadcast: $broadcast,
            models: $models,
        );
    }

    /** @return ($key is 'node_comment' ? class-string<NodeComment> : ($key is 'document_revision' ? class-string<DocumentRevision> : class-string<Model>)) */
    #[Internal]
    public function model(string $key): string
    {
        return $this->models->classFor($key);
    }

    /** @return list<string> */
    private static function parseClassList(mixed $raw, string $path, string $label, ConfigurationValidator $validator): array
    {
        if (!is_array($raw)) {
            $validator->add($path, sprintf('[Vaulter] %s must be a list of %s.', $path, $label));

            return [];
        }

        $classes = [];
        foreach ($raw as $class) {
            if (!is_string($class)) {
                $validator->add($path, sprintf('[Vaulter] %s must contain class names only (closures cannot be cached).', $path));

                return [];
            }
            $classes[] = $class;
        }

        return $classes;
    }
}

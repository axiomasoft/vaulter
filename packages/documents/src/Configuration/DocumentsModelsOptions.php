<?php

declare(strict_types=1);

namespace Vaulter\Documents\Configuration;

use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;

/**
 * Immutable compiled swappable Eloquent model classes for `vaulter-documents.models.*`.
 */
#[Api(since: '1.0')]
final readonly class DocumentsModelsOptions
{
    /** @var array<string, class-string<Model>> */
    private const array DEFAULTS = [
        'document_revision' => DocumentRevision::class,
        'node_comment' => NodeComment::class,
    ];

    /**
     * @param class-string<DocumentRevision> $documentRevision
     * @param class-string<NodeComment>      $nodeComment
     */
    #[Internal]
    public function __construct(
        #[Internal]
        public string $documentRevision = DocumentRevision::class,
        #[Internal]
        public string $nodeComment = NodeComment::class,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, array_keys(self::DEFAULTS), $path);

        /** @var array<string, class-string<Model>> $resolved */
        $resolved = [];
        foreach (self::DEFAULTS as $key => $base) {
            $class = $raw[$key] ?? $base;
            if (!is_string($class) || $class === '' || !class_exists($class) || !is_a($class, $base, true)) {
                $validator->add($path.'.'.$key, '[Vaulter] Model '.$path.'.'.$key.' must be a class extending '.$base.'.');
                $resolved[$key] = $base;

                continue;
            }
            $resolved[$key] = $class;
        }

        /**
         * @var class-string<DocumentRevision> $documentRevision
         * @var class-string<NodeComment>      $nodeComment
         */
        [
            'document_revision' => $documentRevision,
            'node_comment' => $nodeComment,
        ] = $resolved;

        return new self(
            documentRevision: $documentRevision,
            nodeComment: $nodeComment,
        );
    }

    /** @return ($key is 'node_comment' ? class-string<NodeComment> : ($key is 'document_revision' ? class-string<DocumentRevision> : class-string<Model>)) */
    #[Internal]
    public function classFor(string $key): string
    {
        return match ($key) {
            'node_comment' => $this->nodeComment,
            'document_revision' => $this->documentRevision,
            default => throw InvalidConfiguration::forMessage('[Vaulter] Unknown documents model key: '.$key),
        };
    }
}

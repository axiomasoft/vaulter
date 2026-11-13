<?php

declare(strict_types=1);

namespace Vaulter\Documents\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\StringList;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

#[Api]
final readonly class DocumentsConfig
{
    #[Internal]
    public function __construct(private Repository $config) {}

    public function collaborationSessionTtl(): int
    {
        $value = $this->config->get('vaulter-documents.collaboration.session_ttl', 300);
        if (!is_int($value) || $value < 1 || $value > 300) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-documents.collaboration.session_ttl must be an integer between 1 and 300.');
        }

        return $value;
    }

    public function revisionLimit(): int
    {
        $value = $this->config->get(key: 'vaulter-documents.revisions.limit', default: 50);

        return is_numeric($value) ? (int) $value : 50;
    }

    /**
     * How long the working state may run ahead of its last autosave checkpoint.
     * 0 checkpoints every effective write.
     */
    public function checkpointIntervalSeconds(): int
    {
        $value = $this->config->get(key: 'vaulter-documents.revisions.checkpoint_interval_seconds', default: 300);

        $seconds = is_numeric($value) ? (int) $value : 300;

        return max($seconds, 0);
    }

    public function maxContentSizeKb(): int
    {
        $value = $this->config->get(key: 'vaulter-documents.content.max_size_kb', default: 5120);

        return is_numeric($value) ? (int) $value : 5120;
    }

    /**
     * The document type classes registered from config.
     *
     * @return list<string>
     */
    public function types(): array
    {
        $value = $this->config->get(key: 'vaulter-documents.types', default: []);

        if (!is_array($value)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-documents.types must be a list of document type classes.');
        }

        foreach ($value as $class) {
            if (!is_string($class)) {
                throw new InvalidConfigurationException('[Vaulter] vaulter-documents.types must contain class names only (closures cannot be cached).');
            }
        }

        /** @var list<string> */
        return array_values($value);
    }

    /** The type DocumentOperations::create() uses when none is named. */
    public function defaultType(): string
    {
        $value = $this->config->get(key: 'vaulter-documents.default_type', default: 'vaulter/rich-text');

        return is_string($value) && $value !== '' ? $value : 'vaulter/rich-text';
    }

    /**
     * The prefix of the private broadcast channels of a document: the comment events broadcast on
     * "<prefix>.<nodeId>", and the collaboration package names its rooms and authorizes the channel with the same prefix.
     */
    public function channelPrefix(): string
    {
        $value = $this->config->get(key: 'vaulter-documents.broadcast.channel_prefix', default: 'vaulter.documents');

        return is_string($value) ? $value : 'vaulter.documents';
    }

    /**
     * The document exporter classes registered from config, in order.
     *
     * @return list<string>
     */
    public function exporters(): array
    {
        $value = $this->config->get(key: 'vaulter-documents.exporters', default: []);

        if (!is_array($value)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-documents.exporters must be a list of document exporter classes.');
        }

        $classes = [];
        foreach ($value as $class) {
            if (!is_string($class)) {
                throw new InvalidConfigurationException('[Vaulter] vaulter-documents.exporters must contain class names only (closures cannot be cached).');
            }
            $classes[] = $class;
        }

        return array_values(array_unique($classes));
    }

    /** @return list<string> */
    public function tiptapExtraNodes(): array
    {
        return StringList::from($this->config->get(key: 'vaulter-documents.tiptap.extra_nodes', default: []), []);
    }

    /** @return list<string> */
    public function tiptapExtraMarks(): array
    {
        return StringList::from($this->config->get(key: 'vaulter-documents.tiptap.extra_marks', default: []), []);
    }

    /** @return array<string, list<string>> */
    public function tiptapExtraAttributes(): array
    {
        return $this->tiptapAttributeMap('extra_attributes');
    }

    /** @return array<string, list<string>> */
    public function tiptapUrlAttributes(): array
    {
        return $this->tiptapAttributeMap('url_attributes');
    }

    /** @return array<string, list<string>> */
    private function tiptapAttributeMap(string $key): array
    {
        $value = $this->config->get(key: 'vaulter-documents.tiptap.'.$key, default: []);

        if (!is_array($value)) {
            return [];
        }

        $attributes = [];

        foreach ($value as $type => $keys) {
            if (!is_string($type)) {
                continue;
            }
            if (!is_array($keys)) {
                continue;
            }
            $attributes[$type] = array_values(array_filter($keys, is_string(...)));
        }

        return $attributes;
    }

    public function commentsEnabled(): bool
    {
        $value = $this->config->get(key: 'vaulter-documents.comments.enabled', default: true);

        return is_bool($value) ? $value : true;
    }

    /** @return ($key is 'node_comment' ? class-string<NodeComment> : class-string<DocumentRevision>) */
    #[Internal]
    public function model(string $key): string
    {
        if ($key === 'node_comment') {
            return $this->configuredModel(key: $key, base: NodeComment::class);
        }

        if ($key === 'document_revision') {
            return $this->configuredModel(key: $key, base: DocumentRevision::class);
        }

        throw new InvalidConfigurationException('[Vaulter] Unknown documents model key: '.$key);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T> $base
     * @return class-string<T>
     */
    private function configuredModel(string $key, string $base): string
    {
        $class = $this->config->get(key: 'vaulter-documents.models.'.$key, default: $base);

        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] Model vaulter-documents.models.'.$key.' must be a class name.');
        }

        ConfigValidator::assertModel(key: 'vaulter-documents.models.'.$key, class: $class, base: $base);

        return $class;
    }
}

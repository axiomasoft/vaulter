<?php

declare(strict_types=1);

namespace Vaulter\Documents\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Contracts\Handlers\DocumentExporter;
use Vaulter\Contracts\Profiles\CommentsFeature;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Support\Identity;

final readonly class DocumentsConfig implements CommentsFeature
{
    public function __construct(private Repository $config) {}

    public function revisionLimit(): int
    {
        $value = $this->config->get(key: 'vaulter-documents.revisions.limit', default: 50);

        return is_numeric($value) ? (int) $value : 50;
    }

    public function autosaveDebounceSeconds(): int
    {
        $value = $this->config->get(key: 'vaulter-documents.revisions.autosave_debounce_seconds', default: 30);

        $seconds = is_numeric($value) ? (int) $value : 30;

        return max($seconds, 0);
    }

    public function maxContentSizeKb(): int
    {
        $value = $this->config->get(key: 'vaulter-documents.content.max_size_kb', default: 5120);

        return is_numeric($value) ? (int) $value : 5120;
    }

    /** @return list<string> */
    public function enabledDocumentTypes(): array
    {
        return $this->stringList($this->config->get(key: 'vaulter.enabled_document_types', default: []), []);
    }

    public function collaborationDriver(): string
    {
        $value = $this->config->get(key: 'vaulter-documents.collaboration.driver', default: 'hocuspocus');

        return is_string($value) ? $value : 'hocuspocus';
    }

    public function channelPrefix(): string
    {
        $value = $this->config->get(key: 'vaulter-documents.collaboration.broadcast.channel_prefix', default: 'vaulter.documents');

        return is_string($value) ? $value : 'vaulter.documents';
    }

    public function collaborationRoom(mixed $nodeId): string
    {
        return $this->channelPrefix().'.'.Identity::stringify($nodeId);
    }

    public function nodeIdFromRoom(string $room): string
    {
        $prefix = $this->channelPrefix().'.';

        if (str_starts_with($room, $prefix)) {
            return substr($room, strlen($prefix));
        }

        return $room;
    }

    public function hubWebhookSecret(): ?string
    {
        $value = $this->config->get('vaulter-documents.collaboration.hub.webhook_secret');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hubTimestampTolerance(): int
    {
        $value = $this->config->get(key: 'vaulter-documents.collaboration.hub.timestamp_tolerance', default: 300);

        $seconds = is_numeric($value) ? (int) $value : 300;

        return max($seconds, 1);
    }

    public function hubJwtSecret(): ?string
    {
        $value = $this->config->get('vaulter-documents.collaboration.hub.jwt_secret');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hubTokenTtl(): int
    {
        $value = $this->config->get(key: 'vaulter-documents.collaboration.hub.token_ttl', default: 3600);

        return is_numeric($value) ? (int) $value : 3600;
    }

    public function hubUrl(): ?string
    {
        $value = $this->config->get('vaulter-documents.collaboration.hub.url');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, class-string<DocumentExporter>> */
    public function exporters(): array
    {
        $value = $this->config->get(key: 'vaulter-documents.exporters', default: []);

        if (!is_array($value)) {
            return [];
        }

        $exporters = [];

        foreach ($value as $format => $class) {
            if (is_string($format) && is_string($class) && is_a($class, DocumentExporter::class, allow_string: true)) {
                $exporters[$format] = $class;
            }
        }

        /** @var array<string, class-string<DocumentExporter>> $exporters */
        return $exporters;
    }

    /** @return list<string> */
    public function tiptapExtraNodes(): array
    {
        return $this->stringList($this->config->get(key: 'vaulter-documents.tiptap.extra_nodes', default: []), []);
    }

    /** @return list<string> */
    public function tiptapExtraMarks(): array
    {
        return $this->stringList($this->config->get(key: 'vaulter-documents.tiptap.extra_marks', default: []), []);
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

    /**
     * @param  list<string> $default
     * @return list<string>
     */
    private function stringList(mixed $value, array $default): array
    {
        if (!is_array($value)) {
            return $default;
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    public function enabled(): bool
    {
        return $this->commentsEnabled();
    }

    public function commentsEnabled(): bool
    {
        $value = $this->config->get(key: 'vaulter-documents.comments.enabled', default: true);

        return is_bool($value) ? $value : true;
    }

    public function collaborationEnabled(): bool
    {
        $value = $this->config->get(key: 'vaulter-documents.collaboration.enabled', default: false);

        return is_bool($value) ? $value : false;
    }

    public function spreadsheetsEnabled(): bool
    {
        $value = $this->config->get(key: 'vaulter.features.spreadsheets', default: false);

        return is_bool($value) ? $value : false;
    }

    /** @return ($key is 'node_comment' ? class-string<NodeComment> : class-string<DocumentRevision>) */
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

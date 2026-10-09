<?php

declare(strict_types=1);

namespace Vaulter\Documents\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Contracts\Profiles\CommentsFeature;
use Vaulter\Documents\Data\Collaboration\RoomRef;
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

    /** @throws InvalidConfigurationException for the removed reverb driver */
    public function collaborationDriver(): string
    {
        $value = $this->config->get(key: 'vaulter-documents.collaboration.driver', default: 'hocuspocus');

        // Reverb could not persist Yjs state server-side, so a room could never be
        // fenced off after a restore; failing loudly beats silently switching transport.
        if ($value === 'reverb') {
            throw new InvalidConfigurationException(
                '[Vaulter] The reverb collaboration driver was removed; set vaulter-documents.collaboration.driver to hocuspocus. '
                .'Docs: https://github.com/axiomasoft/vaulter/blob/main/docs/guide/collaboration.md',
            );
        }

        return is_string($value) ? $value : 'hocuspocus';
    }

    public function channelPrefix(): string
    {
        $value = $this->config->get(key: 'vaulter-documents.collaboration.broadcast.channel_prefix', default: 'vaulter.documents');

        return is_string($value) ? $value : 'vaulter.documents';
    }

    /**
     * The room of one generation of a document: "<prefix>.<driveId>.<nodeId>.<epoch>".
     * The epoch is part of the name, so a room opened before a restore can never be
     * confused with the one opened after it.
     */
    public function collaborationRoom(mixed $driveId, mixed $nodeId, int $epoch): string
    {
        return $this->channelPrefix().'.'.Identity::stringify($driveId).'.'.Identity::stringify($nodeId).'.'.$epoch;
    }

    /** The parts of a room name, or null when it is not exactly one this installation issues. */
    public function parseRoom(string $room): ?RoomRef
    {
        $prefix = $this->channelPrefix().'.';
        if (!str_starts_with($room, $prefix)) {
            return null;
        }

        $parts = explode('.', substr($room, strlen($prefix)));
        if (count($parts) !== 3) {
            return null;
        }

        [$driveId, $nodeId, $epoch] = $parts;
        if ($driveId === '' || $nodeId === '' || !ctype_digit($epoch) || ($epoch !== '0' && str_starts_with($epoch, '0'))) {
            return null;
        }

        $generation = filter_var($epoch, FILTER_VALIDATE_INT);

        return $generation === false ? null : new RoomRef(driveId: $driveId, nodeId: $nodeId, epoch: $generation);
    }

    /** The largest binary Yjs state a collaboration store may write. */
    public function collaborationMaxStateBytes(): int
    {
        $value = $this->config->get(key: 'vaulter-documents.collaboration.max_state_bytes', default: 16777216);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 16777216;
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

    /**
     * Rights are re-read only on the hub's next authenticate/load/store, so a token
     * may not outlive an hour.
     *
     * @throws InvalidConfigurationException outside 1..3600 seconds
     */
    public function hubTokenTtl(): int
    {
        $value = $this->config->get(key: 'vaulter-documents.collaboration.hub.token_ttl', default: 3600);
        $seconds = is_numeric($value) ? (int) $value : 3600;

        if ($seconds < 1 || $seconds > 3600) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-documents.collaboration.hub.token_ttl must be between 1 and 3600 seconds.');
        }

        return $seconds;
    }

    public function hubUrl(): ?string
    {
        $value = $this->config->get('vaulter-documents.collaboration.hub.url');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The document exporter classes registered from config. The 0.2 map of
     * format => class is still read (its classes, in order) with a deprecation.
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
        $legacyMap = false;
        foreach ($value as $key => $class) {
            if (!is_string($class)) {
                throw new InvalidConfigurationException('[Vaulter] vaulter-documents.exporters must contain class names only (closures cannot be cached).');
            }
            $legacyMap = $legacyMap || is_string($key);
            $classes[] = $class;
        }
        if ($legacyMap) {
            trigger_error('[Vaulter] vaulter-documents.exporters as a map of format => class is deprecated; list the exporter classes instead.', E_USER_DEPRECATED);
        }

        return array_values(array_unique($classes));
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

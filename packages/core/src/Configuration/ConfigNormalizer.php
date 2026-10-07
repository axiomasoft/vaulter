<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Profiles\ProfileProvider;

final class ConfigNormalizer
{
    /** @var array<string, string> */
    private array $defaultPaths = [];

    /** @var array<string, array<string, mixed>> */
    private array $defaults = [];

    /** @var array<string, true> */
    private array $warned = [];

    public function registerDefaults(string $root, string $path): void
    {
        if (($this->defaultPaths[$root] ?? null) !== $path) {
            unset($this->defaults[$root]);
        }
        $this->defaultPaths[$root] = $path;
    }

    /** @return array<string, array<string, mixed>> */
    public function packageDefaults(): array
    {
        $defaults = [];
        foreach ($this->defaultPaths as $root => $path) {
            $this->defaults[$root] ??= $this->loadDefaults($path);
            $defaults[$root] = $this->defaults[$root];
        }

        return $defaults;
    }

    /** @param array<string, array<string, mixed>> $packageDefaults */
    public function normalize(Repository $config, array $packageDefaults): void
    {
        $original = $this->stringKeyedEntries($config->all());
        $defaults = $this->stringKeyedEntries(Arr::dot($packageDefaults));

        foreach (self::keyMap() as $legacy => $canonical) {
            $root = explode('.', $canonical)[0];
            if (!array_key_exists($root, $packageDefaults) || !Arr::has($original, $legacy) || isset($this->warned[$legacy])) {
                continue;
            }

            $value = $this->translate(Arr::get($original, $legacy));
            if ($legacy === 'vaulter.dedup.scope' && $value === 'storage') {
                $value = 'drive';
            }
            if ($legacy === 'vaulter.id_type') {
                $value = Arr::get($original, 'vaulter.ids.default') ?: ($value === 'ulid' ? 'ulid' : 'bigint');
            }
            if ($legacy === 'vaulter.max_versions') {
                $value = Arr::get($original, 'vaulter.versions.max', $value);
            }
            if (in_array($legacy, ['vaulter.share_token_generator', 'vaulter.share.token_generator'], true)) {
                $top = $this->translate(Arr::get($original, 'vaulter.share_token_generator'));
                $nested = $this->translate(Arr::get($original, 'vaulter.share.token_generator'));
                $default = Arr::get($packageDefaults, $canonical);
                if ($top !== null && $nested !== null && $top !== $default && $nested !== $default && $top !== $nested) {
                    throw new InvalidConfigurationException('[Vaulter] Conflicting legacy sharing token keys "vaulter.share_token_generator" and "vaulter.share.token_generator".');
                }
                $value = $nested !== null && $nested !== $default ? $nested : ($top ?? $nested);
            }
            if (in_array($legacy, ['vaulter.uploads.tus.enabled', 'vaulter.features.uploads'], true)) {
                $value = (bool) Arr::get($original, 'vaulter.uploads.tus.enabled', true)
                    && (bool) Arr::get($original, 'vaulter.features.uploads', true);
            }
            if (in_array($legacy, ['vaulter.panels', 'vaulter.default_panel'], true)) {
                foreach (is_array($value) ? $value : [$value] as $class) {
                    if (!is_string($class) || !is_a($class, ProfileProvider::class, true)) {
                        throw new InvalidConfigurationException('[Vaulter] Config key "'.$legacy.'" must contain ProfileProvider classes; run php artisan vaulter:make:profile.');
                    }
                }
            }

            $canonicalValue = $this->translate(Arr::get($original, $canonical));
            $hasOverride = Arr::has($original, $canonical)
                && (!Arr::has($packageDefaults, $canonical) || $canonicalValue !== Arr::get($packageDefaults, $canonical));
            if ($hasOverride && $canonicalValue !== $value) {
                throw new InvalidConfigurationException('[Vaulter] Conflicting config keys "'.$legacy.'" and "'.$canonical.'".');
            }
            $config->set($canonical, $value);
            $this->deprecate($legacy, $canonical);
            if ($legacy === 'vaulter.middleware') {
                Log::warning('[Vaulter] Public route middleware now belongs to vaulter.routes.middleware.public.');
            }
        }

        // Unchanged legacy handler keys still contain renamed built-in class strings.
        if (Arr::has($original, 'vaulter.handlers')) {
            $config->set('vaulter.handlers', $this->translate(Arr::get($original, 'vaulter.handlers')));
        }

        if (array_key_exists('vaulter-drive', $packageDefaults)) {
            $this->normalizeUploadLimit($config, $original, $defaults);
        }
        // The autosave debounce rewrote the head revision in place; checkpoints are
        // immutable now and taken on their own interval, so its value has no meaning
        // to carry over to checkpoint_interval_seconds. extra_data is gone (PLAN2.D41)
        // and a name's length is part of the profile's name policy (PLAN2.D39/D43).
        foreach (['vaulter.tenant.column', 'vaulter.migrations.path', 'vaulter.features.blobs', 'vaulter.features.versions', 'vaulter.features.document_versions', 'vaulter.versions.autosave_debounce_seconds', 'vaulter-documents.revisions.autosave_debounce_seconds', 'vaulter.drive.name_rules.no_separators_pattern', 'vaulter.extra_data.enabled', 'vaulter.drive.name_rules.max_length', 'vaulter-drive.names.max_length'] as $key) {
            $owner = match ($key) {
                'vaulter.features.document_versions', 'vaulter.versions.autosave_debounce_seconds', 'vaulter-documents.revisions.autosave_debounce_seconds' => 'vaulter-documents',
                'vaulter.drive.name_rules.no_separators_pattern', 'vaulter.drive.name_rules.max_length', 'vaulter-drive.names.max_length' => 'vaulter-drive',
                default => 'vaulter',
            };
            if (array_key_exists($owner, $packageDefaults) && Arr::has($original, $key) && !isset($this->warned[$key])) {
                $this->warned[$key] = true;
                trigger_error('[Vaulter] Config key "'.$key.'" is deprecated and ignored.', E_USER_DEPRECATED);
            }
        }
        // Preserve replacement maps/lists. Exporter formats retain their existing additive defaults.
        $arrayKeys = ['vaulter.ids.strategies', 'vaulter.handlers', 'vaulter.enabled_document_types'];
        foreach (self::keyMap() as $canonical) {
            if ($canonical !== 'vaulter-documents.exporters' && is_array($config->get($canonical))) {
                $arrayKeys[] = $canonical;
            }
        }
        foreach ($defaults as $key => $value) {
            $insideConfiguredArray = false;
            foreach ($arrayKeys as $arrayKey) {
                if ($config->has($arrayKey) && str_starts_with($key, $arrayKey.'.')) {
                    $insideConfiguredArray = true;
                    break;
                }
            }
            if (!$insideConfiguredArray && !$config->has($key)) {
                $config->set($key, $value);
            }
        }
    }

    /** @return array<string, string> */
    public static function keyMap(): array
    {
        return [
            'vaulter.connection' => 'vaulter.database.connection',
            'vaulter.table_prefix' => 'vaulter.database.table_prefix',
            'vaulter.id_type' => 'vaulter.ids.strategy',
            'vaulter.ids.default' => 'vaulter.ids.strategy',
            'vaulter.tenant.enabled' => 'vaulter.tenancy.enabled',
            'vaulter.panels' => 'vaulter.profiles.providers',
            'vaulter.default_panel' => 'vaulter.profiles.default',
            'vaulter.features.reliability' => 'vaulter.features.webhooks',
            'vaulter.drivers.permission' => 'vaulter.access.permission_driver',
            'vaulter.permission_drivers' => 'vaulter.access.permission_drivers',
            'vaulter.gate.abilities' => 'vaulter.access.gate.abilities',
            'vaulter.gate.super_ability' => 'vaulter.access.gate.super_ability',
            'vaulter.gate.create_ability' => 'vaulter.access.gate.create_ability',
            'vaulter.spatie.permissions' => 'vaulter.access.spatie.permissions',
            'vaulter.spatie.super_permission' => 'vaulter.access.spatie.super_permission',
            'vaulter.spatie.create_permission' => 'vaulter.access.spatie.create_permission',
            'vaulter.spatie.teams' => 'vaulter.access.spatie.teams',
            'vaulter.spatie.role_subject_type' => 'vaulter.access.spatie.role_subject_type',
            'vaulter.spatie.team_subject_type' => 'vaulter.access.spatie.team_subject_type',
            'vaulter.azgard.panel_id' => 'vaulter-azgard.panel_id',
            'vaulter.azgard.permissions' => 'vaulter-azgard.permissions',
            'vaulter.azgard.create_permission' => 'vaulter-azgard.create_permission',
            'vaulter.route_prefix' => 'vaulter.routes.prefix',
            'vaulter.middleware' => 'vaulter.routes.middleware.authenticated',
            'vaulter.rate_limits.public.per_minute' => 'vaulter.routes.rate_limits.public',
            'vaulter.rate_limits.uploads.per_minute' => 'vaulter.routes.rate_limits.uploads',
            'vaulter.share_token_generator' => 'vaulter.sharing.token_generator',
            'vaulter.share.token_generator' => 'vaulter.sharing.token_generator',
            'vaulter.share.url_base' => 'vaulter.sharing.url_base',
            'vaulter.share.prefix_length' => 'vaulter.sharing.prefix_length',
            'vaulter.share.default_ttl_hours' => 'vaulter.sharing.default_ttl_hours',
            'vaulter.share.max_ttl_hours' => 'vaulter.sharing.max_ttl_hours',
            'vaulter.downloads.inline_mime_whitelist' => 'vaulter.downloads.inline_mime_types',
            'vaulter.storage.default_disk' => 'vaulter-media.disk',
            'vaulter.dedup.scope' => 'vaulter-media.dedup.scope',
            'vaulter.gc.grace_hours' => 'vaulter-media.gc.grace_hours',
            'vaulter.gc.orphan_grace_hours' => 'vaulter-media.gc.orphan_grace_hours',
            'vaulter.cas.content_store' => 'vaulter-media.content_store',
            'vaulter.cas.storage_driver' => 'vaulter-media.storage_driver',
            'vaulter.cas.blob_store' => 'vaulter-media.blob_store',
            'vaulter.drive.allowed_mime' => 'vaulter-drive.uploads.allowed_mime_types',
            'vaulter.uploads.chunk_size' => 'vaulter-drive.uploads.resumable.chunk_size',
            'vaulter.uploads.max_chunk_bytes' => 'vaulter-drive.uploads.resumable.max_chunk_size',
            'vaulter.uploads.session_ttl_hours' => 'vaulter-drive.uploads.resumable.session_ttl_hours',
            'vaulter.uploads.max_active_sessions' => 'vaulter-drive.uploads.resumable.max_active_sessions_per_actor',
            'vaulter.uploads.tus.enabled' => 'vaulter-drive.uploads.resumable.enabled',
            'vaulter.features.uploads' => 'vaulter-drive.uploads.resumable.enabled',
            'vaulter.uploads.metadata.allowed_keys' => 'vaulter-drive.uploads.resumable.metadata.allowed_keys',
            'vaulter.uploads.metadata.max_value_length' => 'vaulter-drive.uploads.resumable.metadata.max_value_length',
            'vaulter.trash.retention_days' => 'vaulter-drive.trash.retention_days',
            'vaulter.max_document_size_kb' => 'vaulter-documents.content.max_size_kb',
            'vaulter.versions.max' => 'vaulter-documents.revisions.limit',
            'vaulter.max_versions' => 'vaulter-documents.revisions.limit',
            'vaulter.features.comments' => 'vaulter-documents.comments.enabled',
            'vaulter.features.collaboration' => 'vaulter-documents.collaboration.enabled',
            'vaulter.collaboration.driver' => 'vaulter-documents.collaboration.driver',
            'vaulter.collaboration.channel_prefix' => 'vaulter-documents.collaboration.broadcast.channel_prefix',
            'vaulter.collaboration.webhook_secret' => 'vaulter-documents.collaboration.hub.webhook_secret',
            'vaulter.collaboration.webhook_timestamp_tolerance' => 'vaulter-documents.collaboration.hub.timestamp_tolerance',
            'vaulter.collaboration.drivers.hocuspocus.url' => 'vaulter-documents.collaboration.hub.url',
            'vaulter.collaboration.drivers.hocuspocus.app_id' => 'vaulter-documents.collaboration.hub.app_id',
            'vaulter.collaboration.drivers.hocuspocus.secret' => 'vaulter-documents.collaboration.hub.jwt_secret',
            'vaulter.collaboration.drivers.hocuspocus.token_ttl' => 'vaulter-documents.collaboration.hub.token_ttl',
            'vaulter.tiptap.extra_nodes' => 'vaulter-documents.tiptap.extra_nodes',
            'vaulter.tiptap.extra_marks' => 'vaulter-documents.tiptap.extra_marks',
            'vaulter.tiptap.extra_attributes' => 'vaulter-documents.tiptap.extra_attributes',
            'vaulter.tiptap.url_attributes' => 'vaulter-documents.tiptap.url_attributes',
            'vaulter.exporters' => 'vaulter-documents.exporters',
            'vaulter.models.storage' => 'vaulter.models.drive',
            'vaulter.models.file_version' => 'vaulter.models.file_revision',
            'vaulter.models.file_version_blob' => 'vaulter.models.file_revision_blob',
            'vaulter.models.upload_session' => 'vaulter-drive.models.upload_session',
            'vaulter.models.upload_part' => 'vaulter-drive.models.upload_part',
            'vaulter.models.node_comment' => 'vaulter-documents.models.node_comment',
            'vaulter.models.node_document_version' => 'vaulter-documents.models.document_revision',
            'vaulter-filament.resources.storage' => 'vaulter-filament.resources.drive',
        ];
    }

    /** @return array<string, mixed> */
    private function loadDefaults(string $path): array
    {
        $values = require $path;
        if (!is_array($values)) {
            throw new InvalidConfigurationException('[Vaulter] Package config file "'.$path.'" must return an array.');
        }
        $defaults = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidConfigurationException('[Vaulter] Package config file "'.$path.'" must use string root keys.');
            }
            $defaults[$key] = $value;
        }

        return $defaults;
    }

    private function deprecate(string $legacy, string $canonical): void
    {
        $this->warned[$legacy] = true;
        trigger_error('[Vaulter] Config key "'.$legacy.'" is deprecated; use "'.$canonical.'".', E_USER_DEPRECATED);
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed> $defaults
     */
    private function normalizeUploadLimit(Repository $config, array $original, array $defaults): void
    {
        $values = [];
        foreach (['vaulter.uploads.max_bytes' => 1, 'vaulter.drive.max_upload_kb' => 1024] as $key => $multiplier) {
            if (Arr::has($original, $key) && !isset($this->warned[$key])) {
                if (is_numeric(Arr::get($original, $key))) {
                    $values[] = max(0, (int) Arr::get($original, $key)) * $multiplier;
                }
                $this->deprecate($key, 'vaulter-drive.uploads.max_file_size');
            }
        }
        if ($values === []) {
            return;
        }
        $canonical = 'vaulter-drive.uploads.max_file_size';
        if (Arr::has($original, $canonical) && Arr::get($original, $canonical) !== ($defaults[$canonical] ?? null)) {
            $limit = Arr::get($original, $canonical);
            if (is_numeric($limit)) {
                $values[] = (int) $limit;
            }
        }
        if (count(array_unique($values)) > 1) {
            Log::warning('[Vaulter] Conflicting upload limits; using the smaller finite limit.');
        }
        $finite = array_values(array_filter($values, static fn (int $value): bool => $value > 0));
        $config->set($canonical, $finite === [] ? 0 : min($finite));
    }

    /**
     * @param  array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private function stringKeyedEntries(array $values): array
    {
        $entries = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $entries[$key] = $value;
            }
        }

        return $entries;
    }

    private function translate(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->translate(...), $value);
        }
        if (!is_string($value)) {
            return $value;
        }

        return match ($value) {
            'Vaulter\\Models\\Drive\\Storage' => 'Vaulter\\Models\\Drive\\Drive',
            'Vaulter\\Models\\Drive\\FileVersion' => 'Vaulter\\Models\\Drive\\FileRevision',
            'Vaulter\\Models\\Drive\\FileVersionBlob' => 'Vaulter\\Models\\Drive\\FileRevisionBlob',
            'Vaulter\\Drivers\\Permission\\DatabasePermissionResolver' => 'Vaulter\\Access\\Drivers\\DatabasePermissionDriver',
            'Vaulter\\Drivers\\Permission\\GatePermissionResolver' => 'Vaulter\\Access\\Drivers\\GatePermissionDriver',
            'Vaulter\\Drivers\\Permission\\SpatiePermissionResolver' => 'Vaulter\\Access\\Drivers\\SpatiePermissionDriver',
            'Vaulter\\Drivers\\Permission\\AzgardPermissionResolver' => 'Vaulter\\Azgard\\AzgardPermissionDriver',
            'Vaulter\\Testing\\NullRoleDriver' => 'Vaulter\\Testing\\NullPermissionDriver',
            'Vaulter\\Engines\\Handlers\\PreviewHandler' => 'Vaulter\\Handlers\\Builtin\\PreviewHandler',
            'Vaulter\\Engines\\Handlers\\DownloadOnlyHandler' => 'Vaulter\\Handlers\\Builtin\\DownloadOnlyHandler',
            default => $value,
        };
    }
}

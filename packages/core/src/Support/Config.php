<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Vaulter\Contracts\DocumentExporter;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Contracts\ShareTokenGenerator;
use Vaulter\Contracts\StorageAdapter;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentComment;
use Vaulter\Models\DocumentFolder;
use Vaulter\Models\DocumentPermission;
use Vaulter\Models\DocumentShare;
use Vaulter\Models\DocumentVersion;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
final class Config
{
    /**
     * @return class-string<Document>
     */
    public static function documentModel(): string
    {
        /** @var class-string<Document> $class */
        $class = config('vaulter.models.document', Document::class);

        return $class;
    }

    /**
     * @return class-string<DocumentVersion>
     */
    public static function versionModel(): string
    {
        /** @var class-string<DocumentVersion> $class */
        $class = config('vaulter.models.version', DocumentVersion::class);

        return $class;
    }

    /**
     * @return class-string<DocumentFolder>
     */
    public static function folderModel(): string
    {
        /** @var class-string<DocumentFolder> $class */
        $class = config('vaulter.models.folder', DocumentFolder::class);

        return $class;
    }

    /**
     * @return class-string<DocumentPermission>
     */
    public static function permissionModel(): string
    {
        /** @var class-string<DocumentPermission> $class */
        $class = config('vaulter.models.permission', DocumentPermission::class);

        return $class;
    }

    /**
     * @return class-string<DocumentComment>
     */
    public static function commentModel(): string
    {
        /** @var class-string<DocumentComment> $class */
        $class = config('vaulter.models.comment', DocumentComment::class);

        return $class;
    }

    /**
     * @return class-string<DocumentShare>
     */
    public static function shareModel(): string
    {
        /** @var class-string<DocumentShare> $class */
        $class = config('vaulter.models.share', DocumentShare::class);

        return $class;
    }

    public static function idType(): string
    {
        $value = config('vaulter.id_type', 'id');

        return is_string($value) ? $value : 'id';
    }

    /**
     * The active id-strategy key (ulid|uuid7|bigint), default ulid (D9).
     *
     * Backward-compat: when ids.default is not explicitly set, derive it from
     * the legacy id_type (id->bigint, ulid->ulid). When both are present,
     * ids.default wins.
     */
    public static function idStrategy(): string
    {
        $explicit = config('vaulter.ids.default');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return self::idStrategyFromLegacyType();
    }

    /**
     * @return array<string, class-string<IdStrategy>>
     */
    public static function idStrategies(): array
    {
        $value = config('vaulter.ids.strategies', []);

        if (!is_array($value)) {
            return [];
        }

        $strategies = [];

        foreach ($value as $key => $class) {
            if (is_string($key) && is_string($class) && is_a($class, IdStrategy::class, allow_string: true)) {
                $strategies[$key] = $class;
            }
        }

        /** @var array<string, class-string<IdStrategy>> $strategies */
        return $strategies;
    }

    /**
     * Legacy id_type values still drive published migrations and host models.
     * usesUlids() reflects the resolved strategy so it keeps working whether the
     * host configures id_type=ulid or the newer ids.default=ulid.
     */
    public static function usesUlids(): bool
    {
        return self::idStrategy() === 'ulid';
    }

    /**
     * Map the legacy id_type (id|ulid) onto an id-strategy key.
     */
    private static function idStrategyFromLegacyType(): string
    {
        return self::idType() === 'ulid' ? 'ulid' : 'bigint';
    }

    public static function extraDataEnabled(): bool
    {
        return (bool) config('vaulter.extra_data.enabled', true);
    }

    public static function tenantEnabled(): bool
    {
        return (bool) config('vaulter.tenant.enabled', false);
    }

    public static function tenantColumn(): string
    {
        $value = config('vaulter.tenant.column', 'tenant_id');

        return is_string($value) ? $value : 'tenant_id';
    }

    public static function tablePrefix(): string
    {
        $value = config('vaulter.table_prefix', 'v_');

        return is_string($value) ? $value : 'v_';
    }

    /**
     * Resolve a Vaulter table name (configured prefix + base name).
     */
    public static function table(string $name): string
    {
        return self::tablePrefix().$name;
    }

    public static function routePrefix(): string
    {
        $value = config('vaulter.route_prefix', 'api/vaulter');

        return is_string($value) ? $value : 'api/vaulter';
    }

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return self::stringList(config('vaulter.middleware', ['api']), ['api']);
    }

    public static function maxVersions(): int
    {
        $value = config('vaulter.max_versions', 50);

        return is_numeric($value) ? (int) $value : 50;
    }

    public static function maxDocumentSizeKb(): int
    {
        $value = config('vaulter.max_document_size_kb', 5120);

        return is_numeric($value) ? (int) $value : 5120;
    }

    /**
     * @return list<string>
     */
    public static function enabledDocumentTypes(): array
    {
        return self::stringList(config('vaulter.enabled_document_types', []), []);
    }

    public static function collaborationDriverName(): string
    {
        $value = config('vaulter.collaboration.driver', 'reverb');

        return is_string($value) ? $value : 'reverb';
    }

    public static function channelPrefix(): string
    {
        $value = config('vaulter.collaboration.channel_prefix', 'vaulter.documents');

        return is_string($value) ? $value : 'vaulter.documents';
    }

    /**
     * @return array<string, mixed>
     */
    public static function driverConfig(string $name): array
    {
        $value = config('vaulter.collaboration.drivers.'.$name);

        /** @var array<string, mixed> $config */
        $config = is_array($value) ? $value : [];

        return $config;
    }

    /**
     * @return class-string|null
     */
    public static function permissionResolverClass(): ?string
    {
        $class = config('vaulter.permission_resolver');

        if (!is_string($class) || $class === '') {
            return null;
        }

        /** @var class-string $class */
        return $class;
    }

    /**
     * @return class-string<StorageAdapter>
     */
    public static function storageAdapterClass(): string
    {
        /** @var class-string<StorageAdapter> $class */
        $class = config('vaulter.storage_adapter', DefaultStorageAdapter::class);

        return $class;
    }

    /**
     * @return class-string<ShareTokenGenerator>
     */
    public static function shareTokenGeneratorClass(): string
    {
        /** @var class-string<ShareTokenGenerator> $class */
        $class = config('vaulter.share_token_generator', RandomShareTokenGenerator::class);

        return $class;
    }

    /**
     * @return array<string, class-string<DocumentExporter>>
     */
    public static function exporters(): array
    {
        $value = config('vaulter.exporters', []);

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

    /**
     * @param  list<string> $default
     * @return list<string>
     */
    private static function stringList(mixed $value, array $default): array
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
}

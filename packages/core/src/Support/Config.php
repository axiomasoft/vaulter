<?php

declare(strict_types=1);

namespace DocVault\Support;

use DocVault\Contracts\StorageAdapter;
use DocVault\Models\Document;
use DocVault\Models\DocumentComment;
use DocVault\Models\DocumentFolder;
use DocVault\Models\DocumentPermission;
use DocVault\Models\DocumentShare;
use DocVault\Models\DocumentVersion;

/**
 * Typed accessors over config('docvault.*'). All package code reads configuration
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
        $class = config('docvault.models.document', Document::class);

        return $class;
    }

    /**
     * @return class-string<DocumentVersion>
     */
    public static function versionModel(): string
    {
        /** @var class-string<DocumentVersion> $class */
        $class = config('docvault.models.version', DocumentVersion::class);

        return $class;
    }

    /**
     * @return class-string<DocumentFolder>
     */
    public static function folderModel(): string
    {
        /** @var class-string<DocumentFolder> $class */
        $class = config('docvault.models.folder', DocumentFolder::class);

        return $class;
    }

    /**
     * @return class-string<DocumentPermission>
     */
    public static function permissionModel(): string
    {
        /** @var class-string<DocumentPermission> $class */
        $class = config('docvault.models.permission', DocumentPermission::class);

        return $class;
    }

    /**
     * @return class-string<DocumentComment>
     */
    public static function commentModel(): string
    {
        /** @var class-string<DocumentComment> $class */
        $class = config('docvault.models.comment', DocumentComment::class);

        return $class;
    }

    /**
     * @return class-string<DocumentShare>
     */
    public static function shareModel(): string
    {
        /** @var class-string<DocumentShare> $class */
        $class = config('docvault.models.share', DocumentShare::class);

        return $class;
    }

    public static function idType(): string
    {
        $value = config('docvault.id_type', 'id');

        return is_string($value) ? $value : 'id';
    }

    public static function usesUlids(): bool
    {
        return self::idType() === 'ulid';
    }

    public static function tenantEnabled(): bool
    {
        return (bool) config('docvault.tenant.enabled', false);
    }

    public static function tenantColumn(): string
    {
        $value = config('docvault.tenant.column', 'tenant_id');

        return is_string($value) ? $value : 'tenant_id';
    }

    public static function tablePrefix(): string
    {
        $value = config('docvault.table_prefix', 'dv_');

        return is_string($value) ? $value : 'dv_';
    }

    /**
     * Resolve a DocVault table name (configured prefix + base name).
     */
    public static function table(string $name): string
    {
        return self::tablePrefix().$name;
    }

    public static function routePrefix(): string
    {
        $value = config('docvault.route_prefix', 'api/docvault');

        return is_string($value) ? $value : 'api/docvault';
    }

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return self::stringList(config('docvault.middleware', ['api']), ['api']);
    }

    public static function maxVersions(): int
    {
        $value = config('docvault.max_versions', 50);

        return is_numeric($value) ? (int) $value : 50;
    }

    public static function maxDocumentSizeKb(): int
    {
        $value = config('docvault.max_document_size_kb', 5120);

        return is_numeric($value) ? (int) $value : 5120;
    }

    /**
     * @return list<string>
     */
    public static function enabledDocumentTypes(): array
    {
        return self::stringList(config('docvault.enabled_document_types', []), []);
    }

    public static function collaborationDriverName(): string
    {
        $value = config('docvault.collaboration.driver', 'reverb');

        return is_string($value) ? $value : 'reverb';
    }

    public static function channelPrefix(): string
    {
        $value = config('docvault.collaboration.channel_prefix', 'docvault.documents');

        return is_string($value) ? $value : 'docvault.documents';
    }

    /**
     * @return array<string, mixed>
     */
    public static function driverConfig(string $name): array
    {
        $value = config('docvault.collaboration.drivers.'.$name);

        /** @var array<string, mixed> $config */
        $config = is_array($value) ? $value : [];

        return $config;
    }

    /**
     * @return class-string|null
     */
    public static function permissionResolverClass(): ?string
    {
        $class = config('docvault.permission_resolver');

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
        $class = config('docvault.storage_adapter', DefaultStorageAdapter::class);

        return $class;
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

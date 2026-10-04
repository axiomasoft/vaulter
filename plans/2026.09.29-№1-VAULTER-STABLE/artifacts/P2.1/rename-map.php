<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

$root = dirname(__DIR__, 4);

return RectorConfig::configure()
    ->withPaths(array_merge(
        glob($root.'/packages/*/src'),
        glob($root.'/packages/*/tests'),
        glob($root.'/packages/*/config'),
        glob($root.'/packages/*/database'),
        glob($root.'/packages/*/routes'),
        [$root.'/tests/Pest.php'],
    ))
    ->withImportNames(importDocBlockNames: true, importShortClasses: false)
    ->withoutParallel()
    ->withConfiguredRule(RenameClassRector::class, [
        'Vaulter\\Models\\Drive\\Storage' => 'Vaulter\\Models\\Drive\\Drive',
        'Vaulter\\Models\\Drive\\FileVersion' => 'Vaulter\\Models\\Drive\\FileRevision',
        'Vaulter\\Models\\Drive\\FileVersionBlob' => 'Vaulter\\Models\\Drive\\FileRevisionBlob',
        'Vaulter\\Panels\\Panel' => 'Vaulter\\Profiles\\Profile',
        'Vaulter\\Panels\\StoragePanelProvider' => 'Vaulter\\Profiles\\ProfileProvider',
        'Vaulter\\Panels\\DefaultStoragePanel' => 'Vaulter\\Profiles\\DefaultProfile',
        'Vaulter\\Panels\\PanelRegistry' => 'Vaulter\\Profiles\\ProfileRegistry',
        'Vaulter\\Schema\\PanelTablesBuilder' => 'Vaulter\\Schema\\SchemaBuilder',
        'Vaulter\\Support\\VaulterSchema' => 'Vaulter\\Schema\\VaulterSchema',
        'Vaulter\\Console\\MakePanelCommand' => 'Vaulter\\Console\\MakeProfileCommand',
        'Vaulter\\Console\\RelayOutboxCommand' => 'Vaulter\\Console\\RelayWebhooksCommand',
        'Vaulter\\Contracts\\Access\\HostRoleDriver' => 'Vaulter\\Contracts\\Access\\PermissionDriver',
        'Vaulter\\Access\\HostRoleDriverManager' => 'Vaulter\\Access\\PermissionDriverManager',
        'Vaulter\\Testing\\NullRoleDriver' => 'Vaulter\\Testing\\NullPermissionDriver',
        'Vaulter\\Exceptions\\Access\\MissingHostRoleDriverException' => 'Vaulter\\Exceptions\\Access\\MissingPermissionDriverException',
        'Vaulter\\Access\\ValueObjects\\AclScope' => 'Vaulter\\Access\\ValueObjects\\AccessScope',
        'Vaulter\\Access\\Abilities\\NodeAbilities' => 'Vaulter\\Access\\ValueObjects\\NodeAbilities',
        'Vaulter\\Contracts\\Handlers\\HandlerRegistry' => 'Vaulter\\Contracts\\Handlers\\FileHandlerRegistry',
        'Vaulter\\Contracts\\Handlers\\NodeHandlerResolver' => 'Vaulter\\Contracts\\Handlers\\NodePresenter',
        'Vaulter\\Engines\\DocumentHandlerRegistry' => 'Vaulter\\Handlers\\FileHandlerRegistry',
        'Vaulter\\Engines\\NodeHandlerResolver' => 'Vaulter\\Handlers\\NodePresenter',
        'Vaulter\\Engines\\DocumentFileDescriptorFactory' => 'Vaulter\\Handlers\\FileDescriptorFactory',
        'Vaulter\\Engines\\Handlers\\AbstractFileHandler' => 'Vaulter\\Handlers\\AbstractFileHandler',
        'Vaulter\\Engines\\Handlers\\PreviewHandler' => 'Vaulter\\Handlers\\Builtin\\PreviewHandler',
        'Vaulter\\Engines\\Handlers\\DownloadOnlyHandler' => 'Vaulter\\Handlers\\Builtin\\DownloadOnlyHandler',
        'Vaulter\\ValueObjects\\Handlers\\HandlerUi' => 'Vaulter\\ValueObjects\\Handlers\\ViewerDescriptor',
        'Vaulter\\ValueObjects\\Handlers\\HandlerDescriptor' => 'Vaulter\\ValueObjects\\Handlers\\NodePresentation',
        'Vaulter\\ValueObjects\\Handlers\\HandlerCapabilities' => 'Vaulter\\ValueObjects\\Handlers\\ContentCapabilities',
        'Vaulter\\Exceptions\\Support\\TenantIsolationNotConfiguredException' => 'Vaulter\\Exceptions\\Tenancy\\TenancyMisconfiguredException',
        'Vaulter\\Drivers\\Permission\\DatabasePermissionResolver' => 'Vaulter\\Access\\Drivers\\DatabasePermissionDriver',
        'Vaulter\\Drivers\\Permission\\GatePermissionResolver' => 'Vaulter\\Access\\Drivers\\GatePermissionDriver',
        'Vaulter\\Drivers\\Permission\\SpatiePermissionResolver' => 'Vaulter\\Access\\Drivers\\SpatiePermissionDriver',
        'Vaulter\\Drivers\\Permission\\AzgardPermissionResolver' => 'Vaulter\\Access\\Drivers\\AzgardPermissionDriver',
        'Vaulter\\Exceptions\\Panels\\InvalidPanelException' => 'Vaulter\\Exceptions\\Profiles\\InvalidProfileException',
        'Vaulter\\Exceptions\\Panels\\UnknownPanelException' => 'Vaulter\\Exceptions\\Profiles\\UnknownProfileException',
        'Vaulter\\Repositories\\Drive\\StorageReadRepository' => 'Vaulter\\Repositories\\Drive\\DriveReadRepository',
        'Vaulter\\Repositories\\Drive\\StorageStoreRepository' => 'Vaulter\\Repositories\\Drive\\DriveStoreRepository',
        'Vaulter\\Repositories\\Drive\\FileVersionReadRepository' => 'Vaulter\\Repositories\\Drive\\FileRevisionReadRepository',
        'Vaulter\\Repositories\\Drive\\FileVersionStoreRepository' => 'Vaulter\\Repositories\\Drive\\FileRevisionStoreRepository',
        'Vaulter\\Repositories\\Drive\\FileVersionBlobStoreRepository' => 'Vaulter\\Repositories\\Drive\\FileRevisionBlobStoreRepository',
        'Vaulter\\Enums\\Activity\\ActivityVerb' => 'Vaulter\\Enums\\Events\\EventType',
        'Vaulter\\Enums\\Webhooks\\WebhookEvent' => 'Vaulter\\Enums\\Events\\EventType',
        'Vaulter\\Data\\Access\\GrantNodeAccessData' => 'Vaulter\\Data\\Access\\GrantNodeAccessInput',
        'Vaulter\\Data\\Sharing\\CreateShareLinkData' => 'Vaulter\\Data\\Sharing\\ShareLinkInput',
        'Vaulter\\Data\\Webhooks\\RegisterWebhookEndpointData' => 'Vaulter\\Data\\Webhooks\\RegisterWebhookEndpointInput',
        'Vaulter\\Data\\Webhooks\\UpdateWebhookEndpointData' => 'Vaulter\\Data\\Webhooks\\UpdateWebhookEndpointInput',
    ]);

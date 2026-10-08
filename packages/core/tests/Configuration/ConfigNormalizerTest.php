<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Models\Drive\Drive;
use Vaulter\Profiles\DefaultProfile;
use Vaulter\Support\RandomShareTokenGenerator;
use Vaulter\VaulterServiceProvider;

/** @param Closure(): void $callback @return list<string> */
function collectConfigurationDeprecations(Closure $callback): array
{
    $messages = [];
    set_error_handler(static function (int $severity, string $message) use (&$messages): bool {
        if ($severity === E_USER_DEPRECATED) {
            $messages[] = $message;

            return true;
        }

        return false;
    });
    try {
        $callback();
    } finally {
        restore_error_handler();
    }

    return $messages;
}

it('normalizes each legacy key and emits a deprecation once', function (string $legacy, string $canonical, mixed $value, mixed $expected): void {
    $config = new Repository;
    $config->set($legacy, $value);
    $root = explode('.', $canonical)[0];
    $normalizer = new ConfigNormalizer;
    $messages = collectConfigurationDeprecations(static function () use ($normalizer, $config, $root): void {
        $normalizer->normalize($config, [$root => []]);
        $normalizer->normalize($config, [$root => []]);
    });
    expect($config->get($canonical))->toBe($expected)
        ->and($messages)->toBe(['[Vaulter] Config key "'.$legacy.'" is deprecated; use "'.$canonical.'".']);
})->with('legacy configuration keys');

it('rejects different canonical and legacy values', function (string $legacy, string $canonical, mixed $value, mixed $expected): void {
    $config = new Repository;
    $config->set($legacy, $value);
    $config->set($canonical, '__conflicting_canonical__');
    $normalizer = new ConfigNormalizer;
    $normalizer->normalize($config, [explode('.', $canonical)[0] => []]);
})->with('legacy configuration keys')->throws(InvalidConfigurationException::class, 'Conflicting config keys');

it('warns without changing keys owned by absent packages', function (): void {
    $config = new Repository(['vaulter' => ['dedup' => ['scope' => 'storage']]]);
    $messages = collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, ['vaulter' => []]));
    expect($messages)->toBe([])->and($config->get('vaulter.dedup.scope'))->toBe('storage')
        ->and($config->has('vaulter-media.dedup.scope'))->toBeFalse();
});

it('preserves ids.default priority over id_type in the published config', function (): void {
    $config = new Repository(['vaulter' => ['id_type' => 'id', 'ids' => ['default' => 'ulid']]]);
    collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, ['vaulter' => []]));
    expect($config->get('vaulter.ids.strategy'))->toBe('ulid');
});

it('translates shipped legacy model class strings while preserving custom classes', function (): void {
    $config = new Repository(['vaulter' => ['models' => ['storage' => 'Vaulter\\Models\\Drive\\Storage', 'file_version' => 'Host\\CustomRevision']]]);
    collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, ['vaulter' => []]));
    expect($config->get('vaulter.models.drive'))->toBe(Drive::class)
        ->and($config->get('vaulter.models.file_revision'))->toBe('Host\\CustomRevision');
});

it('rejects legacy panel classes with the scaffold hint', function (): void {
    (new ConfigNormalizer)->normalize(new Repository(['vaulter' => ['panels' => [stdClass::class]]]), ['vaulter' => []]);
})->throws(InvalidConfigurationException::class, 'run php artisan vaulter:make:profile');

it('uses the smaller finite upload limit after converting kilobytes', function (): void {
    Log::shouldReceive('warning')->once()->with('[Vaulter] Conflicting upload limits; using the smaller finite limit.');
    $config = new Repository(['vaulter' => ['uploads' => ['max_bytes' => 10240], 'drive' => ['max_upload_kb' => 5]]]);
    $messages = collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, ['vaulter-drive' => []]));
    expect($config->get('vaulter-drive.uploads.max_file_size'))->toBe(5120)->and($messages)->toHaveCount(2);
});

it('treats zero upload limits as unlimited when taking the smaller finite limit', function (): void {
    $config = new Repository(['vaulter' => ['uploads' => ['max_bytes' => 0], 'drive' => ['max_upload_kb' => 5]]]);
    collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, ['vaulter-drive' => []]));
    expect($config->get('vaulter-drive.uploads.max_file_size'))->toBe(5120);
});

it('disables resumable uploads when either legacy flag is false', function (bool $uploads, bool $tus): void {
    $config = new Repository(['vaulter' => ['features' => ['uploads' => $uploads], 'uploads' => ['tus' => ['enabled' => $tus]]]]);
    collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, ['vaulter-drive' => []]));
    expect($config->get('vaulter-drive.uploads.resumable.enabled'))->toBeFalse();
})->with([[false, true], [true, false], [false, false]]);

it('deprecates ignored removed keys once', function (string $key, mixed $value, string $root): void {
    $config = new Repository;
    $config->set($key, $value);
    $normalizer = new ConfigNormalizer;
    $messages = collectConfigurationDeprecations(static function () use ($config, $normalizer, $root): void {
        $normalizer->normalize($config, [$root => []]);
        $normalizer->normalize($config, [$root => []]);
    });
    expect($messages)->toBe(['[Vaulter] Config key "'.$key.'" is deprecated and ignored.']);
})->with([
    ['vaulter.tenant.column', 'custom_tenant', 'vaulter'],
    ['vaulter.migrations.path', 'legacy_path', 'vaulter'],
    ['vaulter.features.blobs', false, 'vaulter'],
    ['vaulter.features.versions', false, 'vaulter'],
    ['vaulter.features.document_versions', false, 'vaulter-documents'],
    // Checkpoints replaced the in-place autosave debounce; its value means nothing now.
    ['vaulter.versions.autosave_debounce_seconds', 30, 'vaulter-documents'],
    ['vaulter-documents.revisions.autosave_debounce_seconds', 30, 'vaulter-documents'],
    ['vaulter.drive.name_rules.no_separators_pattern', '/custom/', 'vaulter-drive'],
    ['vaulter.drive.name_rules.max_length', 100, 'vaulter-drive'],
    ['vaulter-drive.names.max_length', 100, 'vaulter-drive'],
    ['vaulter.extra_data.enabled', false, 'vaulter'],
]);

// The expected mapping is a fixed acceptance dataset, independent of the normalizer table.
dataset('legacy configuration keys', [
    'vaulter.connection' => ['vaulter.connection', 'vaulter.database.connection', 'custom', 'custom'],
    'vaulter.table_prefix' => ['vaulter.table_prefix', 'vaulter.database.table_prefix', 'custom', 'custom'],
    'vaulter.id_type' => ['vaulter.id_type', 'vaulter.ids.strategy', 'ulid', 'ulid'],
    'vaulter.ids.default' => ['vaulter.ids.default', 'vaulter.ids.strategy', 'custom', 'custom'],
    'vaulter.tenant.enabled' => ['vaulter.tenant.enabled', 'vaulter.tenancy.enabled', false, false],
    'vaulter.panels' => ['vaulter.panels', 'vaulter.profiles.providers', [DefaultProfile::class], [DefaultProfile::class]],
    'vaulter.default_panel' => ['vaulter.default_panel', 'vaulter.profiles.default', DefaultProfile::class, DefaultProfile::class],
    'vaulter.features.reliability' => ['vaulter.features.reliability', 'vaulter.features.webhooks', false, false],
    'vaulter.drivers.permission' => ['vaulter.drivers.permission', 'vaulter.access.permission_driver', 'custom', 'custom'],
    'vaulter.permission_drivers' => ['vaulter.permission_drivers', 'vaulter.access.permission_drivers', ['custom' => 'Host\\CustomClass'], ['custom' => 'Host\\CustomClass']],
    'vaulter.gate.abilities' => ['vaulter.gate.abilities', 'vaulter.access.gate.abilities', ['custom' => 'Host\\CustomClass'], ['custom' => 'Host\\CustomClass']],
    'vaulter.gate.super_ability' => ['vaulter.gate.super_ability', 'vaulter.access.gate.super_ability', 'custom', 'custom'],
    'vaulter.gate.create_ability' => ['vaulter.gate.create_ability', 'vaulter.access.gate.create_ability', 'custom', 'custom'],
    'vaulter.spatie.permissions' => ['vaulter.spatie.permissions', 'vaulter.access.spatie.permissions', ['custom' => 'Host\\CustomClass'], ['custom' => 'Host\\CustomClass']],
    'vaulter.spatie.super_permission' => ['vaulter.spatie.super_permission', 'vaulter.access.spatie.super_permission', 'custom', 'custom'],
    'vaulter.spatie.create_permission' => ['vaulter.spatie.create_permission', 'vaulter.access.spatie.create_permission', 'custom', 'custom'],
    'vaulter.spatie.teams' => ['vaulter.spatie.teams', 'vaulter.access.spatie.teams', false, false],
    'vaulter.spatie.role_subject_type' => ['vaulter.spatie.role_subject_type', 'vaulter.access.spatie.role_subject_type', 'custom', 'custom'],
    'vaulter.spatie.team_subject_type' => ['vaulter.spatie.team_subject_type', 'vaulter.access.spatie.team_subject_type', 'custom', 'custom'],
    'vaulter.azgard.panel_id' => ['vaulter.azgard.panel_id', 'vaulter-azgard.panel_id', 'custom', 'custom'],
    'vaulter.azgard.permissions' => ['vaulter.azgard.permissions', 'vaulter-azgard.permissions', ['custom' => 'Host\\CustomClass'], ['custom' => 'Host\\CustomClass']],
    'vaulter.azgard.create_permission' => ['vaulter.azgard.create_permission', 'vaulter-azgard.create_permission', 'custom', 'custom'],
    'vaulter.route_prefix' => ['vaulter.route_prefix', 'vaulter.routes.prefix', 'custom', 'custom'],
    'vaulter.middleware' => ['vaulter.middleware', 'vaulter.routes.middleware.authenticated', ['custom'], ['custom']],
    'vaulter.rate_limits.public.per_minute' => ['vaulter.rate_limits.public.per_minute', 'vaulter.routes.rate_limits.public', 'custom', 'custom'],
    'vaulter.rate_limits.uploads.per_minute' => ['vaulter.rate_limits.uploads.per_minute', 'vaulter.routes.rate_limits.uploads', 'custom', 'custom'],
    'vaulter.share_token_generator' => ['vaulter.share_token_generator', 'vaulter.sharing.token_generator', 'custom', 'custom'],
    'vaulter.share.token_generator' => ['vaulter.share.token_generator', 'vaulter.sharing.token_generator', 'custom', 'custom'],
    'vaulter.share.url_base' => ['vaulter.share.url_base', 'vaulter.sharing.url_base', 'custom', 'custom'],
    'vaulter.share.prefix_length' => ['vaulter.share.prefix_length', 'vaulter.sharing.prefix_length', 'custom', 'custom'],
    'vaulter.share.default_ttl_hours' => ['vaulter.share.default_ttl_hours', 'vaulter.sharing.default_ttl_hours', 'custom', 'custom'],
    'vaulter.share.max_ttl_hours' => ['vaulter.share.max_ttl_hours', 'vaulter.sharing.max_ttl_hours', 'custom', 'custom'],
    'vaulter.downloads.inline_mime_whitelist' => ['vaulter.downloads.inline_mime_whitelist', 'vaulter.downloads.inline_mime_types', ['custom'], ['custom']],
    'vaulter.storage.default_disk' => ['vaulter.storage.default_disk', 'vaulter-media.disk', 'custom', 'custom'],
    'vaulter.dedup.scope' => ['vaulter.dedup.scope', 'vaulter-media.dedup.scope', 'storage', 'drive'],
    'vaulter.gc.grace_hours' => ['vaulter.gc.grace_hours', 'vaulter-media.gc.grace_hours', 'custom', 'custom'],
    'vaulter.gc.orphan_grace_hours' => ['vaulter.gc.orphan_grace_hours', 'vaulter-media.gc.orphan_grace_hours', 'custom', 'custom'],
    'vaulter.cas.content_store' => ['vaulter.cas.content_store', 'vaulter-media.content_store', 'custom', 'custom'],
    'vaulter.cas.storage_driver' => ['vaulter.cas.storage_driver', 'vaulter-media.storage_driver', 'custom', 'custom'],
    'vaulter.cas.blob_store' => ['vaulter.cas.blob_store', 'vaulter-media.blob_store', 'custom', 'custom'],
    'vaulter.drive.allowed_mime' => ['vaulter.drive.allowed_mime', 'vaulter-drive.uploads.allowed_mime_types', ['custom'], ['custom']],
    'vaulter.uploads.chunk_size' => ['vaulter.uploads.chunk_size', 'vaulter-drive.uploads.resumable.chunk_size', 'custom', 'custom'],
    'vaulter.uploads.max_chunk_bytes' => ['vaulter.uploads.max_chunk_bytes', 'vaulter-drive.uploads.resumable.max_chunk_size', 'custom', 'custom'],
    'vaulter.uploads.session_ttl_hours' => ['vaulter.uploads.session_ttl_hours', 'vaulter-drive.uploads.resumable.session_ttl_hours', 'custom', 'custom'],
    'vaulter.uploads.max_active_sessions' => ['vaulter.uploads.max_active_sessions', 'vaulter-drive.uploads.resumable.max_active_sessions_per_actor', 'custom', 'custom'],
    'vaulter.uploads.tus.enabled' => ['vaulter.uploads.tus.enabled', 'vaulter-drive.uploads.resumable.enabled', false, false],
    'vaulter.features.uploads' => ['vaulter.features.uploads', 'vaulter-drive.uploads.resumable.enabled', false, false],
    'vaulter.uploads.metadata.allowed_keys' => ['vaulter.uploads.metadata.allowed_keys', 'vaulter-drive.uploads.resumable.metadata.allowed_keys', ['custom'], ['custom']],
    'vaulter.uploads.metadata.max_value_length' => ['vaulter.uploads.metadata.max_value_length', 'vaulter-drive.uploads.resumable.metadata.max_value_length', 'custom', 'custom'],
    'vaulter.trash.retention_days' => ['vaulter.trash.retention_days', 'vaulter-drive.trash.retention_days', 'custom', 'custom'],
    'vaulter.max_document_size_kb' => ['vaulter.max_document_size_kb', 'vaulter-documents.content.max_size_kb', 'custom', 'custom'],
    'vaulter.versions.max' => ['vaulter.versions.max', 'vaulter-documents.revisions.limit', 'custom', 'custom'],
    'vaulter.max_versions' => ['vaulter.max_versions', 'vaulter-documents.revisions.limit', 'custom', 'custom'],
    'vaulter.features.comments' => ['vaulter.features.comments', 'vaulter-documents.comments.enabled', false, false],
    'vaulter.features.collaboration' => ['vaulter.features.collaboration', 'vaulter-documents.collaboration.enabled', false, false],
    'vaulter.collaboration.driver' => ['vaulter.collaboration.driver', 'vaulter-documents.collaboration.driver', 'custom', 'custom'],
    'vaulter.collaboration.channel_prefix' => ['vaulter.collaboration.channel_prefix', 'vaulter-documents.collaboration.broadcast.channel_prefix', 'custom', 'custom'],
    'vaulter.collaboration.webhook_secret' => ['vaulter.collaboration.webhook_secret', 'vaulter-documents.collaboration.hub.webhook_secret', 'custom', 'custom'],
    'vaulter.collaboration.webhook_timestamp_tolerance' => ['vaulter.collaboration.webhook_timestamp_tolerance', 'vaulter-documents.collaboration.hub.timestamp_tolerance', 'custom', 'custom'],
    'vaulter.collaboration.drivers.hocuspocus.url' => ['vaulter.collaboration.drivers.hocuspocus.url', 'vaulter-documents.collaboration.hub.url', 'custom', 'custom'],
    'vaulter.collaboration.drivers.hocuspocus.app_id' => ['vaulter.collaboration.drivers.hocuspocus.app_id', 'vaulter-documents.collaboration.hub.app_id', 'custom', 'custom'],
    'vaulter.collaboration.drivers.hocuspocus.secret' => ['vaulter.collaboration.drivers.hocuspocus.secret', 'vaulter-documents.collaboration.hub.jwt_secret', 'custom', 'custom'],
    'vaulter.collaboration.drivers.hocuspocus.token_ttl' => ['vaulter.collaboration.drivers.hocuspocus.token_ttl', 'vaulter-documents.collaboration.hub.token_ttl', 'custom', 'custom'],
    'vaulter.tiptap.extra_nodes' => ['vaulter.tiptap.extra_nodes', 'vaulter-documents.tiptap.extra_nodes', 'custom', 'custom'],
    'vaulter.tiptap.extra_marks' => ['vaulter.tiptap.extra_marks', 'vaulter-documents.tiptap.extra_marks', 'custom', 'custom'],
    'vaulter.tiptap.extra_attributes' => ['vaulter.tiptap.extra_attributes', 'vaulter-documents.tiptap.extra_attributes', ['custom' => 'Host\\CustomClass'], ['custom' => 'Host\\CustomClass']],
    'vaulter.tiptap.url_attributes' => ['vaulter.tiptap.url_attributes', 'vaulter-documents.tiptap.url_attributes', ['custom' => 'Host\\CustomClass'], ['custom' => 'Host\\CustomClass']],
    'vaulter.exporters' => ['vaulter.exporters', 'vaulter-documents.exporters', ['custom' => 'Host\\CustomClass'], ['custom' => 'Host\\CustomClass']],
    'vaulter.models.storage' => ['vaulter.models.storage', 'vaulter.models.drive', 'custom', 'custom'],
    'vaulter.models.file_version' => ['vaulter.models.file_version', 'vaulter.models.file_revision', 'custom', 'custom'],
    'vaulter.models.file_version_blob' => ['vaulter.models.file_version_blob', 'vaulter.models.file_revision_blob', 'custom', 'custom'],
    'vaulter.models.upload_session' => ['vaulter.models.upload_session', 'vaulter-drive.models.upload_session', 'custom', 'custom'],
    'vaulter.models.upload_part' => ['vaulter.models.upload_part', 'vaulter-drive.models.upload_part', 'custom', 'custom'],
    'vaulter.models.node_comment' => ['vaulter.models.node_comment', 'vaulter-documents.models.node_comment', 'custom', 'custom'],
    'vaulter.models.node_document_version' => ['vaulter.models.node_document_version', 'vaulter-documents.models.document_revision', 'custom', 'custom'],
    'vaulter-filament.resources.storage' => ['vaulter-filament.resources.storage', 'vaulter-filament.resources.drive', 'custom', 'custom'],
]);

it('accepts equal canonical and legacy values with one deprecation', function (string $legacy, string $canonical, mixed $value, mixed $expected): void {
    $config = new Repository;
    $config->set($legacy, $value);
    $config->set($canonical, $expected);
    $messages = collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, [explode('.', $canonical)[0] => []]));
    expect($config->get($canonical))->toBe($expected)->and($messages)->toHaveCount(1);
})->with('legacy configuration keys');

it('preserves a customized sharing alias when its other alias is the shipped default', function (string $customKey): void {
    $default = RandomShareTokenGenerator::class;
    $config = new Repository(['vaulter' => ['share_token_generator' => $default, 'share' => ['token_generator' => $default]]]);
    $config->set($customKey, 'Host\\TokenGenerator');
    collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, ['vaulter' => ['sharing' => ['token_generator' => $default]]]));
    expect($config->get('vaulter.sharing.token_generator'))->toBe('Host\\TokenGenerator');
})->with(['vaulter.share_token_generator', 'vaulter.share.token_generator']);

it('rejects differently customized sharing aliases', function (): void {
    $config = new Repository(['vaulter' => ['share_token_generator' => 'Host\\FirstGenerator', 'share' => ['token_generator' => 'Host\\SecondGenerator']]]);
    (new ConfigNormalizer)->normalize($config, ['vaulter' => ['sharing' => ['token_generator' => RandomShareTokenGenerator::class]]]);
})->throws(InvalidConfigurationException::class, 'Conflicting legacy sharing token keys');

it('recognizes a canonical map still equal to package defaults and preserves the host replacement map', function (): void {
    $defaults = ['vaulter' => ['access' => ['permission_drivers' => ['database' => 'BuiltIn\\Driver', 'gate' => 'BuiltIn\\Gate']]]];
    $config = new Repository($defaults);
    $config->set('vaulter.permission_drivers', ['custom' => 'Host\\CustomDriver']);
    collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, $defaults));
    expect($config->get('vaulter.access.permission_drivers'))->toBe(['custom' => 'Host\\CustomDriver']);
});

it('normalizes each legacy upload size key in its own units', function (string $legacy, int $expected): void {
    $config = new Repository;
    $config->set($legacy, 5);
    $messages = collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, ['vaulter-drive' => []]));
    expect($config->get('vaulter-drive.uploads.max_file_size'))->toBe($expected)->and($messages)->toHaveCount(1);
})->with([
    ['vaulter.uploads.max_bytes', 5],
    ['vaulter.drive.max_upload_kb', 5120],
]);

it('retains module defaults registered before the core provider', function (): void {
    $previousContainer = Container::getInstance();
    try {
        $application = new Application(__DIR__);
        $application->instance('config', new Repository);
        $application->register(VaulterMediaServiceProvider::class);
        $earlyNormalizer = $application->make(ConfigNormalizer::class);
        $application->register(VaulterServiceProvider::class);

        expect($application->make(ConfigNormalizer::class))->toBe($earlyNormalizer)
            ->and(array_keys($earlyNormalizer->packageDefaults()))->toBe(['vaulter-media', 'vaulter']);
    } finally {
        Container::setInstance($previousContainer);
    }
});

it('retains default exporter formats alongside host overrides in legacy and canonical maps', function (string $key, array $override): void {
    $defaults = ['vaulter-documents' => ['exporters' => ['html' => 'BuiltIn\\HtmlExporter', 'markdown' => 'BuiltIn\\MarkdownExporter']]];
    $config = new Repository;
    $config->set($key, $override);
    collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, $defaults));

    expect($config->get('vaulter-documents.exporters'))->toBe(array_replace($defaults['vaulter-documents']['exporters'], $override));
})->with([
    ['vaulter.exporters', ['html' => 'Host\\HtmlExporter']],
    ['vaulter.exporters', []],
    ['vaulter-documents.exporters', ['html' => 'Host\\HtmlExporter']],
    ['vaulter-documents.exporters', []],
]);

it('turns the 0.2 enabled document formats and the spreadsheets flag into document type classes (PLAN2.P4.3)', function (): void {
    $config = new Repository(['vaulter' => [
        'enabled_document_types' => ['richtext', 'table', 'markdown', 'template'],
        'features' => ['spreadsheets' => true],
    ]]);
    $defaults = ['vaulter-documents' => ['types' => ['Vaulter\\Documents\\Types\\RichTextDocumentType', 'Vaulter\\Documents\\Types\\MarkdownDocumentType']]];

    $messages = collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, $defaults));

    expect($config->get('vaulter-documents.types'))->toBe([
        'Vaulter\\Documents\\Types\\RichTextDocumentType',
        'Vaulter\\Documents\\Types\\MarkdownDocumentType',
        'Vaulter\\Documents\\Types\\SpreadsheetDocumentType',
    ])->and($messages)->toContain(
        '[Vaulter] Config key "vaulter.enabled_document_types" is deprecated; use "vaulter-documents.types".',
        '[Vaulter] Config key "vaulter.features.spreadsheets" is deprecated; use "vaulter-documents.types".',
    );
});

it('keeps an explicit vaulter-documents.types list from growing default entries back (PLAN2.P4.3)', function (): void {
    $config = new Repository(['vaulter-documents' => ['types' => ['Host\\KanbanDocumentType']]]);
    $defaults = ['vaulter-documents' => ['types' => ['Vaulter\\Documents\\Types\\RichTextDocumentType', 'Vaulter\\Documents\\Types\\MarkdownDocumentType']]];

    (new ConfigNormalizer)->normalize($config, $defaults);

    expect($config->get('vaulter-documents.types'))->toBe(['Host\\KanbanDocumentType']);
});

it('refuses a legacy format list that contradicts an explicit types list (PLAN2.P4.3)', function (): void {
    $config = new Repository([
        'vaulter' => ['enabled_document_types' => ['markdown']],
        'vaulter-documents' => ['types' => ['Vaulter\\Documents\\Types\\RichTextDocumentType']],
    ]);
    $defaults = ['vaulter-documents' => ['types' => ['Vaulter\\Documents\\Types\\RichTextDocumentType', 'Vaulter\\Documents\\Types\\MarkdownDocumentType']]];

    collectConfigurationDeprecations(static fn () => (new ConfigNormalizer)->normalize($config, $defaults));
})->throws(InvalidConfigurationException::class);

<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vaulter\Documents\Access\CollaborationAccessPolicy;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Data\Types\DocumentFeatures;
use Vaulter\Documents\Data\Types\DocumentViewer;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers as Docs;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\SlotContribution;
use Vaulter\Testing\Fixtures\TestUser;

uses(OperationsTestCase::class, RefreshDatabase::class);

function policyDriver(bool $available = true): void
{
    $driver = new class implements CollaborationProtocolDriver
    {
        public function protocol(): string
        {
            return 'yjs';
        }

        public function version(): string
        {
            return 'fake/1';
        }

        public function supportsState(string $documentType, string $stateFormat, int $schemaVersion, bool $hasCheckpoint, CollaborationMode $mode): bool
        {
            return $stateFormat === 'yjs-update-v1' && $schemaVersion === 1;
        }

        public function issueSession(CollaborationGrant $grant): CollaborationSession
        {
            throw new LogicException('Policy never mints credentials.');
        }
    };
    app()->instance($driver::class, $driver);
    $old = app(Kernel::class)->compiled();
    $contributions = $old->contributions;
    $contributions[CollaborationProtocolDriver::class] = $available
        ? [new SlotContribution(CollaborationProtocolDriver::class, 'test.policy', 'documents', $driver::class, key: 'yjs')]
        : [];
    $kernel = new Kernel(app());
    $kernel->seal(new CompiledModules(...array_replace(get_object_vars($old), ['contributions' => $contributions])));
    app()->instance(Kernel::class, $kernel);
}

beforeEach(function (): void {
    policyDriver();
    $this->drive = Docs::drive();
    $this->node = Documents::in($this->drive)->create('Policy', content: Docs::content()->toArray());
    AccessTestHelpers::grant($this->node, RoleRegistry::WRITER, subjectId: 42);
    $this->context = Docs::context($this->drive, 42);
});

afterEach(fn () => Carbon::setTestNow());

it('returns a complete immutable grant under the configured TTL and scope ceiling', function (): void {
    Carbon::setTestNow(Carbon::createFromTimestamp(1000));
    config()->set('vaulter-documents.collaboration.session_ttl', 120);
    $decision = app(CollaborationAccessPolicy::class)->evaluate($this->context, $this->node);
    expect($decision->ability)->toBe(Ability::Edit)
        ->and($decision->grant->mode)->toBe(CollaborationMode::Write)
        ->and($decision->grant->nodeId)->toBe($this->node->id)
        ->and($decision->grant->driveId)->toBe($this->node->driveId)
        ->and($decision->grant->principal)->toEqual($this->context->actor->ref())
        ->and($decision->grant->tenant)->toBeNull()
        ->and($decision->grant->notAfter)->toBe(1120)
        ->and($decision->grant->protocol)->toBe('yjs')
        ->and($decision->grant->stateFormat)->toBe('yjs-update-v1');
    expect(app(CollaborationAccessPolicy::class)->evaluate($this->context, $this->node, CollaborationMode::Read)->grant->mode)->toBe(CollaborationMode::Read);
});

it('bounds TTL structurally', function (mixed $ttl): void {
    config()->set('vaulter-documents.collaboration.session_ttl', $ttl);
    expect(fn () => app(DocumentsConfig::class)->collaborationSessionTtl())->toThrow(InvalidConfigurationException::class);
})->with([0, -1, 301, '300', 1.5, null]);

it('narrows mismatching client schemas and archived drives to read', function (?int $client, bool $archived): void {
    if ($archived) {
        $this->drive->archive();
    }
    expect(app(CollaborationAccessPolicy::class)->evaluate($this->context, $this->node, clientSchemaVersion: $client)->grant->mode)->toBe(CollaborationMode::Read);
})->with([[0, false], [2, false], [null, true]]);

it('evaluates a hub reference like the handle form and reports the drive as active only while it is', function (bool $archived): void {
    if ($archived) {
        $this->drive->archive();
    }
    $policy = app(CollaborationAccessPolicy::class);

    $decision = $policy->evaluateReference($this->context->actor, $this->node->driveId, $this->node->id, null, 'ref-1');

    expect($decision->driveActive)->toBe(!$archived)
        ->and($decision->grant->mode)->toBe($archived ? CollaborationMode::Read : CollaborationMode::Write)
        ->and($decision->grant->nodeId)->toBe($this->node->id);
})->with([false, true]);

it('hides a hub reference of another drive, tenant, trashed or unknown node and a system actor', function (string $case): void {
    $policy = app(CollaborationAccessPolicy::class);
    $driveId = $this->node->driveId;
    $nodeId = $this->node->id;
    $tenant = null;
    $actor = $this->context->actor;
    match ($case) {
        'drive' => $driveId = Docs::drive()->model()->getKey() === $this->drive->model()->getKey() ? '999999' : (string) Docs::drive()->model()->getKey(),
        'tenant' => $tenant = '0',
        'trash' => $this->drive->nodes()->trash($this->node->id),
        'unknown' => $nodeId = '999999',
        'system' => $actor = Docs::context($this->drive)->actor,
    };

    expect(fn () => $policy->evaluateReference($actor, $driveId, $nodeId, $tenant, 'ref-2'))
        ->toThrow($case === 'system' ? MissingActorException::class : NodeNotFoundException::class);
})->with(['drive', 'tenant', 'trash', 'unknown', 'system']);

it('answers the room channel View check by the ordinary resolver and false for a missing node', function (): void {
    $policy = app(CollaborationAccessPolicy::class);
    $viewer = AccessTestHelpers::user(42);
    $stranger = AccessTestHelpers::user(777);

    expect($policy->canViewDocumentNode($viewer, $this->node->id))->toBeTrue()
        ->and($policy->canViewDocumentNode($stranger, $this->node->id))->toBeFalse()
        ->and($policy->canViewDocumentNode($viewer, 999999))->toBeFalse();
});

it('treats a node id the key column cannot hold as a missing node', function (): void {
    $policy = app(CollaborationAccessPolicy::class);
    $viewer = AccessTestHelpers::user(42);
    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($policy->canViewDocumentNode($viewer, 'no-such-node'))->toBeFalse();
    expect(fn () => $policy->evaluateReference($this->context->actor, $this->node->driveId, 'no-such-node', null, 'ref-bad'))
        ->toThrow(NodeNotFoundException::class);
    expect(json_encode(DB::getQueryLog(), JSON_THROW_ON_ERROR))->not->toContain('no-such-node');
});

it('hides unavailable adapters until View and rejects system principals', function (): void {
    policyDriver(false);
    expect(fn () => app(CollaborationAccessPolicy::class)->evaluate(Docs::context($this->drive, 99), $this->node))->toThrow(NodeNotFoundException::class);
    expect(fn () => app(CollaborationAccessPolicy::class)->evaluate($this->context, $this->node))->toThrow(CollaborationUnavailableException::class, 'protocol_unavailable');
    expect(fn () => app(CollaborationAccessPolicy::class)->evaluate(Docs::context($this->drive), $this->node))->toThrow(MissingActorException::class);
});

it('rejects missing stored compatibility even without a client schema', function (string $column, mixed $value): void {
    Document::query()->where('node_id', $this->node->id)->update([$column => $value]);
    expect(fn () => app(CollaborationAccessPolicy::class)->evaluate($this->context, $this->node))->toThrow(CollaborationUnavailableException::class);
})->with([['schema_version', 2], ['collab_state_format', 'foreign'], ['collab_protocol', 'foreign']]);

it('uses guarded profile flags and preserves metadata profile refusal', function (): void {
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->owners(TestUser::class));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    policyDriver(false);
    expect(fn () => app(CollaborationAccessPolicy::class)->evaluate($this->context, $this->node))->toThrow(ProfileRestrictionException::class);
});

it('hides other drives, explicit tenant mismatch, trash and deleting drives', function (string $case): void {
    $context = $this->context;
    if ($case === 'drive') {
        $context = Docs::context(Docs::drive(), 42);
    }
    if ($case === 'tenant') {
        Node::query()->whereKey($this->node->id)->update(['tenant_id' => '0']);
    }
    if ($case === 'trash') {
        $this->drive->nodes()->trash($this->node->id);
    }
    if ($case === 'deleting') {
        $this->drive->model()->forceFill(['status' => 'deleting'])->save();
    }
    expect(fn () => app(CollaborationAccessPolicy::class)->evaluate($context, $this->node))->toThrow(NodeNotFoundException::class);
})->with(['drive', 'tenant', 'trash', 'deleting']);

it('does not make degraded JSON writable', function (): void {
    Document::query()->where('node_id', $this->node->id)->update(['content' => json_encode(['type' => 'doc', 'content' => [['type' => 'uninstalled-node']]])]);
    expect(app(CollaborationAccessPolicy::class)->evaluate($this->context, $this->node)->grant->mode)->toBe(CollaborationMode::Read);
});

it('uses the highest guarded ability without exceeding the token ceiling', function (string $role, Ability $ability, CollaborationMode $mode): void {
    AccessTestHelpers::grant($this->node, $role, subjectId: 43);
    $decision = app(CollaborationAccessPolicy::class)->evaluate(Docs::context($this->drive, 43), $this->node);
    expect($decision->ability)->toBe($ability)->and($decision->grant->mode)->toBe($mode);
})->with([
    [RoleRegistry::READER, Ability::View, CollaborationMode::Read],
    [RoleRegistry::COMMENTER, Ability::Comment, CollaborationMode::Read],
    [RoleRegistry::WRITER, Ability::Edit, CollaborationMode::Write],
]);

it('does not restore an ability denied by the profile through raw ACL', function (): void {
    AccessTestHelpers::grant($this->node, RoleRegistry::COMMENTER, subjectId: 43);
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->comments(false));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    $decision = app(CollaborationAccessPolicy::class)->evaluate(Docs::context($this->drive, 43), $this->node);
    expect($decision->ability)->toBe(Ability::View)->and($decision->grant->mode)->toBe(CollaborationMode::Read);
});

it('keeps the stored schema read-only when the installed type has advanced', function (): void {
    $type = new class implements DocumentType, SupportsCollaboration
    {
        public function key(): string
        {
            return 'acme/rich-text';
        }

        public function label(): string
        {
            return 'Updated rich text';
        }

        public function schemaVersion(): int
        {
            return 2;
        }

        public function codec(): DocumentCodec
        {
            return (new RichTextDocumentType)->codec();
        }

        public function defaultContent(): DocumentContent
        {
            return (new RichTextDocumentType)->defaultContent();
        }

        public function viewer(): DocumentViewer
        {
            return (new RichTextDocumentType)->viewer();
        }

        public function features(): DocumentFeatures
        {
            return (new RichTextDocumentType)->features();
        }

        public function projectFromState(string $state): ?DocumentContent
        {
            return null;
        }

        public function collaborationProtocol(): string
        {
            return 'yjs';
        }

        public function collaborationStateFormat(): string
        {
            return 'yjs-update-v1';
        }
    };
    $registry = new DocumentTypeRegistry(app());
    $registry->register($type);
    $registry->freeze();
    app()->instance(DocumentTypeRegistry::class, $registry);
    Document::query()->where('node_id', $this->node->id)->update(['type' => 'acme/rich-text']);
    $before = Document::query()->where('node_id', $this->node->id)->firstOrFail()->getAttributes();
    $decision = app(CollaborationAccessPolicy::class)->evaluate($this->context, $this->node);
    expect($decision->grant->schemaVersion)->toBe(1)->and($decision->grant->mode)->toBe(CollaborationMode::Read);
    expect(Document::query()->where('node_id', $this->node->id)->firstOrFail()->getAttributes())->toBe($before);
});

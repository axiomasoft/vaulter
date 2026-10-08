<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Permission;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Access\UnknownRoleException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Models\Activity\Activity;
use Vaulter\Operations\AccessOperations;
use Vaulter\Operations\OperationContext;
use Vaulter\Operations\ShareOperations;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Access\ActorRef;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    config()->set('vaulter.features.share_links', true);
    $this->drive = AccessTestHelpers::storage();
    $this->node = AccessTestHelpers::folder($this->drive, null, 'secured');
    $this->context = new OperationContext($this->drive, Actor::user(AccessTestHelpers::user()), 'explicit-correlation');
});

it('conceals hidden grants and shares before checking Share', function (): void {
    foreach ([AccessOperations::class => 'grants', ShareOperations::class => 'list'] as $class => $method) {
        $operation = app()->makeWith($class, ['context' => $this->context]);
        expect(fn () => $operation->$method($this->node))->toThrow(NodeNotFoundException::class);
        $grant = AccessTestHelpers::grant($this->node, RoleRegistry::READER);
        expect(fn () => $operation->$method($this->node))->toThrow(PermissionDeniedException::class);
        $grant->delete();
    }
});

it('rejects foreign grants and forged model membership without side effects', function (): void {
    AccessTestHelpers::grant($this->node, RoleRegistry::OWNER);
    $foreign = AccessTestHelpers::folder($this->drive, null, 'other');
    $grant = AccessTestHelpers::grant($foreign, RoleRegistry::READER);
    $forged = clone $grant;
    $forged->node_id = $this->node->id;
    $operations = app()->makeWith(AccessOperations::class, ['context' => $this->context]);
    expect(fn () => $operations->revoke($this->node, $forged))->toThrow(NodeNotFoundException::class)
        ->and($grant->fresh())->not->toBeNull();
});

it('requires caller Manage before explaining a privileged supplied subject', function (): void {
    AccessTestHelpers::grant($this->node, RoleRegistry::READER);
    $operations = app()->makeWith(AccessOperations::class, ['context' => $this->context]);
    expect(fn () => $operations->explain($this->node, Ability::View, AccessTestHelpers::user(99)))->toThrow(PermissionDeniedException::class);
});

it('uses the grants feature independently of disabled bearer links', function (): void {
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->grants()->shareLinks(false));
    app()->instance(ProfileRegistry::class, $registry);
    AccessTestHelpers::grant($this->node, RoleRegistry::OWNER);
    $operations = app()->makeWith(AccessOperations::class, ['context' => $this->context]);
    $grant = $operations->grant($this->node, new GrantInput(Subject::ofModel(AccessTestHelpers::user(2)), RoleRegistry::READER));
    expect($grant->granted_by)->toEqual(1);
    $shares = app()->makeWith(ShareOperations::class, ['context' => $this->context]);
    expect(fn () => $shares->create($this->node, new ShareLinkInput(Permission::View->value | Permission::Download->value)))->toThrow(ProfileRestrictionException::class);
});

it('records explicit system actor and correlation without ambient auth attribution', function (): void {
    $this->actingAs(AccessTestHelpers::user(99));
    app('request')->headers->set('X-Request-Id', '9e85b5d9-7519-41f1-82cc-7aafac3e8396');
    $system = new OperationContext($this->drive, Actor::system('import fixture'), 'system-correlation');
    $operations = app()->makeWith(ShareOperations::class, ['context' => $system]);
    Activity::query()->delete();
    $minted = $operations->create($this->node, new ShareLinkInput(Permission::View->value | Permission::Download->value));
    $row = Activity::query()->sole();
    expect($row->actor_type)->toBe(ActorRef::SYSTEM_TYPE)
        ->and($row->actor_id)->toBeNull()
        ->and($row->request_id)->toBe('9e85b5d9-7519-41f1-82cc-7aafac3e8396')
        ->and($row->context['correlation_id'])->toBe('system-correlation')
        ->and($row->context['system_reason'])->toBe('import fixture')
        ->and(json_encode($row->getAttributes()))->not->toContain($minted->token)
        ->and(json_encode($minted->share->getAttributes()))->not->toContain($minted->token);
});

it('validates public grant roles and subject keys', function (): void {
    foreach (['', 'admin', 'Reader', 'editor'] as $role) {
        expect(fn () => new GrantInput(new Subject('user', 1), $role))->toThrow(UnknownRoleException::class);
    }
    expect(fn () => new GrantInput(new Subject('', ''), RoleRegistry::READER))->toThrow(InvalidArgumentException::class);
    expect((new GrantInput(new Subject('user', 'key'), RoleRegistry::PREVIEWER))->role)->toBe(RoleRegistry::PREVIEWER);
});

it('applies the same guard on HTTP grants and conceals hidden nodes', function (): void {
    $this->actingAs(AccessTestHelpers::user());
    $url = 'api/vaulter/nodes/'.$this->node->id.'/grants';
    $this->getJson($url)->assertNotFound();
    $grant = AccessTestHelpers::grant($this->node, RoleRegistry::READER);
    $this->getJson($url)->assertForbidden();
    $grant->forceFill(['role_key' => RoleRegistry::OWNER])->save();
    $this->postJson($url, ['subject_type' => AccessTestHelpers::user()->getMorphClass(), 'subject_id' => 2, 'role' => 'commenter'])->assertCreated()
        ->assertJsonPath('data.role', 'commenter')->assertJsonPath('data.role_version', 1);
    $this->postJson($url, ['subject_type' => AccessTestHelpers::user()->getMorphClass(), 'subject_id' => 3, 'role' => 'editor'])->assertUnprocessable();
    $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
    $this->deleteJson($url, ['subject_type' => AccessTestHelpers::user()->getMorphClass(), 'subject_id' => 2])->assertNoContent();
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
});

it('preserves request UUID and ULID correlation for every grant and share mutation', function (): void {
    app('request')->headers->set('X-Request-Id', '9e85b5d9-7519-41f1-82cc-7aafac3e8396');
    $context = OperationContext::system($this->drive, 'audit fixture');
    $access = app()->makeWith(AccessOperations::class, ['context' => $context]);
    $shares = app()->makeWith(ShareOperations::class, ['context' => $context]);
    Activity::query()->delete();
    $grant = $access->grant($this->node, new GrantInput(Subject::ofModel(AccessTestHelpers::user(2)), RoleRegistry::READER));
    $minted = $shares->create($this->node, new ShareLinkInput(Permission::View->value));
    $access->revoke($this->node, $grant);
    $shares->revoke($this->node, $minted->share);
    $events = Activity::query()->get();
    expect($events)->toHaveCount(4);
    foreach ($events as $event) {
        expect($event->request_id)->toBe('9e85b5d9-7519-41f1-82cc-7aafac3e8396')
            ->and($event->context['correlation_id'])->toBe($context->correlationId)
            ->and($event->actor_type)->toBe(ActorRef::SYSTEM_TYPE)
            ->and($event->context['system_reason'])->toBe('audit fixture')
            ->and(json_encode($event->getAttributes()))->not->toContain($minted->token);
    }
});

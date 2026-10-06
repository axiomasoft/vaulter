<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Exceptions\Access\PermissionDeniedException;
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
        $grant = AccessTestHelpers::grant($this->node, GrantLevel::Reader);
        expect(fn () => $operation->$method($this->node))->toThrow(PermissionDeniedException::class);
        $grant->delete();
    }
});

it('rejects foreign grants and forged model membership without side effects', function (): void {
    AccessTestHelpers::grant($this->node, GrantLevel::Owner);
    $foreign = AccessTestHelpers::folder($this->drive, null, 'other');
    $grant = AccessTestHelpers::grant($foreign, GrantLevel::Reader);
    $forged = clone $grant;
    $forged->node_id = $this->node->id;
    $operations = app()->makeWith(AccessOperations::class, ['context' => $this->context]);
    expect(fn () => $operations->revoke($this->node, $forged))->toThrow(NodeNotFoundException::class)
        ->and($grant->fresh())->not->toBeNull();
});

it('requires caller Manage before explaining a privileged supplied subject', function (): void {
    AccessTestHelpers::grant($this->node, GrantLevel::Reader);
    $operations = app()->makeWith(AccessOperations::class, ['context' => $this->context]);
    expect(fn () => $operations->explain($this->node, Ability::View, AccessTestHelpers::user(99)))->toThrow(PermissionDeniedException::class);
});

it('uses the grants feature independently of disabled bearer links', function (): void {
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->grants()->shareLinks(false));
    app()->instance(ProfileRegistry::class, $registry);
    AccessTestHelpers::grant($this->node, GrantLevel::Owner);
    $operations = app()->makeWith(AccessOperations::class, ['context' => $this->context]);
    $grant = $operations->grant($this->node, new GrantInput(Subject::ofModel(AccessTestHelpers::user(2)), GrantLevel::Reader));
    expect($grant->granted_by)->toEqual(1);
    $shares = app()->makeWith(ShareOperations::class, ['context' => $this->context]);
    expect(fn () => $shares->create($this->node, new ShareLinkInput(1)))->toThrow(ProfileRestrictionException::class);
});

it('records explicit system actor and correlation without ambient auth attribution', function (): void {
    $this->actingAs(AccessTestHelpers::user(99));
    app('request')->headers->set('X-Request-Id', '9e85b5d9-7519-41f1-82cc-7aafac3e8396');
    $system = new OperationContext($this->drive, Actor::system('import fixture'), 'system-correlation');
    $operations = app()->makeWith(ShareOperations::class, ['context' => $system]);
    Activity::query()->delete();
    $minted = $operations->create($this->node, new ShareLinkInput(1));
    $row = Activity::query()->sole();
    expect($row->actor_type)->toBe(ActorRef::SYSTEM_TYPE)
        ->and($row->actor_id)->toBeNull()
        ->and($row->request_id)->toBe('9e85b5d9-7519-41f1-82cc-7aafac3e8396')
        ->and($row->context['correlation_id'])->toBe('system-correlation')
        ->and($row->context['system_reason'])->toBe('import fixture')
        ->and(json_encode($row->getAttributes()))->not->toContain($minted->token)
        ->and(json_encode($minted->share->getAttributes()))->not->toContain($minted->token);
});

it('validates public grant bits and subject keys', function (): void {
    foreach ([-1, 64, 128] as $bits) {
        expect(fn () => new GrantInput(new Subject('user', 1), GrantLevel::Reader, permissions: $bits))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => new GrantInput(new Subject('', ''), GrantLevel::Reader))->toThrow(InvalidArgumentException::class);
    expect((new GrantInput(new Subject('user', 'key'), GrantLevel::Reader, permissions: 63))->permissions)->toBe(63);
});

it('applies the same guard on HTTP grants and conceals hidden nodes', function (): void {
    $this->actingAs(AccessTestHelpers::user());
    $url = 'api/vaulter/nodes/'.$this->node->id.'/grants';
    $this->getJson($url)->assertNotFound();
    $grant = AccessTestHelpers::grant($this->node, GrantLevel::Reader);
    $this->getJson($url)->assertForbidden();
    $grant->forceFill(['level' => GrantLevel::Owner])->save();
    $this->postJson($url, ['subject_type' => AccessTestHelpers::user()->getMorphClass(), 'subject_id' => 2, 'level' => 'reader', 'permissions' => 32])->assertCreated();
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
    $grant = $access->grant($this->node, new GrantInput(Subject::ofModel(AccessTestHelpers::user(2)), GrantLevel::Reader));
    $minted = $shares->create($this->node, new ShareLinkInput(1));
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

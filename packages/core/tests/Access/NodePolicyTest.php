<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Models\Drive\Node;
use Vaulter\Policies\NodePolicy;
use Vaulter\Tests\Access\AccessTestHelpers;

beforeEach(function (): void {
    config()->set('vaulter.drivers.permission', 'database');
});

it('registers NodePolicy against the node model and delegates to the resolver', function (): void {
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'policed');
    $actor = AccessTestHelpers::user();

    // No grant ⇒ the Gate denies view; a grant ⇒ it allows.
    expect(Gate::forUser($actor)->allows('view', $folder))->toBeFalse();

    AccessTestHelpers::grant($folder, GrantLevel::Writer, scope: GrantScope::Item);

    expect(Gate::forUser($actor)->allows('view', $folder))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('edit', $folder))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('share', $folder))->toBeFalse();
});

it('exposes a policy method per ability', function (): void {
    $policy = app(NodePolicy::class);
    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'methods');
    $actor = AccessTestHelpers::user();

    AccessTestHelpers::grant($folder, GrantLevel::Owner, scope: GrantScope::Item);

    expect($policy->view($actor, $folder))->toBeTrue()
        ->and($policy->manage($actor, $folder))->toBeTrue()
        ->and($policy->delete($actor, $folder))->toBeTrue()
        ->and($policy->share($actor, $folder))->toBeTrue();
});

it('resolves the policy through Gate::getPolicyFor on the node model', function (): void {
    expect(Gate::getPolicyFor(Node::class))->toBeInstanceOf(NodePolicy::class);
});

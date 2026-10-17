<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Actions\Access\GrantNodeAccessAction;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Access\GrantNodeAccessInput;
use Vaulter\Drive\Exceptions\Access\UnenumerableGrantSubjectException;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Fixtures\Access\SubclassedRoleDriver;
use Vaulter\Operations\DriveContext;

/**
 * F-P1.2-01 — a grant addressed to a role/team subject under a host-role driver that
 * does NOT enumerate role subjects (gate/azgard/database/null) is silently inert. The
 * GrantNodeAccessAction now makes that loud: a warning always, and a thrown
 * UnenumerableGrantSubjectException outside production. The spatie lane — which DOES
 * enumerate role subjects — is the control that must NOT throw.
 */
function grantAction(): GrantNodeAccessAction
{
    app()->forgetInstance(PermissionDriverManager::class);

    return app(GrantNodeAccessAction::class);
}

// ── F-P1.2-01 (red-before: today the grant persists silently, no warning/throw).
it('warns and throws when a role grant is written under the gate driver, F-P1.2-01', function (): void {
    config()->set('vaulter.access.permission_driver', 'gate');

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'role-grant-gate');

    $spy = Log::spy();

    $action = grantAction();

    expect(fn (): NodeGrant => $action->execute(DriveContext::system($folder->storage()->firstOrFail(), 'fixture'), new GrantNodeAccessInput(node: $folder, grant: new GrantInput(
        subject: new Subject(type: 'role', id: '7'),
        role: RoleRegistry::READER,
    ))))->toThrow(UnenumerableGrantSubjectException::class);

    $spy->shouldHaveReceived('warning')->once();
});

// ── F-P1.2-01: a team subject under gate trips the same guard.
it('throws for a team subject under the gate driver, F-P1.2-01', function (): void {
    config()->set('vaulter.access.permission_driver', 'gate');

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'team-grant-gate');

    $action = grantAction();

    expect(fn (): NodeGrant => $action->execute(DriveContext::system($folder->storage()->firstOrFail(), 'fixture'), new GrantNodeAccessInput(node: $folder, grant: new GrantInput(
        subject: new Subject(type: 'team', id: '3'),
        role: RoleRegistry::READER,
    ))))->toThrow(UnenumerableGrantSubjectException::class);
});

// ── F-P1.2-01 control: a USER subject under gate is fine (no role enumeration needed).
it('does not throw for a plain user subject under the gate driver, F-P1.2-01', function (): void {
    config()->set('vaulter.access.permission_driver', 'gate');

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'user-grant-gate');

    $action = grantAction();

    $grant = $action->execute(DriveContext::system($folder->storage()->firstOrFail(), 'fixture'), new GrantNodeAccessInput(node: $folder, grant: new GrantInput(
        subject: new Subject(type: 'user', id: '1'),
        role: RoleRegistry::READER,
    )));

    expect($grant)->toBeInstanceOf(NodeGrant::class);
});

// ── F-P1.2-01 (review): a host driver that follows the DOCUMENTED extension path —
// subclass DatabasePermissionDriver and OVERRIDE subjectsFor() to append a role
// Subject — makes its role grants genuinely take effect, so the guard must NOT trip
// even though it carries no EnumeratesRoleSubjects marker. Capability is detected via
// the overridden subjectsFor(), not the marker alone (red-before: today the marker
// proxy throws for this working, documented path).
it('does not throw for a role grant under an unmarked subclass that overrides subjectsFor, F-P1.2-01', function (): void {
    config()->set('vaulter.access.permission_drivers.subclassed', SubclassedRoleDriver::class);
    config()->set('vaulter.access.permission_driver', 'subclassed');

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'role-grant-subclass');

    $action = grantAction();

    $grant = $action->execute(DriveContext::system($folder->storage()->firstOrFail(), 'fixture'), new GrantNodeAccessInput(node: $folder, grant: new GrantInput(
        subject: new Subject(type: 'role', id: '7'),
        role: RoleRegistry::READER,
    )));

    expect($grant)->toBeInstanceOf(NodeGrant::class);
});

// ── F-P1.2-01 control: the spatie lane enumerates role subjects, so a role grant is
// accepted without a warning/throw.
it('does not throw when a role grant is written under the spatie driver, F-P1.2-01', function (): void {
    config()->set('vaulter.access.permission_driver', 'spatie');

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'role-grant-spatie');

    $action = grantAction();

    $grant = $action->execute(DriveContext::system($folder->storage()->firstOrFail(), 'fixture'), new GrantNodeAccessInput(node: $folder, grant: new GrantInput(
        subject: new Subject(type: 'role', id: '9'),
        role: RoleRegistry::READER,
    )));

    expect($grant)->toBeInstanceOf(NodeGrant::class);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Vaulter\Access\HostRoleDriverManager;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Actions\Access\GrantNodeAccessAction;
use Vaulter\Data\Access\GrantNodeAccessData;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Exceptions\Access\UnenumerableGrantSubjectException;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\Access\SubclassedRoleDriver;

/**
 * F-P1.2-01 — a grant addressed to a role/team subject under a host-role driver that
 * does NOT enumerate role subjects (gate/azgard/database/null) is silently inert. The
 * GrantNodeAccessAction now makes that loud: a warning always, and a thrown
 * UnenumerableGrantSubjectException outside production. The spatie lane — which DOES
 * enumerate role subjects — is the control that must NOT throw.
 */
function grantAction(): GrantNodeAccessAction
{
    app()->forgetInstance(HostRoleDriverManager::class);

    return app(GrantNodeAccessAction::class);
}

// ── F-P1.2-01 (red-before: today the grant persists silently, no warning/throw).
it('warns and throws when a role grant is written under the gate driver, F-P1.2-01', function (): void {
    config()->set('vaulter.drivers.permission', 'gate');

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'role-grant-gate');

    $spy = Log::spy();

    $action = grantAction();

    expect(fn (): NodeGrant => $action->execute($folder, new GrantNodeAccessData(
        subject: new Subject(type: 'role', id: '7'),
        level: GrantLevel::Reader,
    )))->toThrow(UnenumerableGrantSubjectException::class);

    $spy->shouldHaveReceived('warning')->once();
});

// ── F-P1.2-01: a team subject under gate trips the same guard.
it('throws for a team subject under the gate driver, F-P1.2-01', function (): void {
    config()->set('vaulter.drivers.permission', 'gate');

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'team-grant-gate');

    $action = grantAction();

    expect(fn (): NodeGrant => $action->execute($folder, new GrantNodeAccessData(
        subject: new Subject(type: 'team', id: '3'),
        level: GrantLevel::Reader,
    )))->toThrow(UnenumerableGrantSubjectException::class);
});

// ── F-P1.2-01 control: a USER subject under gate is fine (no role enumeration needed).
it('does not throw for a plain user subject under the gate driver, F-P1.2-01', function (): void {
    config()->set('vaulter.drivers.permission', 'gate');

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'user-grant-gate');

    $action = grantAction();

    $grant = $action->execute($folder, new GrantNodeAccessData(
        subject: new Subject(type: 'user', id: '1'),
        level: GrantLevel::Reader,
    ));

    expect($grant)->toBeInstanceOf(NodeGrant::class);
});

// ── F-P1.2-01 (review): a host driver that follows the DOCUMENTED extension path —
// subclass DatabasePermissionResolver and OVERRIDE subjectsFor() to append a role
// Subject — makes its role grants genuinely take effect, so the guard must NOT trip
// even though it carries no EnumeratesRoleSubjects marker. Capability is detected via
// the overridden subjectsFor(), not the marker alone (red-before: today the marker
// proxy throws for this working, documented path).
it('does not throw for a role grant under an unmarked subclass that overrides subjectsFor, F-P1.2-01', function (): void {
    config()->set('vaulter.permission_drivers.subclassed', SubclassedRoleDriver::class);
    config()->set('vaulter.drivers.permission', 'subclassed');

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'role-grant-subclass');

    $action = grantAction();

    $grant = $action->execute($folder, new GrantNodeAccessData(
        subject: new Subject(type: 'role', id: '7'),
        level: GrantLevel::Reader,
    ));

    expect($grant)->toBeInstanceOf(NodeGrant::class);
});

// ── F-P1.2-01 control: the spatie lane enumerates role subjects, so a role grant is
// accepted without a warning/throw.
it('does not throw when a role grant is written under the spatie driver, F-P1.2-01', function (): void {
    config()->set('vaulter.drivers.permission', 'spatie');

    $storage = AccessTestHelpers::storage();
    $folder = AccessTestHelpers::folder($storage, null, 'role-grant-spatie');

    $action = grantAction();

    $grant = $action->execute($folder, new GrantNodeAccessData(
        subject: new Subject(type: 'role', id: '9'),
        level: GrantLevel::Reader,
    ));

    expect($grant)->toBeInstanceOf(NodeGrant::class);
});

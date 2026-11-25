<?php

declare(strict_types=1);

use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Drive\Actions\Access\SetNodeInheritanceAction;
use Vaulter\Drive\Actions\Sharing\RotateShareLinkAction;
use Vaulter\Drive\Actions\Sharing\UpdateShareLinkAction;
use Vaulter\Drive\Data\Access\RevokeGrantInput;
use Vaulter\Drive\Data\Sharing\RotateShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkUpdate;
use Vaulter\Drive\Data\Sharing\UpdateShareLinkInput;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;

/**
 * On real backends (interfering pairs): two writers that read the same version of an
 * aggregate race; the row lock serialises them and the second one's `UPDATE … WHERE version = ?` (or its comparison
 * under the lock) refuses it with stale_write. Exactly one lands, the version rises by one. PostgreSQL/MySQL only
 * (ForkRace); classified serial + database.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    config()->set('vaulter-drive.features.share_links', true);
});

/** @param list<RaceOutcome> $outcomes */
function shareRaceOneLanded(array $outcomes): void
{
    $landed = array_values(array_filter($outcomes, static fn (RaceOutcome $outcome): bool => $outcome->ok));
    $refused = array_values(array_filter($outcomes, static fn (RaceOutcome $outcome): bool => !$outcome->ok));

    expect($landed)->toHaveCount(1)
        ->and($refused)->toHaveCount(1)
        ->and($refused[0]->failedWith(StaleWriteException::class))->toBeTrue($refused[0]->exception.': '.$refused[0]->message);
}

it('lets one of a rotation and an update under the same version land and refuses the other with stale_write', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('shared');
    $share = $drive->shares()->create($folder->id, new ShareLinkInput);
    $driveId = $drive->model()->getKey();
    $nodeId = DriveTestHelpers::model($folder)->getKey();
    $shareId = $share->link->id;
    $version = $share->link->version;

    $outcomes = ForkRace::run([
        static fn (): string => app(RotateShareLinkAction::class)->execute(
            DriveContext::system(Drive::query()->findOrFail($driveId), 'race'),
            new RotateShareLinkInput(node: Node::query()->findOrFail($nodeId), share: NodeShare::query()->findOrFail($shareId), expectedVersion: $version),
        )->share->token_prefix,
        static fn (): string => (string) app(UpdateShareLinkAction::class)->execute(
            DriveContext::system(Drive::query()->findOrFail($driveId), 'race'),
            new UpdateShareLinkInput(node: Node::query()->findOrFail($nodeId), share: NodeShare::query()->findOrFail($shareId), changes: new ShareLinkUpdate(maxUses: 7), expectedVersion: $version),
        )->max_uses,
    ]);

    shareRaceOneLanded($outcomes);
    expect(NodeShare::query()->findOrFail($shareId)->version)->toBe($version + 1);
});

it('lets one of a grant revoke and an inheritance restriction of the same node land and refuses the other', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('restricted');
    $model = DriveTestHelpers::model($folder);
    $grant = AccessTestHelpers::grant($model, RoleRegistry::READER, subjectId: 71);
    $driveId = $drive->model()->getKey();
    $nodeId = $model->getKey();
    $grantId = $grant->getKey();
    $version = $model->access_version;

    $outcomes = ForkRace::run([
        static fn (): int => app(RevokeNodeAccessAction::class)->execute(
            DriveContext::system(Drive::query()->findOrFail($driveId), 'race'),
            new RevokeGrantInput(node: Node::query()->findOrFail($nodeId), grant: NodeGrant::query()->findOrFail($grantId), expectedAccessVersion: $version),
        ),
        static fn (): string => (string) app(SetNodeInheritanceAction::class)->execute(
            DriveContext::system(Drive::query()->findOrFail($driveId), 'race'),
            Node::query()->findOrFail($nodeId),
            inherit: false,
            expectedAccessVersion: $version,
        )->getKey(),
    ]);

    shareRaceOneLanded($outcomes);
    expect(Node::query()->findOrFail($nodeId)->access_version)->toBe($version + 1);
});

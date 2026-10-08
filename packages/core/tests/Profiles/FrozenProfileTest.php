<?php

declare(strict_types=1);

use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Profiles\UploadPolicy;
use Vaulter\Tests\Fixtures\Panels\FakeProject;

it('captures independent policy snapshots and rejects later mutation', function (): void {
    $source = (new Profile)->key('project')->owners(FakeProject::class)->disk('local')->defaultQuota(100)
        ->uploads(new UploadPolicy(maxFileSize: 70))->permissionDriver('database')->documentTypes('vaulter/rich-text')
        ->grants(false)->shareLinks(false)->comments(false)->revisionLimit(2)->trashRetentionDays(0);
    $registry = new ProfileRegistry;
    $registry->register($source);
    $source->disk('other')->defaultQuota(999);
    $registry->freeze();
    $snapshot = $registry->get('project');
    expect($snapshot->getKey())->toBe('project')->and($snapshot->getOwners())->toBe([FakeProject::class])
        ->and($snapshot->getDisk())->toBe('local')->and($snapshot->getDefaultQuota())->toBe(100)
        ->and($snapshot->getUploadPolicy()->maxFileSize)->toBe(70)->and($snapshot->getPermissionDriver())->toBe('database')
        ->and($snapshot->getDocumentTypes())->toBe(['vaulter/rich-text'])->and($snapshot->grantsEnabled())->toBeFalse()
        ->and($snapshot->shareLinksEnabled())->toBeFalse()->and($snapshot->commentsEnabled())->toBeFalse()
        ->and($snapshot->getRevisionLimit())->toBe(2)->and($snapshot->getTrashRetentionDays())->toBe(0);
    expect(fn () => $snapshot->disk('escape'))->toThrow(LogicException::class)
        ->and(fn () => $registry->all()['project']->grants())->toThrow(LogicException::class)
        ->and(fn () => $registry->register((new Profile)->key('later')))->toThrow(LogicException::class);
});

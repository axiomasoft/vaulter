<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;
use Vaulter\Attributes\Api;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Events\EventTypeContract;
use Vaulter\Support\Identity;

/**
 * Test helpers for an application that embeds Vaulter. Everything goes through the
 * public surface — Operations and the activity journal — never through the access
 * resolver or the tables' internals, so a test fails for the reasons a host would.
 *
 * ```php
 * uses(InteractsWithVaulter::class);
 *
 * it('hides the node', function (): void {
 *     $this->vaulterFakeStorage();
 *     $this->assertNodeNotVisibleTo($stranger, $node);
 * });
 * ```
 */
#[Api]
trait InteractsWithVaulter
{
    /**
     * Fake every disk the application defines (so no test reaches a real object store,
     * whichever disk a profile or the content store uses), or only $disk. Returns the
     * disk the default profile writes to, or $disk.
     */
    public function vaulterFakeStorage(?string $disk = null): Filesystem
    {
        if ($disk !== null) {
            return Storage::fake($disk);
        }

        $names = array_map(strval(...), array_keys((array) Config::get('filesystems.disks', [])));
        foreach (App::make(ProfileRegistry::class)->all() as $profile) {
            if ($profile->getDisk() !== null) {
                $names[] = $profile->getDisk();
            }
        }
        $faked = [];
        foreach (array_unique($names) as $name) {
            $faked[$name] = Storage::fake($name);
        }

        $default = App::make(ProfileRegistry::class)->default()->getDisk() ?? Config::string('filesystems.default', 'local');

        return $faked[$default] ?? Storage::fake($default);
    }

    /**
     * A drive opener that acts as $user: `$open($owner)->folders()->create('Reports')`.
     *
     * @return Closure(Model, ?string=): DriveHandle
     */
    public function vaulterActingAs(Authorizable $user): Closure
    {
        return static fn (Model $ownerOrDrive, ?string $profile = null): DriveHandle => Vaulter::drive($ownerOrDrive, $profile)->actingAs($user);
    }

    /**
     * @param (callable(Model): bool)|null $where narrows the match to a journal row
     */
    public function assertVaulterEventRecorded(EventTypeContract $type, ?callable $where = null): void
    {
        Assert::assertTrue(
            $this->vaulterEvents($type)->contains(static fn (Model $row): bool => $where === null || $where($row)),
            sprintf('No "%s" event was recorded%s.', $type->value, $where === null ? '' : ' that matches the filter'),
        );
    }

    public function assertVaulterEventNotRecorded(EventTypeContract $type): void
    {
        Assert::assertTrue($this->vaulterEvents($type)->isEmpty(), sprintf('A "%s" event was recorded.', $type->value));
    }

    public function assertNodeVisibleTo(Authorizable $user, Node|string $node): void
    {
        Assert::assertTrue($this->vaulterNodeVisibleTo($user, $node), 'The node is not visible to the actor.');
    }

    public function assertNodeNotVisibleTo(Authorizable $user, Node|string $node): void
    {
        Assert::assertFalse($this->vaulterNodeVisibleTo($user, $node), 'The node is visible to the actor.');
    }

    /** @return Collection<int, Activity> */
    private function vaulterEvents(EventTypeContract $type): Collection
    {
        $activity = App::make(DriveConfig::class)->model('activity');

        return Vaulter::withoutTenancy(static fn () => $activity::query()->where('verb', $type->value)->get());
    }

    /**
     * Whether $user can see the node, asked through the drive's Operations: a node the
     * actor cannot view is reported as absent, exactly as a request would see it.
     */
    private function vaulterNodeVisibleTo(Authorizable $user, Node|string $node): bool
    {
        $row = $node instanceof Node ? $node : Vaulter::withoutTenancy(static fn () => App::make(DriveConfig::class)->model('node')::query()->find($node));
        Assert::assertInstanceOf(Node::class, $row, 'The node does not exist, so its visibility is not defined.');

        try {
            Vaulter::drive($row->storage)->actingAs($user)->nodes()->find(Identity::stringify($row->getKey()));
        } catch (NodeNotFoundException) {
            return false;
        }

        return true;
    }
}

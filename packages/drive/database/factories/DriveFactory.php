<?php

declare(strict_types=1);

namespace Vaulter\Drive\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Vaulter\Attributes\Api;
use Vaulter\Drive\Models\Drive;

/**
 * A drive provisioned for a host owner through the same path as Vaulter::drive().
 *
 * ```php
 * $drive = Drive::factory()->for($project)->create();
 * ```
 *
 * @extends VaulterFactory<Drive>
 */
#[Api]
final class DriveFactory extends VaulterFactory
{
    protected $model = Drive::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }

    /**
     * The host entity the drive is mounted on. Any other relation falls through to Eloquent.
     *
     * @param  Factory<Model>|Model $factory
     * @param  string|null          $relationship
     * @return static
     */
    #[\Override]
    public function for($factory, $relationship = null)
    {
        if ($factory instanceof Model) {
            return $this->withVaulter(['owner' => $factory]);
        }

        return parent::for($factory, $relationship);
    }

    /** Provision under a profile other than the default one. */
    public function profile(string $profile): static
    {
        return $this->withVaulter(['profile' => $profile]);
    }

    #[\Override]
    protected function persist(Model $draft): Model
    {
        $owner = $this->stateObject('owner', Model::class) ?? throw new LogicException('A drive needs its owner: Drive::factory()->for($owner)->create().');

        return $this->system($owner, $this->stateString('profile'))->model();
    }
}

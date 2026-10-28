<?php

declare(strict_types=1);

namespace Vaulter\Tenant\Storage;

use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\FilesystemManager;
use Tenant\Composition\CompositionBuilder;
use Tenant\Contracts\Facility;
use Tenant\Contracts\FrameState;
use Tenant\Contracts\ParticipantFactory;
use Tenant\Contracts\PhaseContext;
use Tenant\Contracts\RestoreContext;
use Tenant\Data\ParticipantDeclaration;

/** Explicit host composition for named Vaulter disks; old disk objects retain their permanent generation guard. */
final readonly class StorageFacility implements Facility
{
    /** @param list<string> $disks */
    public function __construct(private FilesystemManager $filesystems, private array $disks) {}

    public static function compose(CompositionBuilder $builder, Container $app, array $disks = ['local']): void
    {
        foreach ($disks as $disk) {
            if (!is_string($disk) || $app['config']->get('filesystems.disks.'.$disk.'.driver') !== 'vaulter-tenant') {
                throw new \InvalidArgumentException('Vaulter named disks must select the guarded driver.');
            }
        }
        $factory = new class($app, $disks) implements ParticipantFactory
        {
            public function __construct(private Container $app, private array $disks) {}

            public function create(): Facility
            {
                return new StorageFacility($this->app->make('filesystem'), $this->disks);
            }
        };
        $builder->register(new ParticipantDeclaration('vaulter:storage', 1, 'resource', [], [], ['vaulter:storage/default'], [], ['tenant:database', 'tenant:scope'], null, $factory));
    }

    public function prepare(PhaseContext $context, FrameState $state): void {}

    public function activate(PhaseContext $context, FrameState $state): void
    {
        $this->filesystems->forgetDisk($this->disks);
    }

    public function restore(RestoreContext $context, FrameState $state): void
    {
        $this->filesystems->forgetDisk($this->disks);
    }

    public function verify(RestoreContext $context, FrameState $state): void {}
}

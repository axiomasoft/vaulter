<?php

declare(strict_types=1);

namespace Vaulter\Media\Doctor;

use Override;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Vaulter\Kernel;
use Vaulter\Media\Contracts\Cas\BlobPins;

/**
 * The pins per owner type. The module of an owner type is its prefix (`drive.revision_blob` -> drive). Pins of a
 * module that is not active stay and keep their blobs from the garbage collector — that is the contract, so it is a
 * warning that names the owner: only the owner's own purge or rollback releases them, never a generic command.
 */
#[Internal]
final readonly class BlobPinsCheck implements DoctorCheck
{
    public function __construct(private BlobPins $pins, private Kernel $kernel) {}

    #[Override]
    public function key(): string
    {
        return 'media.blobs.pins';
    }

    #[Override]
    public function title(): string
    {
        return 'Blob pins by owner';
    }

    #[Override]
    public function run(DoctorContext $context): array
    {
        $counts = $this->pins->countByOwner();
        $offered = [];
        foreach ($this->kernel->modules() as $module) {
            $offered[$module->id->value] = $module->active;
        }

        $findings = [];
        foreach ($counts as $ownerType => $pins) {
            $module = explode('.', $ownerType, 2)[0];
            if ($this->kernel->isActive($module)) {
                continue;
            }
            $state = array_key_exists($module, $offered) ? 'disabled' : 'not installed';
            $findings[] = DoctorFinding::warning(
                code: 'media.blobs.pins_inactive_owner',
                message: sprintf('%d pin(s) of owner type %s belong to the module %s, which is %s; their blobs are kept.', $pins, $ownerType, $module, $state),
                remediation: 'enable the module again, or release its pins through its own purge or rollback',
                evidence: ['owner_type' => $ownerType, 'module' => $module, 'state' => $state, 'pins' => $pins],
            );
        }

        $summary = $counts === []
            ? 'No blob is pinned.'
            : 'Pins by owner type: '.implode(', ', array_map(static fn (string $owner, int $pins): string => $owner.'='.$pins, array_keys($counts), $counts)).'.';

        return [DoctorFinding::ok(code: 'media.blobs.pins', message: $summary, evidence: $counts), ...$findings];
    }
}

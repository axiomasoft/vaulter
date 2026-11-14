<?php

declare(strict_types=1);

namespace Vaulter\Drive\Console;

use Illuminate\Console\Command;
use Vaulter\Attributes\Internal;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\Tenancy;

/**
 * Start a new epoch of a drive's change journal: every cursor issued for
 * the drive expires (410 cursor_expired) and its clients resynchronize with a full
 * listing. For a database restored from a backup, or when an operator knows the
 * journal no longer matches the drive (the journal was switched off for a while).
 */
#[Internal]
final class ResetChangesCommand extends Command
{
    use OpensWriteScope;

    protected $signature = 'vaulter:changes:reset {drive : The drive id}';

    protected $description = 'Expire every change-journal cursor of a drive, forcing its clients to resynchronize.';

    public function handle(ChangeHeadRepository $heads, VaulterDatabase $database, DriveConfig $config): int
    {
        $label = $this->argument('drive');
        $model = $config->model('drive');
        // Reject an invalid or overflowing key before a server engine attempts its column cast.
        $wellFormed = Identity::fitsKey(new $model, $label);
        $drive = $wellFormed
            ? Tenancy::without(static fn () => $model::query()->whereKey($label)->first())
            : null;
        $driveId = Identity::key($drive?->getKey());
        if ($drive === null || $driveId === null) {
            $this->components->error('Drive ['.$label.'] not found.');

            return self::FAILURE;
        }

        $epoch = $database->transaction(static function () use ($heads, $drive, $driveId): int {
            $tenant = Identity::stringify($drive->getRawOriginal('tenant_id'));
            $heads->create($driveId, $tenant === '' ? null : $tenant);
            $heads->find($driveId, 'update');
            $heads->bumpEpoch($driveId);

            return $heads->find($driveId)['epoch'] ?? 1;
        });

        $this->components->info(sprintf('The change journal of drive %s is at epoch %d; earlier cursors have expired.', Identity::stringify($driveId), $epoch));

        return self::SUCCESS;
    }
}

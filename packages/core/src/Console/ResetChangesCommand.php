<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Support\Identity;
use Vaulter\VaulterManager;

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

    public function handle(ChangeHeadRepository $heads, VaulterDatabase $database, VaulterConfig $config, VaulterManager $manager): int
    {
        $argument = $this->argument('drive');
        $model = $config->model('drive');
        // A server engine rejects a non-numeric id for an integer key (SQLSTATE 22P02) instead of finding nothing.
        $wellFormed = (new $model)->getKeyType() !== 'int' || ctype_digit((string) $argument);
        $drive = $wellFormed
            ? $manager->withoutTenancy(static fn () => $model::query()->whereKey($argument)->first())
            : null;
        $driveId = Identity::key($drive?->getKey());
        if ($drive === null || $driveId === null) {
            $this->components->error('Drive ['.$argument.'] not found.');

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

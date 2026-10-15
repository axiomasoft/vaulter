<?php

declare(strict_types=1);

namespace Vaulter\Actions\Content;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Vaulter\Attributes\Internal;
use Vaulter\Content\ScanAttempt;
use Vaulter\Content\ScanWorkTransitions;
use Vaulter\Contracts\Content\ScanWorkSettings;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Repositories\Content\ScanAuthorityRepository;
use Vaulter\Repositories\Content\ScanObligationRepository;

/**
 * Leases due scan work to one worker; the transaction locks only the work row and ends before scanner IO.
 *
 * Hosts reach scan work through the drive scan jobs and commands.
 */
#[Internal]
final readonly class ClaimScanWorkAction
{
    public function __construct(private ScanAuthorityRepository $authority, private VaulterDatabase $database, private ScanObligationRepository $work, private ScanWorkSettings $settings, private ScanWorkTransitions $transitions) {}

    public function execute(int|string $id, int $generation, DateTimeImmutable $now): ?ScanAttempt
    {
        $preview = $this->work->find($id);
        if ($preview === null || $preview->generation !== $generation) {
            return null;
        }
        $drive = $this->authority->drive($preview->drive_id);
        if ($drive === null) {
            return null;
        }
        $spec = $this->transitions->currentSpec($drive, $preview->spec_key);

        return $this->database->transaction(function () use ($id, $generation, $now, $spec): ?ScanAttempt {
            $work = $this->work->lock($id);
            if ($work === null || $work->generation !== $generation || $work->state === 'settled'
                || $work->available_at > $now || ($work->state === 'leased' && $work->lease_expires_at > $now)) {
                return null;
            }
            if ($work->attempt >= $this->transitions->maxAttempts()) {
                $this->transitions->terminal($work, 'attempts_exhausted', $now);

                return null;
            }
            $attempt = $this->transitions->increment($work->attempt);
            $token = (string) Str::uuid();
            $lease = $now->modify('+'.$this->settings->leaseSeconds().' seconds');
            $deadline = $now->modify('+'.$this->settings->scannerTimeoutSeconds().' seconds');
            $work->forceFill(['state' => 'leased', 'attempt' => $attempt, 'lease_token' => $token,
                'lease_expires_at' => $lease, 'updated_at' => $now])->save();

            return new ScanAttempt($work->id, $work->revision_id, $work->blob_id, $work->drive_id, $work->tenant_id,
                $work->content_identity, $spec, $generation, $attempt, $token, $lease, $deadline);
        });
    }
}

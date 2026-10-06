<?php

declare(strict_types=1);

namespace Vaulter\Actions\Access;

use Illuminate\Support\Facades\Log;
use Vaulter\Access\PermissionDriverManager;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Configuration\AccessConfig;
use Vaulter\Data\Access\GrantNodeAccessInput;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Exceptions\Access\UnenumerableGrantSubjectException;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Access\GrantStoreRepository;

/**
 * Grant (or deny) a subject access to a node (§2.6 / H.2). Idempotent on the
 * grant-unique key: re-granting the same subject the same scope/effect updates the
 * level/bits/expiry. The write runs in a transaction and emits NodeGrantCreated.
 *
 * Authorization (the actor may Share/Manage the node) is enforced by the caller
 * (controller/policy) before reaching the Action — the Action persists, it does
 * not re-authorize.
 */
final readonly class GrantNodeAccessAction
{
    public function __construct(
        private readonly AccessConfig $accessConfig,
        private GrantStoreRepository $grants,
        private PermissionDriverManager $drivers,
        private VaulterDatabase $database,
    ) {}

    public function execute(Node $node, GrantNodeAccessInput $data): NodeGrant
    {
        // Fail-loud (F-P1.2-01) BEFORE persisting: a grant to a role/team subject under
        // a driver that cannot enumerate role subjects would be silently inert.
        $this->guardEnumerableSubject($node, $data);

        // The event fires INSIDE the transaction (F-P1.7-03): the outbox/activity rows
        // stage in the SAME commit as the grant — no crash window between commit and
        // staging. The subscribers swallow their own failures, so staging still never
        // rolls the grant back.
        return $this->database->transaction(function () use ($node, $data): NodeGrant {
            $grant = $this->grants->grant(node: $node, data: $data);

            event(new NodeGrantCreated(grant: $grant, actorId: $data->grantedBy));

            return $grant;
        });
    }

    /**
     * Make the silent no-op loud (F-P1.2-01): a grant addressed to a role/group/team
     * subject only ever matches when the active {@see PermissionDriver} expands the actor
     * into those Subjects. Whether it does is decided by
     * {@see PermissionDriverManager::enumeratesRoleSubjects()} — the explicit
     * {@see EnumeratesRoleSubjects} marker OR an overridden `subjectsFor()` (the
     * documented subclass-and-append extension path counts, marker or not). We fail loud
     * ONLY when the driver PROVABLY cannot enumerate role subjects — the standalone
     * `database` base, `gate`, `bridge`, or the `null` lane with `subjectsFor()`
     * inherited unchanged. We always warn, and outside production we throw so a developer
     * notices immediately. The direction is safe (an inert grant grants nothing), so
     * production degrades to a log line rather than a broken write path.
     */
    private function guardEnumerableSubject(Node $node, GrantNodeAccessInput $data): void
    {
        if (!$this->isRoleLikeSubject($data->subject)) {
            return;
        }

        $driver = $this->drivers->forProfile($node->storage()->firstOrFail()->profile);

        if ($this->drivers->enumeratesRoleSubjects($driver)) {
            return;
        }

        Log::warning(
            '[Vaulter] Grant addressed to a role/team subject under a host-role driver '
            .'that does not enumerate role subjects — it will never take effect.',
            [
                'subject_type' => $data->subject->type,
                'subject_id' => $data->subject->id,
                'driver' => $driver::class,
            ],
        );

        if (!app()->environment('production')) {
            throw UnenumerableGrantSubjectException::for($data->subject, $driver::class);
        }
    }

    /**
     * Is the subject a role/group/team subject (as opposed to a user identity)? The
     * canonical role/team subject-type aliases are the ones grant rows are addressed to
     * (config vaulter.spatie.role_subject_type / team_subject_type).
     */
    private function isRoleLikeSubject(Subject $subject): bool
    {
        return in_array(
            $subject->type,
            [$this->accessConfig->spatieRoleSubjectType(), $this->accessConfig->spatieTeamSubjectType()],
            strict: true,
        );
    }
}

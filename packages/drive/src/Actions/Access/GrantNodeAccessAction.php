<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Access;

use Illuminate\Support\Facades\Log;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Configuration\AccessConfig;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Access\GrantNodeAccessInput;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\Access\EffectivePermissionsChanged;
use Vaulter\Drive\Events\Access\NodeGrantCreated;
use Vaulter\Drive\Exceptions\Access\UnenumerableGrantSubjectException;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Repositories\Access\GrantStoreRepository;
use Vaulter\Events\EventRecorder;

/**
 * Give a subject a role on a node. Idempotent on the grant-unique key:
 * re-granting the same subject the same scope replaces the role and expiry. The
 * write runs in a transaction and emits NodeGrantCreated.
 *
 * Authorization (the actor may Manage the node) is enforced by the caller
 * (controller/policy) before reaching the Action — the Action persists, it does
 * not re-authorize.
 */
#[Internal]
final readonly class GrantNodeAccessAction
{
    public function __construct(
        private readonly AccessConfig $accessConfig,
        private GrantStoreRepository $grants,
        private PermissionDriverManager $drivers,
        private VaulterDatabase $database,
        private NodeLocator $locator,
        private EventRecorder $recorder,
    ) {}

    public function execute(DriveContext $context, GrantNodeAccessInput $input): NodeGrant
    {
        $node = $this->locator->find(context: $context, key: $input->node);
        $data = $input->grant;
        // Fail-loud BEFORE persisting: a grant to a role/team subject under
        // a driver that cannot enumerate role subjects would be silently inert.
        $this->guardEnumerableSubject($node, $data);

        // The activity and outbox rows commit with the grant; a failed write rolls it back.
        return $this->database->transaction(function () use ($context, $node, $data): NodeGrant {
            $grant = $this->grants->grant(node: $node, data: $data, grantedBy: $context->actor->ref()->id);

            $this->recorder->record(new NodeGrantCreated(grant: $grant, actor: $context->actor->ref(), correlationId: $context->correlationId));
            $this->recorder->record(EffectivePermissionsChanged::forNode(node: $node, reason: DriveEventType::GrantCreated->value, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $grant;
        });
    }

    /**
     * Make the silent no-op loud: a grant addressed to a role/group/team
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
    private function guardEnumerableSubject(Node $node, GrantInput $data): void
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

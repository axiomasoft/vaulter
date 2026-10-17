<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Exceptions\Access\UnknownRoleException;
use Vaulter\Drive\Exceptions\Policy\FileSizeLimitExceededException;
use Vaulter\Drive\Exceptions\Policy\FolderChildrenLimitExceededException;
use Vaulter\Drive\Exceptions\Policy\TreeDepthLimitExceededException;
use Vaulter\Drive\Exceptions\Sharing\ShareLinkTtlExceededException;
use Vaulter\Drive\Exceptions\Sharing\ShareNotFoundException;
use Vaulter\Drive\Exceptions\Sharing\SharePasswordRequiredException;
use Vaulter\Drive\Exceptions\Sharing\ShareRoleNotAllowedException;
use Vaulter\Exceptions\Changes\CursorExpiredException;
use Vaulter\Exceptions\Changes\InvalidCursorException;
use Vaulter\Exceptions\Drive\CannotDeleteHeadRevisionException;
use Vaulter\Exceptions\Drive\DriveArchivedException;
use Vaulter\Exceptions\Drive\InvalidDriveStateException;
use Vaulter\Exceptions\Drive\QuotaExceededException;
use Vaulter\Exceptions\Drive\RevisionPinnedException;
use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Exceptions\Nodes\InvalidNodeNameException;
use Vaulter\Exceptions\Nodes\InvalidPropertiesException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\NodeNotTrashedException;
use Vaulter\Exceptions\Nodes\NodeTrashedByAncestorException;
use Vaulter\Exceptions\Nodes\NotAFileException;
use Vaulter\Exceptions\Nodes\RestoreDestinationChangedException;
use Vaulter\Exceptions\Nodes\RestoreDestinationRequiredException;
use Vaulter\Exceptions\Nodes\SequenceOverflowException;
use Vaulter\Exceptions\Nodes\StaleNodeException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Exceptions\Nodes\TreeTraversalIncompleteException;
use Vaulter\Exceptions\Nodes\UnsupportedNodeKindException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Exceptions\Profiles\UnknownProfileException;

/**
 * The problem codes of the drive concepts still located in core (D39): nodes, revisions, quotas, access and
 * sharing. Declared by the drive module, so a switched-off drive takes them out of the catalogue; their lines
 * stay in the core lang files until P4 moves the code. A stable public contract, so a code is added here, in
 * lang/{en,ru}/problems.php and in the reference docs together.
 *
 * Visibility (404), authentication and authorization codes are never cached as an idempotency receipt; a
 * deterministic domain refusal (409, 412, 413, 422) is (D25).
 */
#[Internal]
final class DriveSliceProblemCodes implements ProblemCodeSource
{
    public function translationNamespace(): string
    {
        return 'vaulter';
    }

    public function codes(): array
    {
        return [
            'cannot_delete_head_revision' => new ProblemSpec(409, CannotDeleteHeadRevisionException::class, receipt: ReceiptPolicy::Cacheable),
            // Pending scan or quarantine: the same request may succeed later, so the refusal is not an outcome.
            'content_unavailable' => new ProblemSpec(423, ContentUnavailableException::class, Retryable::WithBackoff),
            'cursor_expired' => new ProblemSpec(410, CursorExpiredException::class, Retryable::AfterRefetch),
            'drive_archived' => new ProblemSpec(409, DriveArchivedException::class, receipt: ReceiptPolicy::Cacheable),
            'drive_not_found' => new ProblemSpec(404, StorageNotFoundException::class),
            'file_size_limit_exceeded' => new ProblemSpec(413, FileSizeLimitExceededException::class, receipt: ReceiptPolicy::Cacheable),
            'folder_children_limit_exceeded' => new ProblemSpec(409, FolderChildrenLimitExceededException::class, receipt: ReceiptPolicy::Cacheable),
            'grant_not_found' => new ProblemSpec(404),
            'invalid_cursor' => new ProblemSpec(400, InvalidCursorException::class),
            'invalid_destination' => new ProblemSpec(409, InvalidDestinationException::class, receipt: ReceiptPolicy::Cacheable),
            'invalid_drive_state' => new ProblemSpec(409, InvalidDriveStateException::class, receipt: ReceiptPolicy::Cacheable),
            'invalid_node_name' => new ProblemSpec(422, InvalidNodeNameException::class, receipt: ReceiptPolicy::Cacheable),
            'invalid_properties' => new ProblemSpec(422, InvalidPropertiesException::class, receipt: ReceiptPolicy::Cacheable),
            'node_name_conflict' => new ProblemSpec(409, NodeNameConflictException::class, receipt: ReceiptPolicy::Cacheable),
            'node_not_found' => new ProblemSpec(404, NodeNotFoundException::class),
            'node_not_trashed' => new ProblemSpec(409, NodeNotTrashedException::class, receipt: ReceiptPolicy::Cacheable),
            'node_sequence_exhausted' => new ProblemSpec(409, SequenceOverflowException::class, receipt: ReceiptPolicy::Cacheable),
            'node_sequence_mismatch' => new ProblemSpec(412, StaleNodeException::class, Retryable::AfterRefetch, ReceiptPolicy::Cacheable),
            'node_trashed_by_ancestor' => new ProblemSpec(409, NodeTrashedByAncestorException::class, receipt: ReceiptPolicy::Cacheable),
            'not_a_file' => new ProblemSpec(422, NotAFileException::class, receipt: ReceiptPolicy::Cacheable),
            // A 403 of the profile policy is an authorization refusal: never cached (D25).
            'profile_restriction' => new ProblemSpec(403, ProfileRestrictionException::class),
            'quota_exceeded' => new ProblemSpec(413, QuotaExceededException::class, receipt: ReceiptPolicy::Cacheable),
            // The destination may come back between two attempts of the same request: not a deterministic outcome, never cached (D25).
            'restore_destination_changed' => new ProblemSpec(409, RestoreDestinationChangedException::class, Retryable::AfterRefetch),
            'restore_destination_required' => new ProblemSpec(409, RestoreDestinationRequiredException::class, receipt: ReceiptPolicy::Cacheable),
            'revision_not_found' => new ProblemSpec(404),
            'revision_pinned' => new ProblemSpec(409, RevisionPinnedException::class, receipt: ReceiptPolicy::Cacheable),
            'share_link_ttl_exceeded' => new ProblemSpec(422, ShareLinkTtlExceededException::class, receipt: ReceiptPolicy::Cacheable),
            'share_not_found' => new ProblemSpec(404, ShareNotFoundException::class),
            'share_password_required' => new ProblemSpec(401, SharePasswordRequiredException::class),
            'share_role_not_allowed' => new ProblemSpec(422, ShareRoleNotAllowedException::class, receipt: ReceiptPolicy::Cacheable),
            'tree_depth_limit_exceeded' => new ProblemSpec(409, TreeDepthLimitExceededException::class, receipt: ReceiptPolicy::Cacheable),
            'tree_traversal_incomplete' => new ProblemSpec(409, TreeTraversalIncompleteException::class, receipt: ReceiptPolicy::Cacheable),
            'unknown_profile' => new ProblemSpec(422, UnknownProfileException::class, receipt: ReceiptPolicy::Cacheable),
            'unknown_role' => new ProblemSpec(422, UnknownRoleException::class, receipt: ReceiptPolicy::Cacheable),
            'unsupported_node_kind' => new ProblemSpec(409, UnsupportedNodeKindException::class, receipt: ReceiptPolicy::Cacheable),
        ];
    }
}

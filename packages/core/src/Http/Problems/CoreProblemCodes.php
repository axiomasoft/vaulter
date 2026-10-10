<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Access\UnknownRoleException;
use Vaulter\Exceptions\Changes\CursorExpiredException;
use Vaulter\Exceptions\Changes\InvalidCursorException;
use Vaulter\Exceptions\Database\ConcurrentUpdateException;
use Vaulter\Exceptions\Database\WriteOutsideOperationException;
use Vaulter\Exceptions\Drive\CannotDeleteHeadRevisionException;
use Vaulter\Exceptions\Drive\DriveArchivedException;
use Vaulter\Exceptions\Drive\InvalidDriveStateException;
use Vaulter\Exceptions\Drive\QuotaExceededException;
use Vaulter\Exceptions\Drive\RevisionPinnedException;
use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Exceptions\Http\PreconditionRequiredException;
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
use Vaulter\Exceptions\Policy\FileSizeLimitExceededException;
use Vaulter\Exceptions\Policy\FolderChildrenLimitExceededException;
use Vaulter\Exceptions\Policy\TreeDepthLimitExceededException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Exceptions\Profiles\UnknownProfileException;
use Vaulter\Exceptions\Registry\DuplicateNodeContentLifecycleException;
use Vaulter\Exceptions\Registry\RegistryFrozenException;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Exceptions\Sharing\ShareLinkTtlExceededException;
use Vaulter\Exceptions\Sharing\ShareNotFoundException;
use Vaulter\Exceptions\Sharing\SharePasswordRequiredException;
use Vaulter\Exceptions\Sharing\ShareRoleNotAllowedException;
use Vaulter\Exceptions\Webhooks\InvalidWebhookSignatureException;
use Vaulter\Exceptions\Webhooks\WebhookDeliveryNotFoundException;
use Vaulter\Exceptions\Webhooks\WebhookDeliveryNotRedeliverableException;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;

/**
 * The problem codes of the core package: a stable public contract, so a code is added
 * here, in lang/{en,ru}/problems.php and in the reference docs together.
 */
final class CoreProblemCodes implements ProblemCodeSource
{
    public function package(): string
    {
        return 'core';
    }

    public function translationNamespace(): string
    {
        return 'vaulter';
    }

    public function codes(): array
    {
        return [
            'actor_required' => ['status' => 401, 'exception' => MissingActorException::class],
            'cannot_delete_head_revision' => ['status' => 409, 'exception' => CannotDeleteHeadRevisionException::class],
            'concurrent_update' => ['status' => 409, 'exception' => ConcurrentUpdateException::class],
            'content_unavailable' => ['status' => 423, 'exception' => ContentUnavailableException::class],
            'cursor_expired' => ['status' => 410, 'exception' => CursorExpiredException::class],
            'drive_archived' => ['status' => 409, 'exception' => DriveArchivedException::class],
            'drive_not_found' => ['status' => 404, 'exception' => StorageNotFoundException::class],
            'duplicate_registration' => ['status' => 500, 'exception' => DuplicateNodeContentLifecycleException::class],
            'file_size_limit_exceeded' => ['status' => 413, 'exception' => FileSizeLimitExceededException::class],
            'folder_children_limit_exceeded' => ['status' => 409, 'exception' => FolderChildrenLimitExceededException::class],
            'grant_not_found' => ['status' => 404, 'exception' => null],
            'internal_error' => ['status' => 500, 'exception' => null],
            'invalid_cursor' => ['status' => 400, 'exception' => InvalidCursorException::class],
            'invalid_destination' => ['status' => 409, 'exception' => InvalidDestinationException::class],
            'invalid_drive_state' => ['status' => 409, 'exception' => InvalidDriveStateException::class],
            'invalid_node_name' => ['status' => 422, 'exception' => InvalidNodeNameException::class],
            'invalid_properties' => ['status' => 422, 'exception' => InvalidPropertiesException::class],
            'invalid_webhook_signature' => ['status' => 400, 'exception' => InvalidWebhookSignatureException::class],
            'method_not_allowed' => ['status' => 405, 'exception' => MethodNotAllowedHttpException::class],
            'node_name_conflict' => ['status' => 409, 'exception' => NodeNameConflictException::class],
            'node_not_found' => ['status' => 404, 'exception' => NodeNotFoundException::class],
            'node_not_trashed' => ['status' => 409, 'exception' => NodeNotTrashedException::class],
            'node_sequence_exhausted' => ['status' => 409, 'exception' => SequenceOverflowException::class],
            'node_sequence_mismatch' => ['status' => 412, 'exception' => StaleNodeException::class],
            'node_trashed_by_ancestor' => ['status' => 409, 'exception' => NodeTrashedByAncestorException::class],
            'not_a_file' => ['status' => 422, 'exception' => NotAFileException::class],
            'payload_too_large' => ['status' => 413, 'exception' => PostTooLargeException::class],
            'permission_denied' => ['status' => 403, 'exception' => PermissionDeniedException::class],
            'precondition_required' => ['status' => 428, 'exception' => PreconditionRequiredException::class],
            'profile_restriction' => ['status' => 403, 'exception' => ProfileRestrictionException::class],
            'quota_exceeded' => ['status' => 413, 'exception' => QuotaExceededException::class],
            'rate_limited' => ['status' => 429, 'exception' => ThrottleRequestsException::class],
            'registry_frozen' => ['status' => 500, 'exception' => RegistryFrozenException::class],
            'restore_destination_changed' => ['status' => 409, 'exception' => RestoreDestinationChangedException::class],
            'restore_destination_required' => ['status' => 409, 'exception' => RestoreDestinationRequiredException::class],
            'revision_not_found' => ['status' => 404, 'exception' => null],
            'revision_pinned' => ['status' => 409, 'exception' => RevisionPinnedException::class],
            'route_not_found' => ['status' => 404, 'exception' => NotFoundHttpException::class],
            'share_link_ttl_exceeded' => ['status' => 422, 'exception' => ShareLinkTtlExceededException::class],
            'share_not_found' => ['status' => 404, 'exception' => ShareNotFoundException::class],
            'share_password_required' => ['status' => 401, 'exception' => SharePasswordRequiredException::class],
            'share_role_not_allowed' => ['status' => 422, 'exception' => ShareRoleNotAllowedException::class],
            'tree_depth_limit_exceeded' => ['status' => 409, 'exception' => TreeDepthLimitExceededException::class],
            'tree_traversal_incomplete' => ['status' => 409, 'exception' => TreeTraversalIncompleteException::class],
            'unknown_profile' => ['status' => 422, 'exception' => UnknownProfileException::class],
            'unknown_role' => ['status' => 422, 'exception' => UnknownRoleException::class],
            'unsupported_node_kind' => ['status' => 409, 'exception' => UnsupportedNodeKindException::class],
            'upgrade_preflight_failed' => ['status' => 409, 'exception' => UpgradePreflightException::class],
            'validation_failed' => ['status' => 422, 'exception' => ValidationException::class],
            'webhook_delivery_not_found' => ['status' => 404, 'exception' => WebhookDeliveryNotFoundException::class],
            'webhook_delivery_not_redeliverable' => ['status' => 409, 'exception' => WebhookDeliveryNotRedeliverableException::class],
            'webhook_endpoint_not_found' => ['status' => 404, 'exception' => WebhookEndpointNotFoundException::class],
            'write_outside_operation' => ['status' => 500, 'exception' => WriteOutsideOperationException::class],
        ];
    }
}

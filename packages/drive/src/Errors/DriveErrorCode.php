<?php

declare(strict_types=1);

namespace Vaulter\Drive\Errors;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Canonical Packman error codes owned by `axiomasoft/vaulter-drive`.
 */
#[Api(since: '1.0')]
enum DriveErrorCode: string implements ErrorCode
{
    case CannotDeleteHeadRevision = 'drive.cannot_delete_head_revision';
    case ContentUnavailable = 'drive.content_unavailable';
    case CrossDriveOperation = 'drive.cross_drive_operation';
    case CursorExpired = 'drive.cursor_expired';
    case DriveArchived = 'drive.drive_archived';
    case DriveNotFound = 'drive.drive_not_found';
    case DuplicateRegistration = 'drive.duplicate_registration';
    case FileSizeLimitExceeded = 'drive.file_size_limit_exceeded';
    case FolderChildrenLimitExceeded = 'drive.folder_children_limit_exceeded';
    case GrantNotFound = 'drive.grant_not_found';
    case InvalidCursor = 'drive.invalid_cursor';
    case InvalidDestination = 'drive.invalid_destination';
    case InvalidDriveState = 'drive.invalid_drive_state';
    case InvalidNodeName = 'drive.invalid_node_name';
    case InvalidProfile = 'drive.invalid_profile';
    case InvalidProperties = 'drive.invalid_properties';
    case MissingPermissionDriver = 'drive.missing_permission_driver';
    case NodeMoveCycle = 'drive.node_move_cycle';
    case NodeNameConflict = 'drive.node_name_conflict';
    case NodeNotFound = 'drive.node_not_found';
    case NodeNotTrashed = 'drive.node_not_trashed';
    case NodeSequenceExhausted = 'drive.node_sequence_exhausted';
    case NodeSequenceMismatch = 'drive.node_sequence_mismatch';
    case NodeTrashedByAncestor = 'drive.node_trashed_by_ancestor';
    case NotAFile = 'drive.not_a_file';
    case PermissionDenied = 'drive.permission_denied';
    case ProfileRestriction = 'drive.profile_restriction';
    case QuotaExceeded = 'drive.quota_exceeded';
    case RestoreDestinationChanged = 'drive.restore_destination_changed';
    case RestoreDestinationRequired = 'drive.restore_destination_required';
    case RevisionNotFound = 'drive.revision_not_found';
    case RevisionPinned = 'drive.revision_pinned';
    case ShareLinkTtlExceeded = 'drive.share_link_ttl_exceeded';
    case ShareNotFound = 'drive.share_not_found';
    case SharePasswordRequired = 'drive.share_password_required';
    case ShareRoleNotAllowed = 'drive.share_role_not_allowed';
    case TreeDepthLimitExceeded = 'drive.tree_depth_limit_exceeded';
    case TreeTraversalIncomplete = 'drive.tree_traversal_incomplete';
    case UnknownProfile = 'drive.unknown_profile';
    case UnknownRole = 'drive.unknown_role';
    case UnsupportedNodeKind = 'drive.unsupported_node_kind';
    case UploadConflict = 'drive.upload_conflict';
    case UploadNotFound = 'drive.upload_not_found';
    case UploadOffsetMismatch = 'drive.upload_offset_mismatch';
    case UploadRejected = 'drive.upload_rejected';
    case ValidationFailed = 'drive.validation_failed';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Drive\Errors;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 problem definitions for all `axiomasoft/vaulter-drive` error codes.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class DriveProblemDefinitions implements ProblemDefinitionProvider
{
    private const string URN_PREFIX = 'urn:packman:problem:';

    /** @return iterable<ProblemDefinition> */
    #[Override]
    public function definitions(): iterable
    {
        foreach (DriveErrorCode::cases() as $code) {
            [$status, $title, $retryable, $category, $detailKeys] = self::metadata($code);

            yield new ProblemDefinition(
                code: $code,
                type: self::URN_PREFIX.$code->value,
                defaultStatus: $status,
                title: $title,
                retryable: $retryable,
                category: $category,
                detailKeys: $detailKeys,
            );
        }
    }

    /** @return array{0: int, 1: string, 2: bool, 3: ErrorCategory, 4: list<string>} */
    private static function metadata(DriveErrorCode $code): array
    {
        return match ($code) {
            DriveErrorCode::CannotDeleteHeadRevision => [409, 'Cannot delete head revision', false, ErrorCategory::Conflict, []],
            DriveErrorCode::ContentUnavailable => [423, 'Content unavailable', true, ErrorCategory::Validation, []],
            DriveErrorCode::CrossDriveOperation => [409, 'Cross-drive operation rejected', false, ErrorCategory::Conflict, []],
            DriveErrorCode::CursorExpired => [410, 'Change cursor expired', false, ErrorCategory::Conflict, []],
            DriveErrorCode::DriveArchived => [409, 'Drive is archived', false, ErrorCategory::Conflict, []],
            DriveErrorCode::DriveNotFound => [404, 'Drive not found', false, ErrorCategory::NotFound, []],
            DriveErrorCode::DuplicateRegistration => [500, 'Duplicate lifecycle registration', false, ErrorCategory::Internal, []],
            DriveErrorCode::FileSizeLimitExceeded => [413, 'File size limit exceeded', false, ErrorCategory::Validation, ['limit']],
            DriveErrorCode::FolderChildrenLimitExceeded => [409, 'Folder children limit exceeded', false, ErrorCategory::Conflict, ['limit']],
            DriveErrorCode::GrantNotFound => [404, 'Grant not found', false, ErrorCategory::NotFound, []],
            DriveErrorCode::InvalidCursor => [400, 'Invalid change cursor', false, ErrorCategory::Validation, []],
            DriveErrorCode::InvalidDestination => [409, 'Invalid destination', false, ErrorCategory::Conflict, []],
            DriveErrorCode::InvalidDriveState => [409, 'Invalid drive state', false, ErrorCategory::Conflict, []],
            DriveErrorCode::InvalidNodeName => [422, 'Invalid node name', false, ErrorCategory::Validation, ['reason', 'errors']],
            DriveErrorCode::InvalidProfile => [500, 'Invalid storage profile', false, ErrorCategory::Internal, []],
            DriveErrorCode::InvalidProperties => [422, 'Invalid node properties', false, ErrorCategory::Validation, ['reason']],
            DriveErrorCode::MissingPermissionDriver => [500, 'Missing or forbidden permission driver', false, ErrorCategory::Internal, []],
            DriveErrorCode::NodeMoveCycle => [409, 'Node move would create a cycle', false, ErrorCategory::Conflict, []],
            DriveErrorCode::NodeNameConflict => [409, 'Node name conflict', false, ErrorCategory::Conflict, ['errors']],
            DriveErrorCode::NodeNotFound => [404, 'Node not found', false, ErrorCategory::NotFound, []],
            DriveErrorCode::NodeNotTrashed => [409, 'Node is not trashed', false, ErrorCategory::Conflict, []],
            DriveErrorCode::NodeSequenceExhausted => [409, 'Node sequence exhausted', false, ErrorCategory::Conflict, []],
            DriveErrorCode::NodeSequenceMismatch => [412, 'Node sequence mismatch', true, ErrorCategory::Validation, ['node_id']],
            DriveErrorCode::NodeTrashedByAncestor => [409, 'Node is trashed by an ancestor', false, ErrorCategory::Conflict, ['context']],
            DriveErrorCode::NotAFile => [422, 'Node is not a file', false, ErrorCategory::Validation, []],
            DriveErrorCode::PermissionDenied => [403, 'Permission denied', false, ErrorCategory::Authorization, []],
            DriveErrorCode::ProfileRestriction => [403, 'Profile restriction violated', false, ErrorCategory::Authorization, []],
            DriveErrorCode::QuotaExceeded => [413, 'Drive quota exceeded', false, ErrorCategory::Validation, []],
            DriveErrorCode::RestoreDestinationChanged => [409, 'Restore destination changed', false, ErrorCategory::Conflict, []],
            DriveErrorCode::RestoreDestinationRequired => [409, 'Restore destination required', false, ErrorCategory::Conflict, []],
            DriveErrorCode::RevisionNotFound => [404, 'Revision not found', false, ErrorCategory::NotFound, []],
            DriveErrorCode::RevisionPinned => [409, 'Revision is pinned', false, ErrorCategory::Conflict, []],
            DriveErrorCode::ShareLinkTtlExceeded => [422, 'Share link TTL exceeded', false, ErrorCategory::Validation, ['limit_days']],
            DriveErrorCode::ShareNotFound => [404, 'Share link not found', false, ErrorCategory::NotFound, []],
            DriveErrorCode::SharePasswordRequired => [401, 'Share link password required', false, ErrorCategory::Authentication, []],
            DriveErrorCode::ShareRoleNotAllowed => [422, 'Share link role not allowed', false, ErrorCategory::Validation, []],
            DriveErrorCode::TreeDepthLimitExceeded => [409, 'Tree depth limit exceeded', false, ErrorCategory::Conflict, ['limit']],
            DriveErrorCode::TreeTraversalIncomplete => [409, 'Tree traversal incomplete', false, ErrorCategory::Conflict, []],
            DriveErrorCode::UnknownProfile => [422, 'Unknown storage profile', false, ErrorCategory::Validation, []],
            DriveErrorCode::UnknownRole => [422, 'Unknown access role', false, ErrorCategory::Validation, []],
            DriveErrorCode::UnsupportedNodeKind => [409, 'Unsupported node kind', false, ErrorCategory::Conflict, []],
            DriveErrorCode::UploadConflict => [409, 'Upload session conflict', false, ErrorCategory::Conflict, []],
            DriveErrorCode::UploadNotFound => [404, 'Upload session not found', false, ErrorCategory::NotFound, []],
            DriveErrorCode::UploadOffsetMismatch => [409, 'Upload offset mismatch', true, ErrorCategory::Conflict, []],
            DriveErrorCode::UploadRejected => [422, 'Upload rejected', false, ErrorCategory::Validation, ['reason']],
            DriveErrorCode::ValidationFailed => [422, 'Validation failed', false, ErrorCategory::Validation, ['errors']],
        };
    }
}

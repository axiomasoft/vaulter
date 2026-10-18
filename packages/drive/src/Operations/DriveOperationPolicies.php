<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Vaulter\Attributes\Internal;
use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Operations\OperationPolicySource;

/**
 * The policies of the drive's mutating operations (D47, knowledge p6-design#coverage): nodes, trash, files, uploads,
 * access, shares and the drive lifecycle. `drive.uploads.append` is the Tus PATCH / S3 part protocol, which runs no
 * operation of its own: the policy names its routes.
 */
#[Internal]
final class DriveOperationPolicies implements OperationPolicySource
{
    public function policies(): array
    {
        return [
            'drive.folders.create' => new OperationPolicy('drive.node', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['folders.store']),
            'drive.nodes.rename' => new OperationPolicy('drive.node', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['nodes.update']),
            'drive.nodes.move' => new OperationPolicy('drive.node', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['nodes.move']),
            'drive.nodes.copy' => new OperationPolicy('drive.node', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['nodes.copy']),
            'drive.nodes.set_properties' => new OperationPolicy('drive.node', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['nodes.properties.update']),
            'drive.nodes.star' => new OperationPolicy('drive.node_user_state', ConcurrencyPolicy::Natural, IdempotencyPolicy::Natural, ['nodes.star']),
            'drive.nodes.unstar' => new OperationPolicy('drive.node_user_state', ConcurrencyPolicy::Natural, IdempotencyPolicy::Natural, ['nodes.unstar']),
            'drive.nodes.trash' => new OperationPolicy('drive.node', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['nodes.trash']),
            'drive.trash.restore' => new OperationPolicy('drive.node', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['nodes.restore']),
            'drive.trash.purge' => new OperationPolicy('drive.node', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['nodes.purge', 'trash.empty']),
            // A direct upload and a direct new revision of a file run in one operation of their own (FileOperations).
            'drive.files.upload' => new OperationPolicy('drive.node', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['files.store']),
            'drive.files.add_revision' => new OperationPolicy('drive.node', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['files.revisions.store']),
            'drive.files.restore_revision' => new OperationPolicy('drive.node', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['files.revisions.restore']),
            'drive.files.delete_revision' => new OperationPolicy('drive.revision', ConcurrencyPolicy::RowIdentity, IdempotencyPolicy::Receipt, ['files.revisions.destroy']),
            'drive.files.set_revision_retention' => new OperationPolicy('drive.revision', ConcurrencyPolicy::Natural, IdempotencyPolicy::Natural, ['files.revisions.update']),
            'drive.uploads.initiate' => new OperationPolicy('drive.upload', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['uploads.store']),
            'drive.uploads.complete' => new OperationPolicy('drive.upload', ConcurrencyPolicy::StateMachine, IdempotencyPolicy::Natural, ['uploads.complete']),
            'drive.uploads.abort' => new OperationPolicy('drive.upload', ConcurrencyPolicy::StateMachine, IdempotencyPolicy::Natural, ['uploads.tus.destroy']),
            'drive.uploads.append' => new OperationPolicy('drive.upload', ConcurrencyPolicy::StateMachine, IdempotencyPolicy::Protocol, ['uploads.tus.patch', 'uploads.parts.store']),
            'drive.access.grant' => new OperationPolicy('drive.node_access', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['grants.store']),
            'drive.access.revoke' => new OperationPolicy('drive.node_access', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['grants.destroy']),
            'drive.access.restrict_inheritance' => new OperationPolicy('drive.node_access', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['access.inheritance.restrict']),
            'drive.access.restore_inheritance' => new OperationPolicy('drive.node_access', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['access.inheritance.restore']),
            // A host's invalidation signal (AccessInvalidation): records an event only, and a repeat asks for the same re-check.
            'drive.access.invalidate' => new OperationPolicy('drive.node_access', ConcurrencyPolicy::Natural, IdempotencyPolicy::Natural),
            'drive.shares.create' => new OperationPolicy('drive.share', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['shares.store']),
            'drive.shares.update' => new OperationPolicy('drive.share', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['shares.update']),
            'drive.shares.rotate' => new OperationPolicy('drive.share', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['shares.rotate']),
            'drive.shares.revoke' => new OperationPolicy('drive.share', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['shares.destroy']),
            'drive.drives.archive' => new OperationPolicy('drive.drive', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['drives.archive']),
            'drive.drives.unarchive' => new OperationPolicy('drive.drive', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['drives.unarchive']),
            'drive.drives.delete' => new OperationPolicy('drive.drive', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['drives.destroy']),
        ];
    }
}

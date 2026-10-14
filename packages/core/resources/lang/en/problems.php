<?php

declare(strict_types=1);

return [
    'actor_required' => [
        'title' => 'Authentication required',
        'detail' => 'The request needs an authenticated actor.',
    ],
    'cannot_delete_head_revision' => [
        'title' => 'Head revision cannot be deleted',
        'detail' => 'The current revision cannot be deleted.',
    ],
    'concurrent_update' => [
        'title' => 'Concurrent update',
        'detail' => 'The request conflicted with a concurrent update; retry it.',
    ],
    'content_unavailable' => [
        'title' => 'Content unavailable',
        'detail' => 'The content is not available for download.',
    ],
    'cursor_expired' => [
        'title' => 'Cursor expired',
        'detail' => 'The cursor is older than the retained change feed; restart from a fresh listing.',
    ],
    'dependency_unavailable' => [
        'title' => 'Dependency unavailable',
        'detail' => 'A service the request depends on is unavailable; retry later.',
    ],
    'drive_archived' => [
        'title' => 'Drive is archived',
        'detail' => 'An archived drive is read-only.',
    ],
    'drive_not_found' => [
        'title' => 'Drive not found',
        'detail' => 'The drive does not exist or is not visible to you.',
    ],
    'duplicate_registration' => [
        'title' => 'Duplicate registration',
        'detail' => 'The same key was registered twice.',
    ],
    'extension_failed' => [
        'title' => 'Extension failed',
        'detail' => 'An installed module\'s extension broke its contract; the operation did not run or was rolled back.',
    ],
    'file_size_limit_exceeded' => [
        'title' => 'File too large',
        'detail' => 'The file exceeds the limit of :limit bytes.',
    ],
    'folder_children_limit_exceeded' => [
        'title' => 'Folder is full',
        'detail' => 'The folder already holds the maximum of :limit nodes.',
    ],
    'grant_not_found' => [
        'title' => 'Grant not found',
        'detail' => 'The grant does not exist or is not visible to you.',
    ],
    'idempotency_key_reused' => [
        'title' => 'Idempotency key reused',
        'detail' => 'The idempotency key was already used for a different request; send a new key.',
    ],
    'internal_error' => [
        'title' => 'Internal server error',
    ],
    'invalid_cursor' => [
        'title' => 'Invalid cursor',
        'detail' => 'The cursor is malformed or does not belong to this feed.',
    ],
    'invalid_destination' => [
        'title' => 'Invalid destination',
        'detail' => 'The destination is not a live folder of this drive.',
    ],
    'invalid_drive_state' => [
        'title' => 'Invalid drive state',
        'detail' => 'The drive state does not allow this transition.',
    ],
    'invalid_node_name' => [
        'title' => 'Invalid node name',
        'detail' => 'The name breaks the naming rules.',
    ],
    'invalid_properties' => [
        'title' => 'Invalid properties',
        'detail' => 'The properties patch breaks the contract.',
    ],
    'invalid_webhook_signature' => [
        'title' => 'Invalid webhook signature',
        'detail' => 'The webhook signature did not verify.',
    ],
    'method_not_allowed' => [
        'title' => 'Method not allowed',
        'detail' => 'The endpoint does not support this HTTP method; see the Allow header.',
    ],
    'module_graph_invalid' => [
        'title' => 'Module graph invalid',
        'detail' => 'The installed modules do not form a valid graph; no module is active.',
    ],
    'module_not_ready' => [
        'title' => 'Module not ready',
        'detail' => 'The module serving this operation is inactive or its schema is not installed yet.',
    ],
    'node_name_conflict' => [
        'title' => 'Node name conflict',
        'detail' => 'A node with this name already exists in the folder.',
    ],
    'node_not_found' => [
        'title' => 'Node not found',
        'detail' => 'The node does not exist or is not visible to you.',
    ],
    'node_not_trashed' => [
        'title' => 'Node is not in the trash',
        'detail' => 'Only a trashed node can be restored or deleted permanently.',
    ],
    'node_sequence_exhausted' => [
        'title' => 'Node sequence exhausted',
        'detail' => 'The node change counter is exhausted.',
    ],
    'node_sequence_mismatch' => [
        'title' => 'Node changed',
        'detail' => 'The node changed since you read it; reload it and retry.',
    ],
    'node_trashed_by_ancestor' => [
        'title' => 'Node trashed with its folder',
        'detail' => 'Restore the folder that was trashed together with this node.',
    ],
    'not_a_file' => [
        'title' => 'Not a file',
        'detail' => 'The operation needs a file node.',
    ],
    'operation_in_progress' => [
        'title' => 'Operation in progress',
        'detail' => 'A request with the same idempotency key is still running; retry later.',
    ],
    'payload_too_large' => [
        'title' => 'Payload too large',
        'detail' => 'The request body exceeds the size the server accepts.',
    ],
    'permission_denied' => [
        'title' => 'Permission denied',
        'detail' => 'You are not allowed to perform this action.',
    ],
    'precondition_required' => [
        'title' => 'Precondition required',
        'detail' => 'The :header header is required for this request.',
    ],
    'profile_restriction' => [
        'title' => 'Restricted by the drive profile',
        'detail' => 'The drive profile forbids this operation.',
    ],
    'quota_exceeded' => [
        'title' => 'Quota exceeded',
        'detail' => 'The upload would exceed the drive quota.',
    ],
    'rate_limited' => [
        'title' => 'Too many requests',
        'detail' => 'Too many requests; retry after the interval in the Retry-After header.',
    ],
    'registry_frozen' => [
        'title' => 'Registry frozen',
        'detail' => 'A registration came after the application booted.',
    ],
    'restore_destination_changed' => [
        'title' => 'Restore destination changed',
        'detail' => 'The destination changed while restoring; retry.',
    ],
    'restore_destination_required' => [
        'title' => 'Restore destination required',
        'detail' => 'The original folder is gone; choose a destination folder.',
    ],
    'revision_not_found' => [
        'title' => 'Revision not found',
        'detail' => 'The revision does not exist or is not visible to you.',
    ],
    'revision_pinned' => [
        'title' => 'Revision is pinned',
        'detail' => 'Unpin the revision before deleting it.',
    ],
    'route_not_found' => [
        'title' => 'Route not found',
        'detail' => 'No such endpoint.',
    ],
    'share_link_ttl_exceeded' => [
        'title' => 'Share link lifetime exceeded',
        'detail' => 'The link may stay valid for at most :limit_days days.',
    ],
    'share_not_found' => [
        'title' => 'Share link not found',
        'detail' => 'The share link does not exist, has expired or is not visible to you.',
    ],
    'share_password_required' => [
        'title' => 'Share link password required',
        'detail' => 'This share link is password protected. Send its password in the X-Share-Password header.',
    ],
    'share_role_not_allowed' => [
        'title' => 'Share role not allowed',
        'detail' => 'A share link cannot carry this role for this audience.',
    ],
    'tree_depth_limit_exceeded' => [
        'title' => 'Tree depth limit exceeded',
        'detail' => 'The folder tree may not be deeper than :limit levels.',
    ],
    'tree_traversal_incomplete' => [
        'title' => 'Tree too deep to traverse',
        'detail' => 'The folder tree is deeper than a bounded traversal can read; act on a shallower folder.',
    ],
    'unknown_profile' => [
        'title' => 'Unknown profile',
        'detail' => 'No such drive profile is registered.',
    ],
    'unknown_role' => [
        'title' => 'Unknown role',
        'detail' => 'No such role is registered.',
    ],
    'unsupported_node_kind' => [
        'title' => 'Unsupported node kind',
        'detail' => 'This node kind does not support the operation.',
    ],
    'validation_failed' => [
        'title' => 'Validation failed',
        'detail' => 'The request data is invalid; see the errors field.',
    ],
    'webhook_delivery_not_found' => [
        'title' => 'Webhook delivery not found',
        'detail' => 'The delivery does not exist for this endpoint.',
    ],
    'webhook_delivery_not_redeliverable' => [
        'title' => 'Delivery cannot be redelivered',
        'detail' => 'The delivery is still being retried or its endpoint is inactive.',
    ],
    'webhook_endpoint_not_found' => [
        'title' => 'Webhook endpoint not found',
        'detail' => 'The webhook endpoint does not exist.',
    ],
    'write_outside_operation' => [
        'title' => 'Write outside an operation',
        'detail' => 'A write bypassed the Vaulter operations.',
    ],
];

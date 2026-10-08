<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Exceptions\Access\UnknownRoleException;
use Vaulter\Exceptions\Database\WriteOutsideOperationException;
use Vaulter\Exceptions\Drive\CannotDeleteHeadRevisionException;
use Vaulter\Exceptions\Drive\RevisionPinnedException;
use Vaulter\Exceptions\Nodes\InvalidNodeNameException;
use Vaulter\Exceptions\Nodes\InvalidPropertiesException;
use Vaulter\Exceptions\Nodes\UnsupportedNodeKindException;
use Vaulter\Exceptions\Policy\FileSizeLimitExceededException;
use Vaulter\Exceptions\Policy\FolderChildrenLimitExceededException;
use Vaulter\Exceptions\Policy\TreeDepthLimitExceededException;
use Vaulter\Exceptions\Registry\DuplicateNodeContentLifecycleException;
use Vaulter\Exceptions\Registry\RegistryFrozenException;
use Vaulter\Exceptions\Sharing\ShareLinkTtlExceededException;
use Vaulter\Exceptions\Sharing\ShareRoleNotAllowedException;
use Vaulter\Exceptions\VaulterException;

it('renders the documented domain code and HTTP status', function (VaulterException $exception, string $code, int $status): void {
    $path = trim(app(RoutesConfig::class)->prefix(), '/').'/__domain_status';
    Route::get($path, static function () use ($exception): never {
        throw $exception;
    });

    $this->getJson($path)->assertStatus($status)->assertJsonPath('code', $code);
    $this->get($path, ['Accept' => 'text/html'])->assertStatus($status)->assertJsonPath('code', $code);
})->with([
    'unsupported kind' => [new UnsupportedNodeKindException, 'unsupported_node_kind', 409],
    'invalid name' => [InvalidNodeNameException::forName('', InvalidNodeNameException::EMPTY), 'invalid_node_name', 422],
    'children limit' => [FolderChildrenLimitExceededException::forParent(1, 1), 'folder_children_limit_exceeded', 409],
    'depth limit' => [TreeDepthLimitExceededException::forDepth(2, 1), 'tree_depth_limit_exceeded', 409],
    'size limit' => [FileSizeLimitExceededException::forLimit(1), 'file_size_limit_exceeded', 413],
    'invalid properties' => [InvalidPropertiesException::because(InvalidPropertiesException::KEY), 'invalid_properties', 422],
    'head revision' => [CannotDeleteHeadRevisionException::forRevision(1), 'cannot_delete_head_revision', 409],
    'pinned revision' => [RevisionPinnedException::forRevision(1), 'revision_pinned', 409],
    'unknown role' => [UnknownRoleException::for('missing'), 'unknown_role', 422],
    'share role' => [ShareRoleNotAllowedException::for('owner', ShareAudience::Anyone), 'share_role_not_allowed', 422],
    'share TTL' => [ShareLinkTtlExceededException::forLimit(1), 'share_link_ttl_exceeded', 422],
    'duplicate lifecycle at boot' => [DuplicateNodeContentLifecycleException::forKind(NodeKind::Document), 'duplicate_registration', 500],
    'frozen registry at boot' => [RegistryFrozenException::forRegistry('example'), 'registry_frozen', 500],
    'integration write' => [new WriteOutsideOperationException('Node'), 'write_outside_operation', 500],
]);

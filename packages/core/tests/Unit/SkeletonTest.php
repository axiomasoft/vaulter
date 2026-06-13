<?php

declare(strict_types=1);

use DocVault\Http\Controllers\DocumentController;
use DocVault\Models\Document;
use DocVault\Models\DocumentFolder;
use DocVault\Models\DocumentPermission;
use DocVault\Models\DocumentVersion;
use DocVault\Support\DefaultStorageAdapter;
use Illuminate\Http\Request;

it('returns 501 from every document endpoint (not implemented yet)', function (): void {
    $controller = new DocumentController;
    $request = Request::create('/');

    expect($controller->index($request)->getStatusCode())->toBe(501)
        ->and($controller->store($request)->getStatusCode())->toBe(501)
        ->and($controller->show($request, 1)->getStatusCode())->toBe(501)
        ->and($controller->update($request, 1)->getStatusCode())->toBe(501)
        ->and($controller->destroy($request, 1)->getStatusCode())->toBe(501);
});

it('configures the model tables and casts', function (): void {
    expect((new Document)->getTable())->toBe('dv_documents')
        ->and((new Document)->getCasts())->toHaveKeys(['content', 'meta'])
        ->and((new DocumentVersion)->getTable())->toBe('dv_document_versions')
        ->and((new DocumentVersion)->getCasts())->toHaveKey('content')
        ->and((new DocumentFolder)->getTable())->toBe('dv_document_folders')
        ->and((new DocumentPermission)->getTable())->toBe('dv_document_permissions');
});

it('treats the default storage adapter as a passthrough', function (): void {
    $adapter = new DefaultStorageAdapter;

    $adapter->delete('path/file.png');

    expect($adapter->store('file.png', 'contents'))->toBe('file.png')
        ->and($adapter->url('path/file.png'))->toBe('path/file.png');
});

<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Exceptions\UnknownDocumentTypeException;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Drive\Exceptions\Uploads\OffsetMismatchException;
use Vaulter\Drive\Exceptions\Uploads\StagedUploadMissingException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Exceptions\Uploads\UploadContentMismatchException;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Access\UnenumerableGrantSubjectException;
use Vaulter\Exceptions\Documents\UnknownExportFormatException;
use Vaulter\Exceptions\Documents\UnsupportedDocumentContentException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Exceptions\Profiles\UnknownProfileException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Exceptions\Webhooks\BlockedOutboundUrlException;
use Vaulter\Testing\NullPermissionDriver;

it('renders each domain exception with its stable code and HTTP status', function (
    VaulterException $exception,
    string $code,
    int $status,
    array $extras = [],
): void {
    $path = trim(app(RoutesConfig::class)->prefix(), '/').'/__v24';
    Route::get($path, static function () use ($exception): never {
        throw $exception;
    });

    $response = $this->getJson($path)
        ->assertStatus($status)
        ->assertExactJson(['message' => $exception->getMessage(), 'code' => $code] + $extras);

    expect($response->status())->not->toBe(500);

    // Vaulter routes render JSON even when a browser sends an HTML Accept header.
    $this->get($path, ['Accept' => 'text/html'])
        ->assertStatus($status)
        ->assertJsonPath('code', $code);

    // Host HTML routes continue through Laravel's normal exception handler.
    expect($exception->render(Request::create('/host-page')))->toBeFalse()
        ->and((new VaulterException('infrastructure failure'))->render(Request::create($path)))->toBeFalse();

    $hostJson = Request::create('/host-page', server: ['HTTP_ACCEPT' => 'application/json']);
    $rendered = $exception->render($hostJson);
    expect($rendered)->not->toBeFalse();
    expect($rendered->getStatusCode())->toBe($status)
        ->and($rendered->getData(true))->toBe(['message' => $exception->getMessage(), 'code' => $code] + $extras);

    if ($exception instanceof OffsetMismatchException) {
        $response->assertHeader('Upload-Offset', '7')->assertHeader('Tus-Resumable', '1.0.0');
    }
})->with([
    'permission denied' => [PermissionDeniedException::forAbility('update'), 'permission_denied', 403],
    'name conflict' => [$nameConflict = NodeNameConflictException::forName('duplicate', null), 'node_name_conflict', 409, ['errors' => ['name' => [$nameConflict->getMessage()]]]],
    'move cycle' => [NodeCycleException::forMove(1, 2), 'node_move_cycle', 409],
    'cross drive' => [CrossDriveOperationException::between(1, 2), 'cross_drive_operation', 409],
    'quota' => [QuotaExceededException::forStorage(1, 10, 9, 2), 'quota_exceeded', 413],
    'upload conflict' => [UploadConflictException::forSession('upload'), 'upload_conflict', 409],
    'staged upload missing' => [StagedUploadMissingException::forSession('upload', 7), 'upload_conflict', 409],
    'offset mismatch' => [new OffsetMismatchException(serverOffset: 7), 'upload_offset_mismatch', 409],
    'mime mismatch' => [UploadContentMismatchException::forSession('upload', 'image/png'), 'upload_rejected', 422, ['reason' => 'mime_mismatch']],
    'not a document' => [NotADocumentException::forNode(1), 'not_a_document', 409],
    'unknown document type' => [UnknownDocumentTypeException::forType('unknown', ['tiptap']), 'unknown_document_type', 422],
    'invalid tiptap' => [InvalidDocumentContentException::missingDocNode(), 'invalid_document_content', 422, ['reason' => 'invalid_tiptap']],
    'document revision missing' => [DocumentRevisionNotFoundException::forNode(1, 2), 'document_revision_not_found', 404],
    'comment missing' => [CommentNotFoundException::forNode(1, 2), 'comment_not_found', 404],
    'unknown export format' => [UnknownExportFormatException::forFormat('unknown', ['html']), 'unknown_export_format', 422],
    'unsupported content' => [UnsupportedDocumentContentException::forExporter('html', 'tiptap', 'other'), 'invalid_document_content', 422, ['reason' => 'unsupported_content']],
    'blocked outbound URL' => [$blockedUrl = BlockedOutboundUrlException::scheme('file'), 'validation_failed', 422, ['errors' => ['url' => [$blockedUrl->getMessage()]]]],
    'unenumerable subject' => [$subject = UnenumerableGrantSubjectException::for(Subject::make('role', 1), NullPermissionDriver::class), 'validation_failed', 422, ['errors' => ['subject_type' => [$subject->getMessage()]]]],
    'unknown panel' => [UnknownProfileException::forName('unknown', ['default']), 'unknown_profile', 422],
    'node missing' => [fn (): VaulterException => NodeNotFoundException::forId(1), 'node_not_found', 404],
    'storage missing' => [fn (): VaulterException => StorageNotFoundException::forId('storage'), 'drive_not_found', 404],
]);

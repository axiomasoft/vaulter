<?php

declare(strict_types=1);

use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Exceptions\UnknownDocumentTypeException;
use Vaulter\Documents\Exceptions\UnknownExportFormatException;
use Vaulter\Documents\Exceptions\UnsupportedDocumentContentException;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Access\UnenumerableGrantSubjectException;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Drive\Exceptions\Uploads\OffsetMismatchException;
use Vaulter\Drive\Exceptions\Uploads\StagedUploadMissingException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Exceptions\Uploads\UploadContentMismatchException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Exceptions\Profiles\UnknownProfileException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Exceptions\Webhooks\BlockedOutboundUrlException;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Testing\NullPermissionDriver;
use Vaulter\Testing\Support\ProblemProbeRoutes;

it('renders each domain exception with its stable code and HTTP status', function (
    VaulterException $exception,
    string $code,
    int $status,
    array $extras = [],
): void {
    // Problem Details is the only HTTP rendering; the exception carries no render() of its own.
    expect(method_exists($exception, 'render'))->toBeFalse();

    // The same exception through the versioned surface: problem+json with the catalogue
    // status, and the machine-readable extras as `errors`/`meta`, never a 500.
    ProblemProbeRoutes::register(['case' => $exception]);
    $problem = $this->getJson(ProblemProbeRoutes::path('case'))
        ->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', $code)
        ->assertJsonPath('status', $status);

    expect($problem->status())->not->toBe(500)
        ->and($problem->json())->not->toHaveKey('message')
        ->and($problem->getContent())->not->toContain('[Vaulter]')
        ->and(app(ProblemCatalog::class)->all())->toHaveKey($code)
        ->and($problem->json('meta'))->toBe(array_diff_key($extras, ['errors' => true]) ?: []);
    if ($exception instanceof OffsetMismatchException) {
        $problem->assertHeader('Upload-Offset', '7')->assertHeader('Tus-Resumable', '1.0.0');
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
    'content not an object' => [InvalidDocumentContentException::notAnObject(), 'invalid_document_content', 422, ['reason' => 'invalid_json']],
    'content too large' => [InvalidDocumentContentException::tooLargeBytes(10, 5), 'invalid_document_content', 422, ['reason' => 'too_large']],
    'document type unavailable' => [new DocumentTypeUnavailableException('acme/kanban'), 'document_type_unavailable', 409, ['context' => ['type' => 'acme/kanban']]],
    'document content degraded' => [new DocumentContentDegradedException('vaulter/rich-text'), 'document_content_degraded', 409, ['context' => ['type' => 'vaulter/rich-text']]],
    'document revision missing' => [DocumentRevisionNotFoundException::forNode(1, 2), 'document_revision_not_found', 404],
    'comment missing' => [CommentNotFoundException::forNode(1, 2), 'comment_not_found', 404],
    'unknown export format' => [UnknownExportFormatException::forFormat('unknown', ['html']), 'unknown_export_format', 422, ['allowed' => ['html']]],
    'unsupported content' => [UnsupportedDocumentContentException::forExporter('html', 'tiptap', 'other'), 'invalid_document_content', 422, ['reason' => 'unsupported_content']],
    'blocked outbound URL' => [$blockedUrl = BlockedOutboundUrlException::scheme('file'), 'validation_failed', 422, ['errors' => ['url' => [$blockedUrl->getMessage()]]]],
    'unenumerable subject' => [$subject = UnenumerableGrantSubjectException::for(Subject::make('role', 1), NullPermissionDriver::class), 'validation_failed', 422, ['errors' => ['subject_type' => [$subject->getMessage()]]]],
    'unknown panel' => [UnknownProfileException::forName('unknown', ['default']), 'unknown_profile', 422],
    'node missing' => [fn (): VaulterException => NodeNotFoundException::forId(1), 'node_not_found', 404],
    'storage missing' => [fn (): VaulterException => StorageNotFoundException::forId('storage'), 'drive_not_found', 404],
]);

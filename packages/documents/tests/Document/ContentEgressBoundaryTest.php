<?php

declare(strict_types=1);

use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Export\NodeExportService;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\ValueObjects\Access\Actor;

beforeEach(function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    $this->handle = DocumentTestHelpers::drive()->asSystem('document egress fixture');
    $this->node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($this->handle->model()), new CreateDocumentInput(storage: $this->handle->model(), name: 'protected', content: DocumentTestHelpers::content('secret body'), parent: DriveTestHelpers::model($this->handle->root())));
});

it('SEC-R17-inline rechecks visibility on every PHP export with a retained node', function (): void {
    $user = AccessTestHelpers::user(97);
    $context = new OperationContext($this->handle->model(), Actor::user($user), 'export');
    $grant = AccessTestHelpers::grant($this->node, RoleRegistry::READER, subjectId: 97);
    $service = app(NodeExportService::class);
    expect($service->export($context, $this->node, 'html')->content)->toContain('secret body');
    $grant->delete();
    expect(fn () => $service->export($context, $this->node, 'html'))->toThrow(NodeNotFoundException::class);
});

it('SEC-R17-inline treats forged model scope as a key and hides a foreign drive document', function (): void {
    $other = DocumentTestHelpers::drive()->asSystem('foreign drive');
    $context = OperationContext::system($other->model(), 'test foreign scope');
    $node = clone $this->node;
    $node->drive_id = $other->model()->id;
    expect(fn () => app(NodeExportService::class)->export($context, $node, 'html'))->toThrow(NodeNotFoundException::class);
});

it('SEC-R17-inline serves only the actual head and refuses trash on PHP export', function (): void {
    $context = OperationContext::system($this->handle->model(), 'export fixture');
    Node::query()->whereKey($this->node->id)->update(['trashed_at' => now()]);
    expect(fn () => app(NodeExportService::class)->export($context, $this->node, 'html'))->toThrow(NodeNotFoundException::class);
});

<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

beforeEach(function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    $this->handle = DocumentTestHelpers::drive()->asSystem('document egress fixture');
    $this->node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($this->handle->model()), new CreateDocumentInput(storage: $this->handle->model(), name: 'protected', content: DocumentTestHelpers::content('secret body'), parent: DriveTestHelpers::model($this->handle->root())));
});

it('SEC-R17-inline rechecks visibility on every PHP export with a retained handle', function (): void {
    $user = AccessTestHelpers::user(97);
    $grant = AccessTestHelpers::grant($this->node, RoleRegistry::READER, subjectId: 97);
    $documents = Documents::in($this->handle->actingAs($user));
    expect($documents->export($this->node->id, 'html')->bytes)->toContain('secret body');
    app(VaulterDatabase::class)->write(fn () => $grant->delete());
    expect(fn () => $documents->export($this->node->id, 'html'))->toThrow(NodeNotFoundException::class);
});

it('SEC-R17-inline hides a document of another drive from an export', function (): void {
    $other = DocumentTestHelpers::drive()->asSystem('foreign drive');
    expect(fn () => Documents::in($other)->export($this->node->id, 'html'))->toThrow(NodeNotFoundException::class);
});

it('SEC-R17-inline refuses trash on PHP export', function (): void {
    app(VaulterDatabase::class)->write(fn () => Node::query()->whereKey($this->node->id)->update(['trashed_at' => now()]));
    expect(fn () => Documents::in($this->handle)->export($this->node->id, 'html'))->toThrow(NodeNotFoundException::class);
});

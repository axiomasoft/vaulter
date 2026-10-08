<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * PLAN2.P4.3: vaulter:make:document-type scaffolds a host-owned type and leaves
 * registration (config) to the host.
 */
beforeEach(function (): void {
    File::deleteDirectory(app_path('Vaulter'));
});

afterEach(function (): void {
    File::deleteDirectory(app_path('Vaulter'));
});

it('writes a document type class with an app/ key and does not touch the config', function (): void {
    $typesBefore = config('vaulter-documents.types');

    $this->artisan('vaulter:make:document-type', ['name' => 'Kanban'])
        ->expectsOutputToContain('KanbanDocumentType::class')
        ->assertSuccessful();

    $path = app_path('Vaulter/DocumentTypes/KanbanDocumentType.php');
    expect(File::exists($path))->toBeTrue()
        ->and(File::get($path))
        ->toContain('namespace App\\Vaulter\\DocumentTypes;')
        ->toContain('final readonly class KanbanDocumentType implements DocumentType')
        ->toContain("return 'app/kanban';")
        ->toContain('new OpaqueJsonCodec')
        ->and(config('vaulter-documents.types'))->toBe($typesBefore);
});

it('accepts a kebab-case name and refuses to overwrite without --force', function (): void {
    $this->artisan('vaulter:make:document-type', ['name' => 'task-board'])->assertSuccessful();
    expect(File::get(app_path('Vaulter/DocumentTypes/TaskBoardDocumentType.php')))->toContain("return 'app/task-board';");

    $this->artisan('vaulter:make:document-type', ['name' => 'TaskBoard'])->assertFailed();
    $this->artisan('vaulter:make:document-type', ['name' => 'TaskBoard', '--force' => true])->assertSuccessful();
});

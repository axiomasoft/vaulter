<?php

declare(strict_types=1);

it('SEC-R17 inventory keeps descriptors metadata-only and installed delivery mediated without a conditional 304 branch', function (): void {
    $root = dirname(__DIR__, 4);
    $paths = [
        'packages/core/src/Handlers/FileDescriptorFactory.php',
        'packages/core/src/Handlers/NodePresenter.php',
        'packages/core/src/Handlers/Builtin/PreviewHandler.php',
        'packages/core/src/Http/Resources/Drive/NodeResource.php',
        'packages/drive/src/Http/Controllers/DownloadController.php',
        'packages/core/src/Http/Controllers/Sharing/PublicShareController.php',
        'packages/documents/src/Export/NodeExportService.php',
        'packages/documents/src/Engines/Handlers/TipTapHandler.php',
        'packages/documents/src/Engines/Handlers/UniverHandler.php',
    ];
    foreach ($paths as $path) {
        $source = file_get_contents($root.'/'.$path);
        expect($source)->toBeString()->not->toContain('->temporaryUrl(', '->setNotModified(', '->isNotModified(', 'HTTP_NOT_MODIFIED');
    }
    expect(file_get_contents($root.'/packages/drive/src/Operations/FileOperations.php'))->toContain('$this->delivery->stream(');
    expect(file_get_contents($root.'/packages/core/src/Http/Support/DownloadHeaders.php'))->toContain('private, no-store');
});

<?php

declare(strict_types=1);

it('SEC-R17 inventory keeps descriptors metadata-only and installed delivery mediated without a conditional 304 branch', function (): void {
    $root = dirname(__DIR__, 4);
    $paths = [
        'packages/drive/src/Handlers/FileDescriptorFactory.php',
        'packages/drive/src/Handlers/NodePresenter.php',
        'packages/drive/src/Handlers/Builtin/PreviewHandler.php',
        'packages/drive/src/Http/Resources/Drive/NodeResource.php',
        'packages/drive/src/Http/Controllers/Files/DownloadFileController.php',
        'packages/drive/src/Http/Controllers/Sharing/DownloadPublicShareController.php',
        'packages/drive/src/Http/Controllers/Sharing/ShowPublicShareController.php',
        'packages/drive/src/Http/Support/ContentResponder.php',
        'packages/documents/src/Actions/Export/ExportDocumentAction.php',
        'packages/documents/src/Presentation/DocumentPresenter.php',
    ];
    foreach ($paths as $path) {
        $source = file_get_contents($root.'/'.$path);
        expect($source)->toBeString()->not->toContain('->temporaryUrl(', '->setNotModified(', '->isNotModified(', 'HTTP_NOT_MODIFIED');
    }
    expect(file_get_contents($root.'/packages/drive/src/Operations/FileOperations.php'))->toContain('$this->delivery->stream(');
    expect(file_get_contents($root.'/packages/drive/src/Http/Support/DownloadHeaders.php'))->toContain('private, no-store');
});

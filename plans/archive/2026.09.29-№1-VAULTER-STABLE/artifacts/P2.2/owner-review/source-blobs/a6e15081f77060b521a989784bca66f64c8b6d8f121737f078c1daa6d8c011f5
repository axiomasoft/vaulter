<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\ValueObject\MethodCallRename;

$root = dirname(__DIR__, 4);

return RectorConfig::configure()
    ->withPaths([
        $root.'/packages/drive/src',
        $root.'/packages/drive/tests',
        $root.'/packages/drive/routes',
        $root.'/packages/drive/config',
        $root.'/packages/documents/src',
        $root.'/packages/documents/tests',
        $root.'/packages/filament/src',
        $root.'/packages/filament/tests',
        $root.'/packages/core/tests',
    ])
    ->withImportNames(importDocBlockNames: true, importShortClasses: false)
    ->withoutParallel()
    ->withConfiguredRule(RenameClassRector::class, [
        'Vaulter\\Drive\\DriveContext' => 'Vaulter\\Drive\\DriveHandle',
        'Vaulter\\Drive\\DriveManager' => 'Vaulter\\Drive\\DriveHandleFactory',
        'Vaulter\\Drive\\StorageManager' => 'Vaulter\\Drive\\DriveProvisioner',
        'Vaulter\\Drive\\Actions\\ForceDeleteNodeAction' => 'Vaulter\\Drive\\Actions\\Trash\\PurgeNodeAction',
        'Vaulter\\Drive\\Actions\\UploadFileAction' => 'Vaulter\\Drive\\Actions\\Files\\IngestFileAction',
        'Vaulter\\Drive\\Actions\\Uploads\\CommitUploadAction' => 'Vaulter\\Drive\\Actions\\Uploads\\CompleteUploadAction',
        'Vaulter\\Drive\\Data\\CreateDriveFolderData' => 'Vaulter\\Drive\\Data\\Nodes\\CreateFolderInput',
        'Vaulter\\Drive\\Data\\UploadFileData' => 'Vaulter\\Drive\\Data\\Files\\IngestFileInput',
        'Vaulter\\Drive\\Data\\Uploads\\UploadInitData' => 'Vaulter\\Drive\\Data\\Uploads\\InitiateUploadInput',
        'Vaulter\\Drive\\Events\\StorageCreated' => 'Vaulter\\Drive\\Events\\Drives\\DriveCreated',
        'Vaulter\\Drive\\Events\\NodeCreated' => 'Vaulter\\Drive\\Events\\Nodes\\FolderCreated',
        'Vaulter\\Drive\\Events\\NodeForceDeleted' => 'Vaulter\\Drive\\Events\\Nodes\\NodePurged',
        'Vaulter\\Drive\\Events\\FileUploaded' => 'Vaulter\\Drive\\Events\\Files\\FileUploaded',
        'Vaulter\\Drive\\Events\\NodeVersionCommitted' => 'Vaulter\\Drive\\Events\\Files\\FileRevisionAdded',
        'Vaulter\\Drive\\Exceptions\\CrossStorageMoveException' => 'Vaulter\\Drive\\Exceptions\\Nodes\\CrossDriveOperationException',
        'Vaulter\\Drive\\Exceptions\\CycleException' => 'Vaulter\\Drive\\Exceptions\\Nodes\\NodeCycleException',
        'Vaulter\\Drive\\Exceptions\\QuotaExceededException' => 'Vaulter\\Drive\\Exceptions\\Quota\\QuotaExceededException',
        'Vaulter\\Drive\\Http\\Controllers\\StorageController' => 'Vaulter\\Drive\\Http\\Controllers\\Drives\\ShowDriveController',
        'Vaulter\\Drive\\Http\\Controllers\\Uploads\\TusUploadController' => 'Vaulter\\Drive\\Http\\Controllers\\Uploads\\TusProtocolController',
        'Vaulter\\Drive\\Http\\Controllers\\Uploads\\S3MultipartController' => 'Vaulter\\Drive\\Http\\Controllers\\Uploads\\S3MultipartProtocolController',
        'Vaulter\\Drive\\Http\\Resources\\StorageResource' => 'Vaulter\\Drive\\Http\\Resources\\DriveResource',
        'Vaulter\\Drive\\Http\\Resources\\FileVersionResource' => 'Vaulter\\Drive\\Http\\Resources\\FileRevisionResource',
        'Vaulter\\Drive\\Support\\Uploads\\MagicByteSniffer' => 'Vaulter\\Drive\\Uploads\\Inspection\\MimeSniffer',
        'Vaulter\\Drive\\Support\\Uploads\\StagedUpload' => 'Vaulter\\Drive\\Uploads\\StagedUpload',
        'Vaulter\\Drive\\Actions\\CreateFolderAction' => 'Vaulter\\Drive\\Actions\\Nodes\\CreateFolderAction',
        'Vaulter\\Drive\\Actions\\RenameNodeAction' => 'Vaulter\\Drive\\Actions\\Nodes\\RenameNodeAction',
        'Vaulter\\Drive\\Actions\\MoveNodeAction' => 'Vaulter\\Drive\\Actions\\Nodes\\MoveNodeAction',
        'Vaulter\\Drive\\Actions\\CopyNodeAction' => 'Vaulter\\Drive\\Actions\\Nodes\\CopyNodeAction',
        'Vaulter\\Drive\\Actions\\TrashNodeAction' => 'Vaulter\\Drive\\Actions\\Trash\\TrashNodeAction',
        'Vaulter\\Drive\\Actions\\RestoreNodeAction' => 'Vaulter\\Drive\\Actions\\Trash\\RestoreNodeAction',
        'Vaulter\\Drive\\Data\\RenameNodeData' => 'Vaulter\\Drive\\Data\\Nodes\\RenameNodeInput',
        'Vaulter\\Drive\\Data\\MoveNodeData' => 'Vaulter\\Drive\\Data\\Nodes\\MoveNodeInput',
        'Vaulter\\Drive\\Data\\CopyNodeData' => 'Vaulter\\Drive\\Data\\Nodes\\CopyNodeInput',
        'Vaulter\\Drive\\Events\\NodeRenamed' => 'Vaulter\\Drive\\Events\\Nodes\\NodeRenamed',
        'Vaulter\\Drive\\Events\\NodeMoved' => 'Vaulter\\Drive\\Events\\Nodes\\NodeMoved',
        'Vaulter\\Drive\\Events\\NodeCopied' => 'Vaulter\\Drive\\Events\\Nodes\\NodeCopied',
        'Vaulter\\Drive\\Events\\NodeTrashed' => 'Vaulter\\Drive\\Events\\Nodes\\NodeTrashed',
        'Vaulter\\Drive\\Events\\NodeRestored' => 'Vaulter\\Drive\\Events\\Nodes\\NodeRestored',
        'Vaulter\\Drive\\Events\\UploadSessionInitiated' => 'Vaulter\\Drive\\Events\\Uploads\\UploadInitiated',
        'Vaulter\\Drive\\Events\\UploadSessionAborted' => 'Vaulter\\Drive\\Events\\Uploads\\UploadAborted',
        'Vaulter\\Drive\\Events\\UploadSessionExpired' => 'Vaulter\\Drive\\Events\\Uploads\\UploadExpired',
        'Vaulter\\Drive\\Exceptions\\OffsetMismatchException' => 'Vaulter\\Drive\\Exceptions\\Uploads\\OffsetMismatchException',
        'Vaulter\\Drive\\Exceptions\\StagedUploadMissingException' => 'Vaulter\\Drive\\Exceptions\\Uploads\\StagedUploadMissingException',
        'Vaulter\\Drive\\Exceptions\\UploadConflictException' => 'Vaulter\\Drive\\Exceptions\\Uploads\\UploadConflictException',
        'Vaulter\\Drive\\Exceptions\\UploadContentMismatchException' => 'Vaulter\\Drive\\Exceptions\\Uploads\\UploadContentMismatchException',
        'Vaulter\\Drive\\Http\\Requests\\CreateFolderRequest' => 'Vaulter\\Drive\\Http\\Requests\\Folders\\CreateFolderRequest',
        'Vaulter\\Drive\\Http\\Requests\\UploadFileRequest' => 'Vaulter\\Drive\\Http\\Requests\\Files\\UploadFileRequest',
        'Vaulter\\Drive\\Http\\Requests\\CopyNodeRequest' => 'Vaulter\\Drive\\Http\\Requests\\Nodes\\CopyNodeRequest',
        'Vaulter\\Drive\\Http\\Requests\\MoveNodeRequest' => 'Vaulter\\Drive\\Http\\Requests\\Nodes\\MoveNodeRequest',
        'Vaulter\\Drive\\Http\\Requests\\RenameNodeRequest' => 'Vaulter\\Drive\\Http\\Requests\\Nodes\\RenameNodeRequest',
    ])
    ->withConfiguredRule(RenameMethodRector::class, [
        new MethodCallRename('Vaulter\\Contracts\\Drive\\DriveHandle', 'storage', 'model'),
        new MethodCallRename('Vaulter\\Contracts\\Drive\\DriveHandle', 'panel', 'profile'),
        new MethodCallRename('Vaulter\\Drive\\DriveContext', 'storage', 'model'),
        new MethodCallRename('Vaulter\\Drive\\DriveContext', 'panel', 'profile'),
    ]);

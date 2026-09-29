<?php

declare(strict_types=1);

use Aws\S3\S3Client;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const P4_BUCKET = 'vaulter-load';
const P4_PREFIX = 'tmp/uploads/';

$execute = in_array('--execute', $argv, true);
$endpoint = getenv('MINIO_ENDPOINT') ?: 'http://minio:9000';
$key = getenv('MINIO_ROOT_USER') ?: '';
$secret = getenv('MINIO_ROOT_PASSWORD') ?: '';

if ($key === '' || $secret === '') {
    fwrite(STDERR, "p4-minio-cleanup: MINIO credentials are required\n");

    exit(2);
}

$client = new S3Client([
    'version' => 'latest',
    'region' => getenv('MINIO_REGION') ?: 'us-east-1',
    'endpoint' => $endpoint,
    'use_path_style_endpoint' => true,
    'credentials' => ['key' => $key, 'secret' => $secret],
]);

$multipart = [];
$keyMarker = null;
$uploadIdMarker = null;

do {
    $args = ['Bucket' => P4_BUCKET, 'Prefix' => P4_PREFIX];

    if ($keyMarker !== null) {
        $args['KeyMarker'] = $keyMarker;
    }

    if ($uploadIdMarker !== null) {
        $args['UploadIdMarker'] = $uploadIdMarker;
    }

    $result = $client->listMultipartUploads($args);

    foreach ($result['Uploads'] ?? [] as $upload) {
        $objectKey = (string) ($upload['Key'] ?? '');
        $uploadId = (string) ($upload['UploadId'] ?? '');

        if (!str_starts_with($objectKey, P4_PREFIX) || $uploadId === '') {
            throw new RuntimeException('Refusing multipart cleanup outside the P4 upload prefix.');
        }

        $multipart[] = ['Key' => $objectKey, 'UploadId' => $uploadId];
    }

    $truncated = (bool) ($result['IsTruncated'] ?? false);
    $keyMarker = $truncated ? (string) ($result['NextKeyMarker'] ?? '') : null;
    $uploadIdMarker = $truncated ? (string) ($result['NextUploadIdMarker'] ?? '') : null;
} while ($truncated);

$objects = [];
$bytes = 0;
$continuation = null;

do {
    $args = ['Bucket' => P4_BUCKET, 'Prefix' => P4_PREFIX];

    if ($continuation !== null) {
        $args['ContinuationToken'] = $continuation;
    }

    $result = $client->listObjectsV2($args);

    foreach ($result['Contents'] ?? [] as $object) {
        $objectKey = (string) ($object['Key'] ?? '');

        if (!str_starts_with($objectKey, P4_PREFIX)) {
            throw new RuntimeException('Refusing object cleanup outside the P4 upload prefix.');
        }

        $objects[] = ['Key' => $objectKey];
        $bytes += (int) ($object['Size'] ?? 0);
    }

    $truncated = (bool) ($result['IsTruncated'] ?? false);
    $continuation = $truncated ? (string) ($result['NextContinuationToken'] ?? '') : null;
} while ($truncated);

if ($execute) {
    foreach ($multipart as $upload) {
        $client->abortMultipartUpload([
            'Bucket' => P4_BUCKET,
            'Key' => $upload['Key'],
            'UploadId' => $upload['UploadId'],
        ]);
    }

    foreach (array_chunk($objects, 1000) as $chunk) {
        if ($chunk !== []) {
            $client->deleteObjects([
                'Bucket' => P4_BUCKET,
                'Delete' => ['Objects' => $chunk, 'Quiet' => true],
            ]);
        }
    }
}

echo json_encode([
    'mode' => $execute ? 'execute' : 'dry-run',
    'bucket' => P4_BUCKET,
    'prefix' => P4_PREFIX,
    'multipart_uploads' => count($multipart),
    'objects' => count($objects),
    'object_bytes' => $bytes,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

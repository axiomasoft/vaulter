<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Inspection;

use finfo;
use Packman\Attributes\Internal;
use RuntimeException;
use ZipArchive;

/**
 * Content-vs-declared-mime sniffer for the assembled resumable object. A resumable commit must not trust the CLIENT's declared
 * mime — a file uploaded as image/png whose magic bytes are a ZIP (or a script)
 * is a type-confusion vector. The sniff reads the leading bytes of the assembled
 * object and compares the detected type against what the client declared.
 *
 * It is deliberately CONSERVATIVE to avoid false rejections of legitimate uploads:
 * it only rejects when BOTH the declared type and the detected type are concrete
 * (non-generic) and they disagree. An unstated declared type, or content finfo
 * cannot confidently classify (octet-stream / empty), is accepted — the sniff
 * disproves a lie, it does not attempt to positively classify every upload.
 */
#[Internal]
final class MimeSniffer
{
    public function __construct(private readonly ZipCentralDirectoryGuard $zipGuard = new ZipCentralDirectoryGuard) {}

    /**
     * Bytes read from the head of the object for magic-byte detection.
     */
    private const int SNIFF_BYTES = 8192;

    public function detect(string $path): ?string
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to inspect upload.');
        }
        try {
            $head = fread($stream, self::SNIFF_BYTES);
            if ($head === false) {
                throw new RuntimeException('Unable to inspect upload.');
            }
            $mime = new finfo(FILEINFO_MIME_TYPE)->buffer($head);
        } finally {
            fclose($stream);
        }
        // OOXML refinement requires bounded ZIP metadata, never just the suffix.
        if (is_string($mime) && ($mime === 'application/zip' || str_starts_with($mime, 'application/vnd.openxmlformats-')
            || str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06"))) {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                throw ZipCentralDirectoryGuard::invalid();
            }
            try {
                $this->zipGuard->check($zip, $path);
                $this->zipGuard->checkRelationships($zip);
                $entry = $zip->statName('[Content_Types].xml');
                if (is_array($entry)) {
                    if ($entry['size'] > 65536) {
                        throw ZipCentralDirectoryGuard::invalid();
                    }
                    $types = $this->zipGuard->readMetadata($zip, '[Content_Types].xml', $entry['size']);
                    foreach (['word' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xl' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ppt' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'] as $part => $type) {
                        if (str_contains($types, $type.'.main+xml') && $zip->locateName($part.'/'.($part === 'word' ? 'document' : ($part === 'xl' ? 'workbook' : 'presentation')).'.xml') !== false) {
                            return $type;
                        }
                    }
                }
            } finally {
                $zip->close();
            }
        }

        return is_string($mime) && $mime !== 'inode/x-empty' ? $this->normalise($mime) : null;
    }

    /**
     * Generic detections that carry no signal — neither a declared nor a detected
     * value of these can contradict anything, so they are treated as "unknown".
     */
    private const array GENERIC = [
        '',
        'application/octet-stream',
        'text/plain',
        'inode/x-empty',
    ];

    /**
     * Whether the object's leading bytes are consistent with the declared mime.
     * True (accept) when nothing is declared, when the declared or detected type is
     * generic, or when they agree; false (reject) only on a concrete contradiction.
     *
     * @param resource $stream a readable stream positioned at the object start
     */
    public function matches(mixed $stream, ?string $declaredMime): bool
    {
        $declared = $this->normalise($declaredMime);

        if ($this->isGeneric($declared)) {
            return true;
        }

        if (!is_resource($stream)) {
            return true;
        }

        $head = (string) stream_get_contents($stream, self::SNIFF_BYTES, 0);

        if ($head === '') {
            return true;
        }

        $sniffed = new finfo(FILEINFO_MIME_TYPE)->buffer($head);
        $detected = $this->normalise($sniffed !== false ? $sniffed : null);

        if ($this->isGeneric($detected)) {
            return true;
        }

        return $detected === $declared;
    }

    /**
     * Lowercase, drop any `; charset=…` parameter, and fold the jpeg/jpg alias so
     * equivalent spellings compare equal.
     */
    private function normalise(?string $mime): string
    {
        if ($mime === null) {
            return '';
        }

        $value = strtolower(trim(explode(';', $mime, 2)[0]));

        return $value === 'image/jpg' ? 'image/jpeg' : $value;
    }

    private function isGeneric(string $mime): bool
    {
        return in_array($mime, self::GENERIC, strict: true);
    }
}

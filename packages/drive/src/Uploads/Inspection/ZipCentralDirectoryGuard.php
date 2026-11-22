<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Inspection;

use DOMDocument;
use Illuminate\Validation\ValidationException;
use Packman\Attributes\Internal;
use ZipArchive;

/** Bounds ZIP inspection before any entry is read. The caller owns the archive handle. */
#[Internal]
final class ZipCentralDirectoryGuard
{
    private const int MAX_ENTRIES = 2000;

    private const int MAX_ENTRY_BYTES = 50 * 1024 * 1024;

    private const int MAX_TOTAL_BYTES = 150 * 1024 * 1024;

    private const int MAX_RATIO = 100;

    public function check(ZipArchive $zip, string $path): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw self::invalid();
        }
        $this->checkRawNames($path, $zip);

        $seen = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            if ($entry === false || !$zip->getExternalAttributesIndex($index, $system, $attributes) || !is_int($attributes)) {
                throw self::invalid();
            }
            $name = $entry['name'];
            $key = mb_convert_case(rtrim($name, '/'), MB_CASE_FOLD, 'UTF-8');
            if ($key === '' || isset($seen[$key]) || !mb_check_encoding($name, 'UTF-8')
                || preg_match('~(?:^/|^[a-z]:|(?:^|/)\.\.(?:/|$)|[\x00])~i', $name) === 1 || str_contains($name, '\\')) {
                throw self::invalid();
            }
            $seen[$key] = true;

            // Only regular files and directories. Older writers may leave the type bits unset.
            if ($system === ZipArchive::OPSYS_UNIX && !in_array(($attributes >> 16) & 0170000, [0, 0100000, 0040000], true)) {
                throw self::invalid();
            }

            $size = $entry['size'];
            $compressed = $entry['comp_size'];
            $total += $size;
            if ($size < 0 || $compressed < 0 || $size > self::MAX_ENTRY_BYTES || $total > self::MAX_TOTAL_BYTES
                || ($size > 0 && ($compressed === 0 || $size / $compressed > self::MAX_RATIO))) {
                throw self::invalid();
            }
        }
    }

    /** libzip replaces NULs with spaces even when the raw-name flag is requested. */
    private function checkRawNames(string $path, ZipArchive $zip): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw self::invalid();
        }
        try {
            $stat = fstat($stream);
            if ($stat === false || $stat['size'] < 22) {
                throw self::invalid();
            }
            $tailOffset = max(0, $stat['size'] - 65557);
            $tail = self::readAt($stream, $tailOffset, $stat['size'] - $tailOffset);
            $end = $this->endRecord($tail);
            [$size, $offset] = self::numbers('V2', substr($tail, $end + 12, 8));
            if ($offset === 0xFFFFFFFF || $size === 0xFFFFFFFF || substr($tail, $end + 10, 2) === "\xff\xff") {
                $locator = self::readAt($stream, $tailOffset + $end - 20, 20);
                if (!str_starts_with($locator, "PK\x06\x07")) {
                    throw self::invalid();
                }
                [$location] = self::numbers('P', substr($locator, 8, 8));
                $record = self::readAt($stream, $location, 56);
                if (!str_starts_with($record, "PK\x06\x06")) {
                    throw self::invalid();
                }
                [$size, $offset] = self::numbers('P2', substr($record, 40, 16));
            }

            $boundary = $tailOffset + $end;
            if ($offset < 0 || $offset > $boundary || $size < 0 || $size > $boundary - $offset) {
                throw self::invalid();
            }
            $limit = $offset + $size;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $header = self::readAt($stream, $offset, 46);
                [$nameLength, $extraLength, $commentLength] = self::numbers('v3', substr($header, 28, 6));
                $next = $offset + 46 + $nameLength + $extraLength + $commentLength;
                if (!str_starts_with($header, "PK\x01\x02") || $nameLength === 0 || $next > $limit) {
                    throw self::invalid();
                }
                $name = self::readAt($stream, $offset + 46, $nameLength);
                if (str_contains($name, "\0") || $name !== $zip->getNameIndex($index, ZipArchive::FL_ENC_RAW)) {
                    throw self::invalid();
                }
                $offset = $next;
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return list<int> */
    private static function numbers(string $format, string $bytes): array
    {
        $values = unpack($format, $bytes);
        if ($values === false) {
            throw self::invalid();
        }

        return array_values(array_filter($values, is_int(...)));
    }

    private function endRecord(string $tail): int
    {
        $length = strlen($tail);
        for ($offset = $length - 22; $offset >= 0; $offset--) {
            if (substr($tail, $offset, 4) === "PK\x05\x06"
                && ord($tail[$offset + 20]) + (ord($tail[$offset + 21]) << 8) === $length - $offset - 22) {
                return $offset;
            }
        }

        throw self::invalid();
    }

    /** @param resource $stream */
    private static function readAt(mixed $stream, int $offset, int $length): string
    {
        if ($offset < 0 || $length < 1 || fseek($stream, $offset) !== 0) {
            throw self::invalid();
        }
        $bytes = fread($stream, $length);
        if ($bytes === false || strlen($bytes) !== $length) {
            throw self::invalid();
        }

        return $bytes;
    }

    /** Read exactly the inspected size, with a bounded overflow probe. */
    public function readMetadata(ZipArchive $zip, string $name, int $size): string
    {
        $bytes = $zip->getFromName($name, $size + 1);
        if ($bytes === false || strlen($bytes) !== $size
            || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b|http:\/\/www\.w3\.org\/2001\/XInclude/i', $bytes) === 1
            || str_contains($bytes, "\0")) {
            throw self::invalid();
        }

        return $bytes;
    }

    public function checkRelationships(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            if ($entry === false || !str_ends_with(strtolower($entry['name']), '.rels')) {
                continue;
            }
            if ($entry['size'] > 65536) {
                throw self::invalid();
            }
            $xml = $this->readMetadata($zip, $entry['name'], $entry['size']);
            $document = new DOMDocument;
            if ($xml === '' || !$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
                || $document->doctype !== null || $document->getElementsByTagNameNS('http://www.w3.org/2001/XInclude', '*')->length > 0) {
                throw self::invalid();
            }

            foreach ($document->getElementsByTagNameNS('*', 'Relationship') as $relationship) {
                if (strcasecmp($relationship->getAttribute('TargetMode'), 'External') !== 0) {
                    continue;
                }
                if (!in_array($relationship->getAttribute('Type'), [
                    'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink',
                    'http://purl.oclc.org/ooxml/officeDocument/relationships/hyperlink',
                ], true) || preg_match('~\A(?:https?://|mailto:)~i', $relationship->getAttribute('Target')) !== 1) {
                    throw self::invalid();
                }
            }
        }
    }

    public static function invalid(): ValidationException
    {
        return ValidationException::withMessages(['file' => ['The ZIP archive has unsafe or invalid entries.']]);
    }
}

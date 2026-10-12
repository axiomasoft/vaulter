<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Inspection;

use finfo;

/**
 * Content-vs-declared-mime sniffer for the assembled resumable object (§2.8 /
 * F-P1.4-11 / U5.5). A resumable commit must not trust the CLIENT's declared
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
final class MimeSniffer
{
    /**
     * Bytes read from the head of the object for magic-byte detection.
     */
    private const int SNIFF_BYTES = 4096;

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

        $detected = $this->normalise((new finfo(FILEINFO_MIME_TYPE))->buffer($head) ?: null);

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

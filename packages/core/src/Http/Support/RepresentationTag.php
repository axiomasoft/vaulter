<?php

declare(strict_types=1);

namespace Vaulter\Http\Support;

use JsonException;
use stdClass;
use Vaulter\Attributes\Internal;

/**
 * Strong entity tags of the versioned API.
 *
 * A node's JSON depends on who reads it (abilities, starred, the scan state of its
 * head), so its tag hashes the exact representation the caller receives: a grant, a
 * star or a finished scan changes the tag although the node's sequence did not move.
 * The id and sequence stay readable for support, but clients treat the tag as opaque.
 *
 * Document content carries nothing caller-specific, so its tag is the content
 * generation itself; so are the tags of the aggregates with a version of their own.
 */
#[Internal]
final class RepresentationTag
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, mixed> $representationWithoutEtag the node exactly as serialized for the caller, without its etag
     */
    public static function node(array $representationWithoutEtag, string $id, int $sequence): string
    {
        return '"'.$id.'.'.$sequence.'.'.substr(hash('sha256', self::canonical($representationWithoutEtag)), 0, 16).'"';
    }

    public static function document(string $nodeId, int $epoch, int $stateVersion): string
    {
        return '"'.$nodeId.'.'.$epoch.'.'.$stateVersion.'"';
    }

    /**
     * The tag of an aggregate that carries its own version (CC2): a share link, the access of a node (its
     * grants and inheritance), a drive. Nothing caller-specific changes what a write of it may assume, so the tag is
     * the kind, the id and the version; the kind keeps the tags of different aggregates with one id apart.
     */
    public static function versioned(string $kind, string $id, int $version): string
    {
        return '"'.$kind.'.'.$id.'.'.$version.'"';
    }

    /**
     * The JSON text with object keys sorted at every depth, so two equal representations
     * hash equally whatever order their keys were built in. An empty object stays `{}`
     * and an empty list stays `[]`.
     *
     * @throws JsonException
     */
    public static function canonical(mixed $value): string
    {
        return json_encode(self::sorted(json_decode(json_encode($value, self::FLAGS), associative: false, flags: JSON_THROW_ON_ERROR)), self::FLAGS);
    }

    private static function sorted(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::sorted(...), $value);
        }
        if (!$value instanceof stdClass) {
            return $value;
        }
        $properties = get_object_vars($value);
        ksort($properties, SORT_STRING);
        $sorted = new stdClass;
        foreach ($properties as $key => $item) {
            $sorted->{$key} = self::sorted($item);
        }

        return $sorted;
    }
}

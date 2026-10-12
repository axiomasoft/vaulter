<?php

declare(strict_types=1);

namespace Vaulter\Profiles;

use Vaulter\Exceptions\Nodes\InvalidPropertiesException;
use Vaulter\Exceptions\Policy\FileSizeLimitExceededException;
use Vaulter\Exceptions\Policy\FolderChildrenLimitExceededException;
use Vaulter\Exceptions\Policy\TreeDepthLimitExceededException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;

/**
 * @internal The checks Actions run against the drive policy of a drive.
 *
 * Each check refuses only an operation that makes the state worse than the limit
 * allows; an excess left behind by a lowered limit stays readable and shrinkable.
 * Callers hold the locks of the rows the check reads (the parent row for the
 * children count, the tree lock for depth).
 */
final readonly class DrivePolicyEnforcer
{
    private const int MAX_VALUE_BYTES = 1024;

    public function __construct(private ProfileRegistry $profiles, private NodeReadRepository $nodes) {}

    public function policy(Drive $drive): DrivePolicy
    {
        return $this->profiles->get($drive->profile)->getDrivePolicy();
    }

    /** A new name under the drive's name policy, normalized. */
    public function name(Drive $drive, string $name): string
    {
        return $this->policy($drive)->names()->apply($name);
    }

    /** Room for $adding more children of the locked parent; trashed children count until purged. */
    public function assertChildren(Drive $drive, int|string $parentId, int $adding = 1): void
    {
        $limit = $this->policy($drive)->maxFolderChildren;
        if ($adding > 0 && $this->nodes->childCount($parentId) + $adding > $limit) {
            throw FolderChildrenLimitExceededException::forParent($parentId, $limit);
        }
    }

    /** A folder about to be created with this many children (a copy) must fit the limit itself. */
    public function assertChildCount(Drive $drive, int|string $folderId, int $children): void
    {
        $limit = $this->policy($drive)->maxFolderChildren;
        if ($children > $limit) {
            throw FolderChildrenLimitExceededException::forParent($folderId, $limit);
        }
    }

    /**
     * A subtree of $height levels below its own root placed under $parent. A move
     * passes the deepest level the subtree reaches now: going no deeper is allowed.
     */
    public function assertDepth(Drive $drive, Node $parent, int $height = 0, ?int $currentDeepest = null): void
    {
        $limit = $this->policy($drive)->maxFolderDepth;
        $deepest = $this->nodes->depth($parent) + 1 + $height;
        if ($deepest > $limit && ($currentDeepest === null || $deepest > $currentDeepest)) {
            throw TreeDepthLimitExceededException::forDepth($deepest, $limit);
        }
    }

    /** The bound to read a subtree height with: one level past the limit proves an excess. */
    public function heightBound(Drive $drive): int
    {
        return $this->policy($drive)->maxFolderDepth + 1;
    }

    public function maxFileBytes(Drive $drive): ?int
    {
        return $this->policy($drive)->maxFileBytes;
    }

    public function assertFileSize(Drive $drive, int $bytes): void
    {
        $limit = $this->maxFileBytes($drive);
        if ($limit !== null && $bytes > $limit) {
            throw FileSizeLimitExceededException::forLimit($limit);
        }
    }

    /**
     * Apply a patch to one namespace of a node's properties and check the result.
     *
     * @param  array<string, array<string, string>>|null $current
     * @param  array<array-key, mixed>                   $set
     * @param  array<array-key, mixed>                   $unset
     * @return array<string, array<string, string>>|null
     */
    public function patchProperties(Drive $drive, ?array $current, string $namespace, array $set, array $unset, bool $system): ?array
    {
        if (preg_match('/^[a-z0-9][a-z0-9.-]{0,127}$/D', $namespace) !== 1) {
            throw InvalidPropertiesException::because(InvalidPropertiesException::NAMESPACE, $namespace);
        }
        if (!$system && ($namespace === 'vaulter' || str_starts_with($namespace, 'vaulter.'))) {
            throw InvalidPropertiesException::because(InvalidPropertiesException::RESERVED_NAMESPACE, $namespace);
        }

        $current ??= [];
        $values = $current[$namespace] ?? [];
        foreach ($unset as $key) {
            if (!is_string($key)) {
                throw InvalidPropertiesException::because(InvalidPropertiesException::KEY);
            }
            unset($values[$key]);
        }
        foreach ($set as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $key) !== 1) {
                throw InvalidPropertiesException::because(InvalidPropertiesException::KEY, (string) $key);
            }
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || strlen($value) > self::MAX_VALUE_BYTES) {
                throw InvalidPropertiesException::because(InvalidPropertiesException::VALUE_TOO_LONG, $key);
            }
            $values[$key] = $value;
        }

        $next = $current;
        if ($values === []) {
            unset($next[$namespace]);
        } else {
            ksort($values, SORT_STRING);
            $next[$namespace] = $values;
        }
        ksort($next, SORT_STRING);

        $policy = $this->policy($drive);
        $count = self::count($next);
        if ($count > $policy->maxPropertyCount && $count > self::count($current)) {
            throw InvalidPropertiesException::because(InvalidPropertiesException::TOO_MANY);
        }
        $bytes = self::bytes($next);
        if ($bytes > $policy->maxPropertiesBytes && $bytes > self::bytes($current)) {
            throw InvalidPropertiesException::because(InvalidPropertiesException::TOO_LARGE);
        }

        return $next === [] ? null : $next;
    }

    /** @param array<string, array<string, string>> $properties */
    public static function count(array $properties): int
    {
        return array_sum(array_map(count(...), $properties));
    }

    /**
     * The size of the canonical JSON form (sorted keys, unescaped UTF-8).
     *
     * @param array<string, array<string, string>> $properties
     */
    public static function bytes(array $properties): int
    {
        return $properties === [] ? 0 : strlen(json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}

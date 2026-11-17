<?php

declare(strict_types=1);

namespace Vaulter\Documents\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Configuration\StringList;

/**
 * Immutable compiled TipTap extension options for `vaulter-documents.tiptap.*`.
 */
#[Api(since: '1.0')]
final readonly class TipTapOptions
{
    /**
     * @param list<string>                $extraNodes
     * @param list<string>                $extraMarks
     * @param array<string, list<string>> $extraAttributes
     * @param array<string, list<string>> $urlAttributes
     */
    public function __construct(
        public array $extraNodes = [],
        public array $extraMarks = [],
        public array $extraAttributes = [],
        public array $urlAttributes = [],
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['extra_nodes', 'extra_marks', 'extra_attributes', 'url_attributes'], $path);

        return new self(
            extraNodes: StringList::from($raw['extra_nodes'] ?? [], []),
            extraMarks: StringList::from($raw['extra_marks'] ?? [], []),
            extraAttributes: self::parseAttributeMap($raw['extra_attributes'] ?? []),
            urlAttributes: self::parseAttributeMap($raw['url_attributes'] ?? []),
        );
    }

    /** @return array<string, list<string>> */
    private static function parseAttributeMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $attributes = [];
        foreach ($value as $type => $keys) {
            if (!is_string($type) || !is_array($keys)) {
                continue;
            }
            $attributes[$type] = array_values(array_filter($keys, is_string(...)));
        }

        return $attributes;
    }
}

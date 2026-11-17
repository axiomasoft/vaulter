<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Drive\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Drive\Support\RandomShareTokenGenerator;

/**
 * Immutable compiled share-link options for `vaulter-drive.sharing.*`.
 */
#[Api(since: '1.0')]
final readonly class SharingOptions
{
    /**
     * @param class-string<ShareTokenGenerator> $tokenGenerator
     */
    public function __construct(
        public string $tokenGenerator = RandomShareTokenGenerator::class,
        public string $urlBase = '/share',
        public int $prefixLength = 8,
        public int $defaultTtlHours = 72,
        public int $maxTtlHours = 720,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['token_generator', 'url_base', 'prefix_length', 'default_ttl_hours', 'max_ttl_hours'], $path);

        $rawGenerator = $raw['token_generator'] ?? RandomShareTokenGenerator::class;
        if (!is_string($rawGenerator) || $rawGenerator === '' || !class_exists($rawGenerator) || !is_a($rawGenerator, ShareTokenGenerator::class, true)) {
            $validator->add($path.'.token_generator', '[Vaulter] vaulter-drive.sharing.token_generator must be a FQCN implementing '.ShareTokenGenerator::class.'.');
            $generator = RandomShareTokenGenerator::class;
        } else {
            /** @var class-string<ShareTokenGenerator> $generator */
            $generator = $rawGenerator;
        }

        $rawBase = $raw['url_base'] ?? '/share';
        $urlBase = is_string($rawBase) && $rawBase !== '' ? rtrim($rawBase, '/') : '/share';

        $prefix = $raw['prefix_length'] ?? 8;
        $defaultTtl = $raw['default_ttl_hours'] ?? 72;
        $maxTtl = $raw['max_ttl_hours'] ?? 720;

        return new self(
            tokenGenerator: $generator,
            urlBase: $urlBase,
            prefixLength: max(is_numeric($prefix) ? (int) $prefix : 8, 1),
            defaultTtlHours: max(is_numeric($defaultTtl) ? (int) $defaultTtl : 72, 1),
            maxTtlHours: max(is_numeric($maxTtl) ? (int) $maxTtl : 720, 1),
        );
    }
}

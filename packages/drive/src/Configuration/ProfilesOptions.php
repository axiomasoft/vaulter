<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Drive\Profiles\DefaultProfile;
use Vaulter\Drive\Profiles\ProfileProvider;

/**
 * Immutable compiled profile registry options for `vaulter-drive.profiles.*`.
 */
#[Api(since: '1.0')]
final readonly class ProfilesOptions
{
    /**
     * @param class-string<ProfileProvider>       $default
     * @param list<class-string<ProfileProvider>> $providers
     */
    public function __construct(
        public string $default = DefaultProfile::class,
        public array $providers = [],
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['default', 'providers'], $path);

        $defaultRaw = $raw['default'] ?? DefaultProfile::class;
        if (!is_string($defaultRaw) || $defaultRaw === '' || !class_exists($defaultRaw) || !is_a($defaultRaw, ProfileProvider::class, true)) {
            $validator->add($path.'.default', '[Vaulter] Invalid class for vaulter-drive.profiles.default.');
            $default = DefaultProfile::class;
        } else {
            /** @var class-string<ProfileProvider> $default */
            $default = $defaultRaw;
        }

        $providersRaw = $raw['providers'] ?? [];
        $providers = [];
        if (!is_array($providersRaw) || !array_is_list($providersRaw)) {
            $validator->add($path.'.providers', '[Vaulter] vaulter-drive.profiles.providers must be a list of ProfileProvider classes.');
        } else {
            foreach ($providersRaw as $class) {
                if (!is_string($class) || $class === '' || !class_exists($class) || !is_a($class, ProfileProvider::class, true)) {
                    $validator->add($path.'.providers', '[Vaulter] Invalid class in vaulter-drive.profiles.providers.');

                    continue;
                }
                /** @var class-string<ProfileProvider> $class */
                $providers[] = $class;
            }
        }

        return new self(
            default: $default,
            providers: $providers,
        );
    }
}

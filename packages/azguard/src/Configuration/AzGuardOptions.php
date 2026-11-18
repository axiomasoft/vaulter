<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Configuration;

use Illuminate\Support\Arr;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\AzGuard\Contracts\AzGuardGuard;

#[Api]
final readonly class AzGuardOptions implements OptionsFactory
{
    /** @param array<array-key, mixed> $values */
    private function __construct(private array $values) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter-azguard'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['panel_id', 'guard', 'permissions', 'create_permission'], $path);
        $validator->failIfAny();
        $options = new self($config);
        $options->guard();

        return $options;
    }

    public function panelId(): ?string
    {
        $value = $this->value('vaulter-azguard.panel_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The explicit guard class the host configured, validated; null when none.
     *
     * @return class-string<AzGuardGuard>|null
     */
    public function guard(): ?string
    {
        $value = $this->value('vaulter-azguard.guard');
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw InvalidConfiguration::forMessage('[Vaulter] Config key "vaulter-azguard.guard" must be a class-string or null.');
        }
        if (!is_a($value, AzGuardGuard::class, true)) {
            throw InvalidConfiguration::forMessage('[Vaulter] vaulter-azguard.guard must implement '.AzGuardGuard::class);
        }

        return $value;
    }

    /** @return array<string, string> */
    public function permissions(): array
    {
        $value = $this->value('vaulter-azguard.permissions', self::DEFAULT_PERMISSIONS);

        if (!is_array($value)) {
            return self::DEFAULT_PERMISSIONS;
        }

        $map = [];

        foreach ($value as $ability => $permission) {
            if (is_string($ability) && is_string($permission) && $permission !== '') {
                $map[$ability] = $permission;
            }
        }

        return $map === [] ? self::DEFAULT_PERMISSIONS : $map;
    }

    public function createPermission(): string
    {
        $value = $this->value('vaulter-azguard.create_permission', 'documents.create');

        return is_string($value) && $value !== '' ? $value : 'documents.create';
    }

    /** @var array<string, string> */
    private const array DEFAULT_PERMISSIONS = [
        'view' => 'documents.view',
        'download' => 'documents.download',
        'comment' => 'documents.comment',
        'edit' => 'documents.update',
        'create' => 'documents.create',
        'move' => 'documents.update',
        'copy' => 'documents.update',
        'trash' => 'documents.delete',
        'delete' => 'documents.delete',
        'restore' => 'documents.delete',
        'share' => 'documents.manage',
        'manage' => 'documents.manage',
    ];

    private function value(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->values, substr($key, strlen('vaulter-azguard.')), $default);
    }
}

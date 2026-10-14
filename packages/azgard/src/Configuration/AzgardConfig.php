<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

final readonly class AzgardConfig
{
    public function __construct(private Repository $config) {}

    public function panelId(): ?string
    {
        $value = $this->config->get('vaulter-azgard.panel_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The explicit guard class the host configured, validated; null when none.
     *
     * @return class-string<AzgardGuard>|null
     */
    public function guard(): ?string
    {
        $value = $this->config->get('vaulter-azgard.guard');
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidConfigurationException('[Vaulter] Config key "vaulter-azgard.guard" must be a class-string or null.');
        }
        ConfigValidator::assertImplements('vaulter-azgard.guard', $value, AzgardGuard::class);

        return $value;
    }

    /** @return array<string, string> */
    public function permissions(): array
    {
        $value = $this->config->get('vaulter-azgard.permissions', self::DEFAULT_PERMISSIONS);

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
        $value = $this->config->get('vaulter-azgard.create_permission', 'documents.create');

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
}

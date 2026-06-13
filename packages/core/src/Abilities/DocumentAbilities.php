<?php

declare(strict_types=1);

namespace DocVault\Abilities;

use DocVault\DocVaultManager;
use DocVault\Models\Document;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Per-document computed permissions for the front-end.
 */
final readonly class DocumentAbilities extends AbilitiesDto
{
    public function __construct(
        public bool $view,
        public bool $comment,
        public bool $edit,
        public bool $manage,
        public bool $delete,
    ) {}

    public static function for(?Authenticatable $user, Document $document, DocVaultManager $manager): self
    {
        $flags = self::resolveFlags($user, $document, $manager, [
            'view' => 'view',
            'comment' => 'comment',
            'edit' => 'edit',
            'manage' => 'manage',
            'delete' => 'delete',
        ]);

        return new self(
            view: $flags['view'],
            comment: $flags['comment'],
            edit: $flags['edit'],
            manage: $flags['manage'],
            delete: $flags['delete'],
        );
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'view' => $this->view,
            'comment' => $this->comment,
            'edit' => $this->edit,
            'manage' => $this->manage,
            'delete' => $this->delete,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Vaulter\Enums;

/**
 * The Drive action vocabulary the AccessResolver answers `can()` for, and the bits
 * each action requires (master plan §2.6 / H.2). One ability maps to exactly the
 * CRUDS permission(s) that must be present in the effective allow-mask for the
 * action to be permitted; the deny-veto then re-checks the SAME bits.
 *
 * Per H.2 there is no orphan stringly-typed delete action: permanent delete folds
 * into the DELETE bit (Organizer/Manage), so Trash and Delete both require DELETE.
 *
 *   View/Download/Comment → READ
 *   Edit                  → UPDATE
 *   Create/Copy           → CREATE
 *   Move                  → UPDATE+CREATE (re-parent = update here, create there)
 *   Trash/Restore/Delete  → DELETE
 *   Share                 → SHARE
 *   Manage                → ALL (full management of the node)
 */
enum Ability: string
{
    case View = 'view';
    case Download = 'download';
    case Comment = 'comment';
    case Edit = 'edit';
    case Create = 'create';
    case Move = 'move';
    case Copy = 'copy';
    case Trash = 'trash';
    case Restore = 'restore';
    case Share = 'share';
    case Manage = 'manage';
    case Delete = 'delete';

    /**
     * The CRUDS bitmask this ability requires. An actor may perform the ability iff
     * every bit here is present in the effective allow-mask (and absent from the
     * deny-mask).
     */
    public function requiredBits(): int
    {
        return match ($this) {
            self::View, self::Download, self::Comment => Permission::Read->value,
            self::Edit => Permission::Update->value,
            self::Create, self::Copy => Permission::Create->value,
            self::Move => Permission::Update->value | Permission::Create->value,
            self::Trash, self::Restore, self::Delete => Permission::Delete->value,
            self::Share => Permission::Share->value,
            self::Manage => Permission::allBits(),
        };
    }

    /**
     * Whether the effective allow-mask satisfies this ability (all required bits
     * present). The caller still runs the deny-veto separately (H.2 step 5).
     */
    public function satisfiedBy(int $bits): bool
    {
        return ($bits & $this->requiredBits()) === $this->requiredBits();
    }
}

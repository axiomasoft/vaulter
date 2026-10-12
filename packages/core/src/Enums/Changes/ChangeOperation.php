<?php

declare(strict_types=1);

namespace Vaulter\Enums\Changes;

/**
 * What a change-journal entry tells a syncing client to do with a node.
 *
 *   upsert  the node exists in its current state: fetch or replace it
 *   remove  the node is gone for this reader; a removed folder takes its subtree along
 *
 * @closed The string values are stored and never change.
 */
enum ChangeOperation: string
{
    case Upsert = 'upsert';
    case Remove = 'remove';
}

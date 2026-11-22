<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Illuminate\Database\Connection;
use Packman\Attributes\Api;
use Vaulter\Documents\Data\Documents\DocumentMutationState;

/** The locked document for one transaction attempt. The handle expires when the callback exits. */
#[Api]
interface LockedDocumentMutation
{
    /** Read the locked working state, including any replacement made by this callback. */
    public function snapshot(): DocumentMutationState;

    /** Compare and replace the working state once per attempt. An unchanged replacement keeps its position. */
    public function replace(DocumentContent $content, int $expectedStateVersion): DocumentMutationState;

    /** Use this transaction's connection for the calling adapter's own records. */
    public function connection(): Connection;
}

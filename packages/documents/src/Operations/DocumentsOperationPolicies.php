<?php

declare(strict_types=1);

namespace Vaulter\Documents\Operations;

use Vaulter\Attributes\Internal;
use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Operations\OperationPolicySource;

/**
 * The policies of the documents' mutating operations: documents, their content
 * and revisions, and comments. The content PUT is conditional: a repeat with the same `If-Match` after the commit
 * answers 412 `document_state_mismatch` without a second revision. The export is safe and not listed.
 */
#[Internal]
final class DocumentsOperationPolicies implements OperationPolicySource
{
    public function policies(): array
    {
        return [
            'collaboration.sessions.create' => new OperationPolicy('documents.collaboration_session', ConcurrencyPolicy::Natural, IdempotencyPolicy::Natural, ['documents.collaboration.session']),

            'documents.documents.create' => new OperationPolicy('documents.document', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['documents.store']),
            'documents.documents.update' => new OperationPolicy('documents.document', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt),
            'documents.documents.update_content' => new OperationPolicy('documents.document', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Conditional, ['documents.content.update']),
            'documents.documents.snapshot' => new OperationPolicy('documents.document', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['documents.revisions.store']),
            'documents.documents.restore' => new OperationPolicy('documents.document', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['documents.revisions.restore']),
            'documents.documents.set_retention' => new OperationPolicy('documents.revision', ConcurrencyPolicy::Natural, IdempotencyPolicy::Natural, ['documents.revisions.update']),
            'documents.comments.add' => new OperationPolicy('documents.comment', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['comments.store']),
            'documents.comments.resolve' => new OperationPolicy('documents.comment', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['comments.resolve']),
            'documents.comments.delete' => new OperationPolicy('documents.comment', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['comments.destroy']),
        ];
    }
}

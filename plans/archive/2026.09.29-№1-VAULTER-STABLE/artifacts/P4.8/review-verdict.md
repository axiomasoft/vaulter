# P4.8 — grok review verdict

- Reviewer: grok / grok-4.7 (effort high requested; modelId grok-4.7 in updates.jsonl; contextWindowTokens 500000), session 01a11c2f-4a58-7f41-8142-7f010449218e, 44 turns, 798 s, exit 0. Launch: review-launch.json; stream: review-grok-stream.jsonl.
- Verdict: no high/medium findings; axes 1, 2, 4, 5 clean. One low finding.

## F1 (low) — comment Actions have no sameStorage / T→N→D lock
AddCommentAction:40-47, DeleteCommentAction, ResolveCommentAction write inside a transaction without lockTreeShared/node lock/sameStorage vs `$context->driveId()`.
- Confirmed by reading (AddCommentAction has only a plain transaction).
- Mitigation: the only entry points (CommentOperations add/resolve/delete) run NodeLocator (drive-scoped, active, tenant) and OperationGuard first, so a foreign-drive node is unreachable; the residual risk is a race with a concurrent trash/move of the node.
- Not fixed here: adding locks to comment writes changes lock semantics (comments are not in P4.8's lock-order claims for document state). Recorded as follow-up for the P4.6/P4.7 barrier. No authorization code changed, so no re-review.

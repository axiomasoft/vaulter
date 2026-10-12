### Documents API: authorized operations on snapshots (PLAN2.D34, D35, D38, D44, D45; PLAN2.P4.8)

- Added `Documents::in($drive)` (`DocumentOperations`: `create`, `content`, `update`, `snapshot`,
  `revisions`, `restore`, `setRetention`, `export`, `collaborationSession`) and
  `Documents::comments($drive)` (`CommentOperations`: `list`, `add`, `resolve`, `delete`). Every method
  locates the node in the handle's drive and authorizes it through `OperationGuard` (ACL, profile,
  tenancy, content egress). They return `DocumentSnapshot`, `DocumentRevisionSnapshot`,
  `CommentSnapshot`, `NodeSnapshot` or `Page<T>` and take ids or snapshots.
- Added `CommentNotFoundException` (`comment_not_found`, 404): a comment id of another node, also as a
  reply's `parent_id` (was a bare 404 without a code).
- Changed the documents Actions to `execute(OperationContext $context, …Input $input)`; the actor comes
  from the context. Removed `actorId` from `CreateDocumentInput`, `UpdateDocumentContentInput` and
  `CreateNamedRevisionInput`, and `authorType`/`authorId` from `AddCommentInput` (now `body`, `parentId`,
  `anchor`). Added `RestoreDocumentRevisionInput`, `UpdateRevisionRetentionInput`, `ResolveCommentInput`,
  `DeleteCommentInput`; `CreateDocumentInput::$onConflict`. The Actions are internal.
- Changed the documents HTTP routes to go through the operations, with the same URLs and JSON keys: a
  node the user cannot see, a trashed node and a node of another tenant answer `404 node_not_found`
  (was `403` or a bare `404`); export needs `Download` (was checked as `View` first); reading content
  needs `View` (was `Download`), so a previewer reads documents in the app; resolving a
  comment needs `Comment` and authorship or `Edit` (was authorship or `Manage`); ids in the document,
  revision and comment JSON are strings; the history is ordered by creation time.
- Changed `CollaborationSessionService::issue()` to `issue(Node $node, Ability $ability, $actorId)`; it no
  longer resolves access (internal).
- Removed `Vaulter\Http\Support\TrashGuard` (core); `NodeLocator` keeps trashed nodes out.


# P4.6 negative controls (V80)

Every new guard or Arch test was broken on purpose in the product code, run, and the exact original restored (sha256 of the file compared before and after). `red` = the named test went red with the break in place.

| Control | Test | Temporary break | Result | File restored |
|---|---|---|---|---|
| NC2-silent-root-restore | TrashRestoreTest + TrashRootsTest | resolveDestination falls back to the drive root again (D37 removed) | red (1 failed, 11 passed (37 assertions)) | yes |
| NC3a-assertLive-update | MutationLivenessTest | UpdateDocumentContentAction no longer re-checks the node is live under the lock | red (1 failed, 14 passed (29 assertions)) | yes |
| NC3b-assertWritable-update | MutationLivenessTest | UpdateDocumentContentAction no longer re-checks the drive status under the lock | red (1 failed, 14 passed (29 assertions)) | yes |
| NC12-assertLive-comment-delete | MutationLivenessTest | DeleteCommentAction no longer re-checks the node is live | red (1 failed, 14 passed (29 assertions)) | yes |
| NC5-comment-event-not-after-commit | CommentAfterCommitTest | CommentAdded without ShouldDispatchAfterCommit | red (3 failed, 2 passed (10 assertions)) | yes |
| NC9-archived-not-enforced | DriveArchiveTest | OperationGuard stops refusing mutations on an archived drive | red (1 failed, 5 passed (27 assertions)) | yes |
| NC10-bypass-open | DrivePurgeTest | PurgeNodeAction accepts bypassTrashRequirement from any actor | red (1 failed, 7 passed (29 assertions)) | yes |
| NC11-hasdrives-softdelete | HasDrivesTest | HasDrives reacts to deleted on a SoftDeletes host | red (1 failed, 3 passed (9 assertions)) | yes |
| NC7-recount-no-write | RecountDrivesTest | the recount reports drift but does not write it | red (4 failed, 1 passed (21 assertions)) | yes |
| NC8-backfill-members-skipped | TrashRootsBackfillTest | the migration backfills roots but not their members | red (2 failed) | yes |
| NC14a-listing-not-roots | TrashRootsTest | the trash listing returns members, not only roots | red (1 failed, 5 passed (19 assertions)) | yes |
| NC4a-assertComplete-disabled | DeepSubtreeLifecycleTest | the traversal no longer fails closed when it hits its bound | red (1 failed, 3 passed (22 assertions)) | yes |
| NC4b-bound-64 | DeepSubtreeLifecycleTest | the bound ignores the profile maxFolderDepth (64) with the completeness check on | red (3 failed, 1 passed (3 assertions)) | yes |
| NC4c-bound-64-no-check | DeepSubtreeLifecycleTest | the bound is fixed at 64 | red (3 failed, 1 passed (3 assertions)) | yes |
| NC6-rollback-guard-neutral | OffDedupRollbackSafetyTest (PG) | the rollback guard never reports conflicts | red (2 failed) | yes |
| NC15-archive-recheck-rename-removed | DriveArchiveTest (all drive writers) | RenameNodeAction no longer re-checks the drive status under the tree lock | red (1 failed, 17 passed (50 assertions)) | yes |
| NC14b-retention-selects-members | TrashRootsTest | trashedBefore()/trashRoots() return members too | red (1 failed, 6 passed (22 assertions)) | yes |
| NC14c-lock-accepts-member | TrashRootsTest | lockTrashedRoot() locks a member | red (1 failed, 6 passed (23 assertions)) | yes |

Notes:
- NC14b/NC14c are separate layers of one rule (candidates are roots; the row lock refuses a member); each has its own assertion.
- The PG race tests (DriveArchiveRaceTest, DocumentMutationLivenessRaceTest, CommentMutationRaceTest) accept both orders of a race by design, so they cannot go red on a removed re-check by themselves; the deterministic tests above (every drive writer, every document and comment writer, called past the guard on an archived drive or a trashed node) are the proof of the in-lock re-check, and the races prove the real-backend invariants (no write after the winning trash/archive, matching state).
- An earlier NC15 run on the race test stayed green for exactly that reason and was replaced by the deterministic dataset in DriveArchiveTest.

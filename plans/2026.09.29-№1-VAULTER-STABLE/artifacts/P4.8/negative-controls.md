# P4.8 — negative controls (V80)

Each control: one temporary break → the named test goes red → exact restore → green. Record the
actual red output (test name + assertion) and confirm `git diff --stat` of the touched file is empty
after the restore. To be executed by the check session (see sonnet-handoff.md); outputs below are
filled by it.

| # | Break (temporary) | Test that must go red | Red output | Restored |
|---|---|---|---|---|
| NC1 | `packages/documents/src/Http/Controllers/Documents/DocumentNodeController.php`: add `use Vaulter\Documents\Actions\Documents\CreateDocumentAction;` | `documents tests/Arch/OperationsBoundaryTest.php` "keeps the documents HTTP layer and facade on Operations" | red: OperationsBoundaryTest 'keeps the documents HTTP layer and facade on Operations' — arrays not identical (1 failed, 2 passed) | yes: file byte-identical to backup (cmp), test green again |
| NC2 | `packages/documents/src/Operations/DocumentOperations.php` `export()`: authorize `Ability::View` instead of `Ability::Download` | `documents tests/Operations/DocumentAbilityMatrixTest.php` row "previewer export" (expects permission_denied) | first break (Operations only): stayed green (103 passed) — NodeExportService is the second guard; both broken: red 'authorizes every documents method by role [previewer]' + 'same codes through HTTP routes [previewer]' (2 failed, 101 passed) | yes: file byte-identical to backup (cmp), test green again |
| NC3 | `packages/documents/src/Operations/CommentOperations.php` `resolve()`: delete the `if (!$this->isAuthor($row)) { … Edit … }` block | `documents tests/Operations/CommentOperationsTest.php` "lets the author resolve with Comment, refuses a commenter who is not the author" | red: CommentOperationsTest 'lets the author resolve with Comment, refuses a commenter who is not the author' (1 failed, 10 passed) | yes: file byte-identical to backup (cmp), test green again |
| NC4 | `packages/drive/tests/Arch/OperationsBoundaryTest.php`: put `'documents/src/Http/Controllers/Documents/DocumentNodeController.php'` back into `$exempt` | the same file, "keeps every HTTP and Filament boundary … shrinking exemption list" (stale exemption) | red: drive OperationsBoundaryTest 'keeps every HTTP and Filament boundary…' — stale exemption, arrays not identical (1 failed, 3 passed) | yes: file byte-identical to backup (cmp), test green again |
| NC5 | `packages/documents/src/Queries/DocumentQuery.php` `comment()`: drop `->where('node_id', $node->getKey())` | `documents tests/Operations/CommentOperationsTest.php` "resolves a comment of another node as comment_not_found" | red: CommentOperationsTest 'resolves a comment of another node as comment_not_found' + 'threads a reply only under a comment of the same node' (2 failed, 9 passed) | yes: file byte-identical to backup (cmp), test green again |

NC2 note: change only the ability argument of `export()`'s `$this->document(...)` call to `Ability::View`;
`NodeExportService::assertContentAvailable` also checks Download, so if the row stays green, record that
the service is the second guard and break both (then restore both).

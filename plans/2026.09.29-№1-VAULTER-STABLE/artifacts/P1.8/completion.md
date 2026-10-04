# P1.8 completion evidence

Implementation and all declared acceptance are GREEN, including the owner-requested independent Grok 4.7 review.

## Delivered

- ProblemException with problemCode()/httpStatus(), implemented by all 20 classes in the item contract.
- Conditional central JSON renderer: Vaulter paths or requests expecting JSON; host HTML and infrastructure exceptions return false to Laravel. Existing messages are preserved.
- Extra validation fields/content reasons and tus offset/version headers are retained.
- NodeNotFoundException and StorageNotFoundException, public API snapshot, error reference, CHANGELOG and AD29 upgrade notes.

## Acceptance

| Package | Pint | PHPStan | Pest |
|:--|:--|:--|:--|
| core | exit 0 | exit 0 | {"tool":"pest","result":"passed","tests":604,"passed":593,"assertions":1925,"duration_ms":69241,"skipped":11,"risky":17} |
| drive | exit 0 | exit 0 | ✓ it acquireForPatch на MySQL берёт НЕблокирующий лок — FOR UPDATE NO… 0.06s     ✓ it acquireForPatch на MySQL берёт НЕблокирующий лок — FOR UPDATE NO… 0.05s      Tests:    2 risky, 8 skipped, 448 passed (1325 assertions)   Duration: 32.35s |
| documents | exit 0 | exit 0 | ✓ it markdown-экспорт: "|" в ЦЕЛИ ссылки/картинки не добавляет таблиц… 0.05s     ✓ it markdown-экспорт: "|" в ЦЕЛИ ссылки/картинки не добавляет таблиц… 0.05s      Tests:    1 skipped, 245 passed (809 assertions)   Duration: 18.54s |
| media | exit 0 | exit 0 | ! it records gc metrics via vaulter:gc-blobs command → This test did…  0.05s     ! it records reconcile metrics via vaulter:reconcile-blobs command →…  0.05s      Tests:    4 risky, 9 skipped, 128 passed (449 assertions)   Duration: 24.17s |
| filament | exit 0 | exit 0 | ✓ it drops ActivityResource when the core activity feature is off, ev… 0.08s     ✓ it control: ActivityResource registers when the core activity featu… 0.08s      Tests:    1 skipped, 76 passed (215 assertions)   Duration: 9.07s |

All 15 explicit package gates exit 0. Existing skips/risky annotations are reported in the logs; they were neither removed nor treated as executed tests. No PG concurrency or mutation run is required by P1.8. D14 remains in force. SQLite :memory: and APP_ENV=testing were explicitly selected; the TestCase connection resolvers use in-memory SQLite, and upload fixtures use fake disks.

V24: initial 20 failed; after implementation/restoration 20 passed (204 assertions). Removing implements ProblemException from CycleException makes V24 and the drive architecture guard fail; restoring the exact source returns both GREEN. Additional directory guard negative probe rejects a second file, restoration returns GREEN. See negative-controls.json and corrected logs. The first directory-probe invocation used the wrong core cwd and is procedural evidence only.

Code Guidance: zero assertStatus(500)/assertServerError/assertInternalServerError matches in packages/*/tests. Scoped product git diff --check exits 0. An unrelated pre-existing roadmap blank-line finding is preserved.

## Bounded contract alignment

The old ADR-0017 directory test rejects the exact Exceptions/Contracts namespace mandated by the item. Its guard now permits that directory only with ProblemException.php as its single entry; other files/subdirectories and all other nested technical layers remain forbidden. The supplied item spec is unchanged. See arch-contract-repair.md.

Only tests that failed on changed status/body/rendering were updated, besides that exact structural guard alignment. Other 415 tus protocol validation tests remain unchanged. OffsetMismatchException has no named factory in the existing API: V24 uses its existing named constructor argument, without adding an unsolicited factory.

## Independent review

review: grok/grok-4.7 GREEN. Fresh Grok 4.7/high session 855f6154-2e5b-443a-a345-3ab2108008e1, actual provider model grok-4.7-build, read-only (26 read_file and 33 grep calls), no web/subagents. Candidate bytes: review-candidate.json; input: review-prompt.txt; invocation/model witness: review-route.json; verbatim final verdict: grok-review.md. Initial CLI invocation used flags after the agent subcommand and was rejected before dispatch; correction used the documented top-level CLI. No alternate reviewer was used.

## Scope

P1.8 only; next recommendation is P1.4. Prior unrelated plan edits, :memory: artifact, and concurrent .gitignore/.swissknife.json edits are preserved. No push, tag, release, migration or external write was performed.

write-site: pre_mutation: allow · pre_final: allow

Task terminal journal and generated P1.8 state are GREEN. plan-lint: OK, 0 errors, 0 warnings. The first terminal append exceeded Task's 300-character note limit and was rejected before mutation; shortening the note allowed the same run to close. Shared journal/status and prior P1 design files already contained owner work and are left in the working tree; the scoped commit contains only P1.8 product files, its evidence/state and updated handoff.

The raw review prompt embeds git diff context lines and verbatim test output, so its trailing whitespace is intentional evidence. It is kept byte-identical to the submitted prompt. The product-only staged diff passes git diff --check; the raw review input is not a product whitespace violation.

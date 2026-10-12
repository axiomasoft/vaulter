# P5.5 negative controls (V80; SQLite, 2026-10-10)

Each control injects one defect, proves the named test red, restores the exact bytes (sha256 checked) and proves it green.

```
a packages/core/src/Repositories/Changes/ChangeEntryRepository.php
  injected: "->where('operation', ChangeOperation::Upsert->value)" -> ''
  RED   ('.', 1, '{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":973,"failed":1,"failures":[{"test":"P\\\\Packages\\\\core\\\\tests\\\\Changes\\\\ChangeJournalTest::__pest_evaluable_it_never_compacts_a_remove_away","file":"/home/vostrikov/projects/packages/vaulter/packages/core/tests/Changes')
  GREEN ('.', 0, '{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":2,"duration_ms":957}')
  restored sha256 e7c7261ed72495fd75bb4b7c36c217f23da3a0a10c73815b51d22ff0d8c1f700
b packages/core/src/Operations/ChangeOperations.php
  injected: 'if (!$inTransaction) {\n            $this->assertCurrent($position, $this->heads->find($thi' -> ''
  RED   ('.', 1, '{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":903,"failed":1,"failures":[{"test":"P\\\\Packages\\\\core\\\\tests\\\\Changes\\\\ChangeFeedReadTest::__pest_evaluable_it__f__notices_a_prune_that_runs_between_reading_the_head_and_reading_the_entries","file":"/home/vostrikov/pr')
  GREEN ('.', 0, '{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":2,"duration_ms":895}')
  restored sha256 a3bbb4294295cc0b3c2771aaa934ff2e6ac7446c667eb68a7cbf32a442ebd6f2
c packages/core/src/Operations/ChangeOperations.php
  injected: '$nodes = $this->guard->visible(context: $context, nodes: $candidates);' -> '$nodes = is_array($candidates) ? $candidates : $candidates->values()->all();'
  RED   ('.', 1, '{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":975,"failed":1,"failures":[{"test":"P\\\\Packages\\\\core\\\\tests\\\\Changes\\\\ChangeFeedReadTest::__pest_evaluable_it__b__turns_a_move_out_of_the_reader_s_view_into_remove_moved__out__without_a_snapshot","file":"/home/vostri')
  GREEN ('.', 0, '{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":2,"duration_ms":954}')
  restored sha256 a3bbb4294295cc0b3c2771aaa934ff2e6ac7446c667eb68a7cbf32a442ebd6f2
--- control d, first run: the E2E stayed green (a later subtree re-read of 'alpha' masked the missed remove):
d packages/core/src/Changes/ChangeJournal.php
  injected: 'keptTypes: self::reasonTypes());' -> 'keptTypes: []);'
  RED   ('.', 1, '{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":1024,"failed":1,"failures":[{"test":"P\\\\Packages\\\\core\\\\tests\\\\Changes\\\\ChangeJournalTest::__pest_evaluable_it_keeps_a_file_move_or_access_change_under_a_later_plain_upsert__D61_","file":"/home/vostrikov/projects/pack')
  RED   ('packages/drive', 0, 'Tests:    1 passed (13 assertions)')
  GREEN ('.', 0, '{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":3,"duration_ms":1052}')
  GREEN ('packages/drive', 0, 'Tests:    1 passed (13 assertions)')
  restored sha256 00fa83cc3312b28ca6092a359365183107fe8c62af08db2938469870d7bbfc87
--- control d after moving the E2E file to the root:
d packages/core/src/Changes/ChangeJournal.php
  injected: 'keptTypes: self::reasonTypes());' -> 'keptTypes: []);'
  RED   ('.', 1, '{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":1010,"failed":1,"failures":[{"test":"P\\\\Packages\\\\core\\\\tests\\\\Changes\\\\ChangeJournalTest::__pest_evaluable_it_keeps_a_file_move_or_access_change_under_a_later_plain_upsert__D61_","file":"/home/vostrikov/projects/pack')
  RED   ('packages/drive', 1, 'Tests:    1 failed (3 assertions)')
  GREEN ('.', 0, '{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":3,"duration_ms":1039}')
  GREEN ('packages/drive', 0, 'Tests:    1 passed (13 assertions)')
  restored sha256 00fa83cc3312b28ca6092a359365183107fe8c62af08db2938469870d7bbfc87
```

- (a) compaction deletes removes → ChangeJournalTest 'never compacts a remove away' red.
- (b) no head post-check → ChangeFeedReadTest '(f) notices a prune…' red.
- (c) snapshot of an invisible node → ChangeFeedReadTest '(b) … moved_out' red.
- (d) D61 off → ChangeJournalTest D61 and DriveChangesEndToEndTest replica red.

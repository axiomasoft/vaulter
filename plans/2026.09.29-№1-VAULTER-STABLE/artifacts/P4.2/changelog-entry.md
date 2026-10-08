### Added
- Copying a document node (alone or inside a folder) creates a new document with one checkpoint and the source's working state; history, collaboration epoch and comments are not copied (PLAN2.P4.2).
- `NodeContentLifecycleRegistry::registerFallback()`, the `vaulter.content_lifecycles` container tag and the `PurgeOnlyContentLifecycle` marker interface.

### Changed
- A duplicate node content lifecycle registration throws `DuplicateNodeContentLifecycleException` (`duplicate_registration`) instead of `LogicException`.
- Drive's `DocumentPurgeLifecycle` is a fallback replaced by the documents package; purging a document deletes its checkpoints in batches of 500 before the document row.

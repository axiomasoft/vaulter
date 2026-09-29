# Remaining-work result carriers

Each R01–R20 slice in [REMAINING-WORK.md](../REMAINING-WORK.md) writes one result file named `RNN.md` here. The result records the task ID and source revision, scope/commit, relevant source and run IDs, raw-artifact links, commands with actual exit codes, measured verdict, limitations, and newly unlocked/blocked IDs. Keep raw load evidence in its owning immutable run directory and link to it; this folder does not replace it.

Full P6 queue/sink ledgers are stored here as compressed `RNN-P6-<run-id>.tar.gz` evidence archives. Extract one to reproduce a verifier run; the equivalent unpacked JSONL and prior-ledger copies are local-only to keep the repository below GitHub's per-file limit.

Likewise, retain parsed mutation-ID results, summaries and compressed runner logs, but not Pest's disposable per-mutant cache directory.

A result may honestly end RED, unevaluated or deferred. A missing check cannot be marked GREEN. Update the State cell in the execution map only after the result exists. No result files are precreated.

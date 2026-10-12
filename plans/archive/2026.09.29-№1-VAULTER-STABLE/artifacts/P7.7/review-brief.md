You are an independent, read-only reviewer (do not write or edit any file). Review the uncommitted work of plan item P7.7 in this repository (git diff + untracked files, ignore everything under plans/).

Goal of the item: every PHP/TS/Vue/bash fenced block in docs/{getting-started,guides,extending} (en + docs/ru) carries a marker comment `<!-- recipe: <path>#<region> -->` or `<!-- snippet: config|shell|host|signature <FQCN…> -->`; `scripts/check-doc-snippets.php docs` fails on an unmarked or diverging block. `recipe` blocks equal (byte-for-byte after dedent; leading `use`/`import` lines excluded and checked separately; ru compared without comments) a `// [recipe:<name>]` … `// [/recipe]` region of a Pest/vitest test that executes behaviour.

Files: scripts/check-doc-snippets.php; packages/{core,drive,documents,media}/tests/Recipes/*; packages/vue/tests/recipes/*; tests/Pest.php and packages/*/tests/Pest.php bindings; docs fragments (git diff docs/); CHANGELOG.md.

Review for: (1) correctness bugs in the checker (false negatives that let a stale fragment pass, false positives, crashes, regex flaws); (2) recipe tests that mirror text without asserting behaviour; (3) docs fragments that still describe API that does not exist in the code (verify against packages/*/src and packages/vue/src); (4) en/ru mismatches; (5) violations of project rules in CLAUDE.md / .claude/rules.

Run these to check: `scripts/php84 scripts/check-doc-snippets.php docs`; `cd packages/drive && ../../scripts/php84 vendor/bin/pest --ci tests/Recipes` (also documents, media); `cd packages/vue && npx vitest run tests/recipes`.

For EVERY finding give a reproduction (a failing command, a mutated doc/test that wrongly passes, or exact output). A finding without a reproduction counts as at most "plausible". Report findings ranked by severity as: file:line — claim — reproduction. If nothing survives, say "no findings". Stop after the report.

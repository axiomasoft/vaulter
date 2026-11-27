---
title: Scrubbing Vaulter Git history
---

# Scrubbing Vaulter Git history

Use this rehearsal before publishing or mirroring Vaulter. It removes the retired planning tree and audits from the history of a disposable local clone. Confirm that the private archive is readable before starting. The commands require Bash, Git and `git-filter-repo` on `PATH`.

## Rehearse in an isolated clone

Run this block from the root of the Vaulter working repository. The bare clone copies local branches and tags into independent object storage. Bring any other refs intended for publication into the validation scope before publishing.

```bash
(
set -euo pipefail
umask 077
source_repo=$(pwd -P)
scratch=$(mktemp -d /tmp/vaulter-history-scrub.XXXXXX)
printf 'Disposable clone workspace %s\n' "$scratch"
git clone --no-local --bare -- "$source_repo" "$scratch/vaulter.git"

cd "$scratch/vaulter.git"
before=$(git log --all --format=%H -- plan audits)
test -n "$before"
printf 'Commits touching retired paths before rewrite: %s\n' "$(printf '%s\n' "$before" | wc -l)"

git filter-repo --invert-paths --path plan/tiptap-pro --path plan --path audits

after=$(git log --all --format=%H -- plan audits)
test -z "$after"
remotes=$(git remote)
test -z "$remotes"
git fsck --full
printf 'Commits touching retired paths after rewrite: 0\n'
)
```

## Before publication

Review the rewritten refs and the retained history for relocated copies, including earlier file names. Review commit messages, tags and release assets separately for private data. Rotate any exposed credentials. A rewrite changes affected commit IDs, so coordinate replacement clones, signed tags, and dependent mirrors with the repository owner before any publication. Preserve the rehearsal logs privately and discard the disposable clone after inspection.

The engineering standards cover which dependencies belong in the repository. [Engineering standards](../reference/engineering-standards.md)

The Git filter-repo manual explains fresh-clone checks and path selection. [Git filter-repo manual](https://github.com/newren/git-filter-repo/blob/main/Documentation/git-filter-repo.txt)

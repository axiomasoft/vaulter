GREEN

The diff is a straight owner swap to `axiomasoft/vaulter` in the 12 in-scope files. Suffixes, package names, the star prompt, and the docs URL helper stay aligned.

- Root `homepage` and the six package manifests (`core`, `drive`, `documents`, `media`, `filament`, `azgard`) change only `homepage`, `support.issues`, and `support.source`. `/issues` is unchanged, and no other suffix is added or dropped.
- `VaulterException::docs()` and `r07ErrorDocs()` both use `https://github.com/axiomasoft/vaulter/blob/main/docs/guide/errors.md#` plus the anchor. The collaboration error keeps `#unknown-collaboration-driver`.
- `askToStarRepoOnGitHub('axiomasoft/vaulter')` matches the required slug.
- No `name` field or `axioma-studio/vaulter-*` package id is touched. README, the docs site, and RFC 9457 `type` links are absent from the diff.
- The `[Unreleased]` **Fixed** note records the new repository URL and that vendor names stay `axioma-studio/vaulter-*`.

### Documentation layout and generated references (PLAN2.P7.6)

- Changed: the docs follow the reader's need — `getting-started/`, `concepts/`, `guides/`, `extending/`, `reference/` — in English and Russian; `docs/guide/` is gone (no redirects, the site is not published as 1.0). Search & Trash is merged into the trash guide, database compatibility into `reference/compatibility`, contracts into `reference/php-api`.
- Added: `vaulter:config:reference` and `vaulter:commands:reference` (`--check`) generate the key table of every package config and the table of `vaulter:*` commands into `docs/reference/config.md` and `commands.md` (en and ru).
- Added: `scripts/check-doc-links.php` — offline check of relative links and `#anchors` in `docs/` and `README.md`; `--mirror` checks that `docs/` and `docs/ru/` hold the same pages.
- Changed: error and config doc URLs in exception messages point to the new page paths (`docs/guides/errors.md`, `docs/guides/collaboration.md`, `docs/reference/config.md`).

### Command canon, scheduler, install and upgrade inspection (PLAN2.P7.2)

- Changed: seven maintenance commands were renamed to `vaulter:<area>:<verb>` without aliases; options and
  behaviour are unchanged. `vaulter:install` is now Vaulter's own command: it publishes the config of every
  installed package, prints the host settings to apply, never edits `config/*.php` or `.env`, and migrates
  only on request.
- Added: the packages put their maintenance commands on the Laravel scheduler (`withoutOverlapping`,
  `onOneServer`) when `vaulter.schedule.enabled` is `true`; the `schedule` blocks of `vaulter.php`,
  `vaulter-drive.php` and `vaulter-media.php` set the frequency of each task, `null` turns one off, and an
  invalid value fails boot. Scan reconcile is scheduled whenever the scan mode is not `off`, independent of
  webhooks.
- Added: `vaulter:upgrade:inspect [--json]`, a read-only report of 0.2 data that blocks the 0.3 upgrade.

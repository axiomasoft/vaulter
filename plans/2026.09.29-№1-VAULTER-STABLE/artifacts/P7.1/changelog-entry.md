### `vaulter:doctor` (PLAN2.P7.1)

- Added: `vaulter:doctor [--production] [--json] [--check=*] [--sample=1000] [--probe-storage]`, a read-only
  diagnosis of the host's Vaulter setup. Every package contributes checks by tagging services
  `vaulter.doctor_checks` (contract `Vaulter\Contracts\Doctor\DoctorCheck`); the catalogue covers the database,
  schema and migrations, configuration, routes, permission driver, tenancy, queue, scheduler, cache, webhook
  outbox leases and deliveries, storage disks, deduplication scope, upload staging, the scan scanner, policy
  activation, obligations, leases and scope, drive usage, document types, collaboration and the AzGuard bridge.
  `--json` prints one `vaulter.doctor/v1` document (schema in `packages/core/resources/schemas/doctor-v1.schema.json`).
  Exit code `1` on any error, `2` on a bad option. The only write is the opt-in storage probe.
- Changed: `vaulter:install` ends with the doctor report; its exit code stays `0`.
- Added: `ConfigNormalizer::usedDeprecatedKeys()`.

### Generators write a contract test (PLAN2.P7.3)

- Added: `vaulter:make:file-handler` and `vaulter:make:permission-driver` (core). Each writes the class under
  `app/Vaulter/<Kind>/` and a Pest test in `tests/Unit/Vaulter/` on `FileHandlerContractTests` /
  `PermissionDriverContractTests`, and prints the registration line.
- Changed: `vaulter:make:profile`, `vaulter:make:document-type` and `vaulter:make:exporter` also write a Pest test
  (profile registers and resolves its frozen policy; `DocumentTypeContractTests`; `ExporterContractTests`). An
  existing test is kept unless `--force` is passed.

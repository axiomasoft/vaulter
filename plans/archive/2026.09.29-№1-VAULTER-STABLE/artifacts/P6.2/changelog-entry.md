### Problem Details for the versioned API (PLAN2.P6.2)

- Added: `Vaulter\Http\Problems\ProblemRenderer`. Every failure of a `vaulter.v1.*` route, or of a path under
  `{routes.prefix}/v1`, answers `application/problem+json` (RFC 9457) with `type`, `title`, `status`, `code`,
  `detail`, `request_id`, `errors` and `meta`, plus `Content-Language`, `Cache-Control: no-store`,
  `X-Vaulter-Api-Version: 1` and `X-Request-Id` (PLAN2.D62, D63, D65). `title` and `detail` come from lang, never
  from the exception message; `meta` passes a recursive allowlist (`path`, `storage_key`, `tenant_id`, `token`,
  `sql`, `trace`, `file`, `line`, `password`, `secret` are dropped with a warning in the log). Framework
  exceptions map to `validation_failed`, `actor_required`, `permission_denied`, `rate_limited` (with
  `Retry-After`), `route_not_found`, `method_not_allowed` (with `Allow`), `payload_too_large`, `http_<status>` and
  `internal_error`. Host routes and unversioned Vaulter routes keep their answers (the `{message, code}` body stays
  until the legacy routes go).
- Added: `ProblemException::problemDetails(): array` (the `errors` map and the `meta` fields); every
  exception implementing it moved `renderExtras()` there (it was `protected`, it is now public API) and uses
  `Vaulter\Exceptions\Concerns\ProvidesProblemDetails` for the empty default and `problemHeaders()`
  (`Retry-After` of `concurrent_update`, `Upload-Offset`/`Tus-Resumable` of `upload_offset_mismatch`).
  `ConcurrentUpdateException::render()` and `OffsetMismatchException::render()` are gone.
- Added: `Vaulter\Exceptions\Http\PreconditionRequiredException` (`precondition_required`, 428;
  `missing(string $header)`, `wildcard()`).
- Added: `ProblemCatalog` with the tag `vaulter.problem_codes` (`ProblemCodeSource` per package), localized
  `resources/lang/{en,ru}/problems.php` in core, drive and documents, the command
  `php artisan vaulter:errors:reference {--check} {--path=*}` and the generated `docs/reference/errors.md`
  (`docs/ru/reference/errors.md`), whose headings are the anchors of the `type` URIs.
- Added: `RoutesConfig::versionedPrefix()` (`{prefix}/v1`).

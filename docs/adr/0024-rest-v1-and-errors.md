# ADR-0024 — REST v1 & errors

**Status:** proposed

> Transfers decisions D29–D31 of the 2026-09-29 stable audit
> (`audits/2026-09-29-stable-audit/opus/02-decisions.md#d29` … `#d31`) into the project ADR set.

## Context

- One middleware list wraps all routes — protected ones, public share links (`share/{token}`) and
  server-to-server hub callbacks (`collab/authenticate|store`). Adding `auth:sanctum` breaks public
  links and the hub (audit finding N09).
- `VaulterServiceProvider` registers global `Route::bind('node')` and `Route::bind('storage')` for
  the whole application; any host route with a `{node}` or `{storage}` parameter resolves to Vaulter
  models, and `node` is looked up by global id without drive/tenant (N10).

## Decision

**1. Errors: RFC 9457 and stable codes (D29).** All Vaulter exceptions implement
`Vaulter\Exceptions\Contracts\ProblemException` (`problemCode(): string`, `httpStatus(): int`,
`problemDetails(): array`); one `ProblemRenderer` is registered for requests to Vaulter routes and
returns `application/problem+json`. The "Why/Fix/Docs" message stays in the exception text for logs
but is not sent to the client as `detail`.

**2. REST v1 (D30).** URL version `/{prefix}/v1`; route names `vaulter.v1.*`; resource map,
cursors, `ETag`/`If-Match` and the error envelope as specified in the audit's HTTP API v1 chapter.
OpenAPI 3.1 is maintained by hand in `openapi/vaulter-v1.yaml`; contract tests check responses
against the schema; Vue TS types are generated from it.

**3. HTTP layer structure (D31).**

- Controllers are single-action (`__invoke`), `Http/Controllers/<Domain>/<Verb><Noun>Controller`.
- No global `Route::bind`: parameters are scalars, resolved through `HttpResourceResolver` inside
  Vaulter routes, taking drive and tenant into account (N10).
- Three middleware stacks: `routes.middleware.authenticated` (default `['api', 'auth:sanctum']`),
  `routes.middleware.public` (`['api']`), `routes.middleware.hub` (`['api']`) (N09).
- Controller flow: request → context (`HttpOperationContextFactory`) → Operations → Resource.

## Consequences

- Clients branch on stable problem codes and HTTP status, not on message text; diagnostic text
  stays server-side.
- Host routes are no longer hijacked by Vaulter bindings; node resolution is always drive- and
  tenant-scoped.
- Protected, public and hub surfaces get independent middleware, so authentication can be enabled
  without breaking share links or hub callbacks.
- The OpenAPI document is the single contract for server responses and generated client types.
- Controllers stay thin clients of the authorized Operations layer
  ([ADR-0020](/adr/0020-authorized-public-api)).

## Alternatives rejected

The source decisions D29–D31 record no rejected alternatives.

## References

- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d29`
- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d30`
- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d31`
- `audits/2026-09-29-stable-audit/opus/10-http-api-v1.md` (resource map, §6 error codes)
- `audits/2026-09-29-stable-audit/opus/01-review.md` — N09, N10

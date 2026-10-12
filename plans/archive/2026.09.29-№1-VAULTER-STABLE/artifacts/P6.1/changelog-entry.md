### REST v1: HTTP kernel and core routes (PLAN2.P6.1)

- Added: the REST v1 kernel in core. `HttpOperationContextFactory` runs every v1 controller as
  `Vaulter::drive($drive)->actingAs($user)->withCorrelationId(<request id>)`; `HttpResourceResolver::drive()` /
  `driveOfNode()` resolve route ids through the tenant-scoped repositories (unknown, foreign-tenant and
  deleting drives answer the same `drive_not_found`). Middleware `AssignCorrelationId` (`X-Request-Id`, kept when
  `^[A-Za-z0-9._:-]{1,64}$`, otherwise a ULID), `AddApiVersionHeader` (`X-Vaulter-Api-Version: 1`) and
  `ApplyResponseCachePolicy` are appended to the three stacks with `RecordVaulterRequestMetrics` and ranked first
  in the middleware priority (PLAN2.D62, D63, D65).
- Added: `RepresentationTag` — the node `ETag` `"{id}.{sequence}.{digest}"` over the caller's canonical JSON
  (abilities, starred, scan state, presentation, properties and head included) and the document tag
  `"{node}.{epoch}.{state_version}"`; `Preconditions` — `If-Match` required (`428`, no `*`), strong comparison,
  `412 node_sequence_mismatch` with `meta.node_id` and the expected sequence handed to the operation's CAS;
  `If-None-Match` → `304` only after the node was found and authorized (PLAN2.D62, C07).
- Added: `ContentResponder`, the single egress point: `strict` streams with RFC 6266 `Content-Disposition`,
  `nosniff`, `Content-Security-Policy: sandbox; default-src 'none'`, `Accept-Ranges: none`,
  `Cache-Control: private, no-store`; `direct_signed` answers `302` to the signed URL of the exact revision with
  `no-store` and `Referrer-Policy: no-referrer`; `423 content_unavailable` before any byte (C08).
- Added: `NodeOperations::abilitiesMany()` and `OperationGuard::abilitiesMany()` — the abilities of a page of
  nodes from one batch evaluation, constant query count (PLAN2.D33).
- Added: core v1 routes and invokable controllers — grants (`grants.index/store/destroy`), inheritance
  (`access.inheritance.restrict/restore`), shares (`shares.index/store/update/rotate/destroy`), public shares
  (`public-shares.show/nodes/download`), activity (`activity.drive/node`), change journal
  (`changes.start/index`), webhook endpoints (`webhook-endpoints.index/store/show/update/deactivate/rotate-secret/destroy`)
  and deliveries (`webhook-deliveries.index/redeliver`); `PageResponse` (`{data, meta: {next_cursor, per_page}}`);
  resources `NodeResource` (v1), `GrantResource`, `ShareResource`, `MintedShareResource`, `ActivityResource`,
  `ChangeResource`, `WebhookEndpointResource`, `WebhookDeliveryResource`; `WebhookDeliveryStoreRepository::pageForEndpoint()`.
- Added: problem codes `share_password_required` (401), `webhook_endpoint_not_found` (404) and
  `webhook_delivery_not_found` (404) with `SharePasswordRequiredException`, `ShareNotFoundException`
  (`share_not_found`), `WebhookEndpointNotFoundException` and `WebhookDeliveryNotFoundException`.
- Changed: the public share routes answer problem+json, are `private, no-store` with `Referrer-Policy: no-referrer`
  on every answer, address children by opaque id (`?node=`), and `show` no longer embeds children.
- Changed: `ChangeOperations` with the journal off throws `ProfileRestrictionException` instead of
  `LogicException`; `PreconditionRequiredException::wildcard()` reports `reason: wildcard_not_allowed`;
  `StaleNodeException` carries `meta.node_id`.
- Removed: the unversioned core routes and `GrantController`, `ShareController`, `ActivityController`,
  `PublicShareController`, `WebhookEndpointsController`, `NodeGrantResource`, `NodeShareResource`,
  `StoreNodeGrantRequest`, `DestroyNodeGrantRequest`. The previous node shape stays as the internal
  `LegacyNodeResource` for the unversioned drive and documents routes.


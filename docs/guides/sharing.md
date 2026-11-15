---
title: Sharing
description: Bearer share-links — a separate, token-resolved public channel that grants exactly the abilities of one role, to a chosen audience, on its node-set, intersected with item-safety.
outline: deep
---

# Sharing

A **bearer share-link** is a secret token that grants access to a single node (and,
optionally, its subtree) **without the recipient being a registered subject**. It is the
**separate sharing channel** — a share is resolved in its *own* pass and grants **exactly
the abilities of its role** on its own node-set, intersected with item-safety. It is
**never unioned with the identity ACL**: the `AccessResolver` is never consulted on a
share-authorized request.

A link has four settings that matter:

| Setting    | Values | Meaning |
| ---------- | ------ | ------- |
| **role**   | `previewer`, `reader`, `commenter`, `writer` | What the holder may do. A previewer sees the node but never receives the original (no `download`). `organizer` and `owner` can never be shared. |
| **audience** | `anyone`, `authenticated`, `tenant` | Who may open it: the token alone, a signed-in host user, or a signed-in user whose host tenant is the drive's tenant. |
| **scope**  | `item`, `subtree` | The node only, or the node and its descendants down to the next inheritance boundary. |
| **generation** | integer | Raised by every rotation; ends every visit opened with the previous token. |

::: info Two distinct channels
This page documents **node** share-links (`v_node_shares`, the Drive channel). They are evaluated as a separate pass from identity grants and never unioned with them ([ADR-0013](/adr/0013-two-sharing-channels)).
:::

## Roles and audiences

| Role        | Abilities | Audience |
| ----------- | --------- | -------- |
| `previewer` | `view` | any |
| `reader`    | `view`, `download` | any |
| `commenter` | `view`, `download`, `comment` | any |
| `writer`    | commenter + `edit`, `create`, `move`, `copy`, `trash`, `restore` | `authenticated` or `tenant` only |
| `organizer`, `owner` | — | never (`share_role_not_allowed`) |

A role version is stored with the link (`role_key`, `role_version`) and keeps its meaning
forever. The pair is checked again on **every** visit: a stored combination the rules no
longer allow resolves to `404`.

`tenant` means the visitor is signed in **and** the host's `TenantResolver::current()` equals
the drive's tenant — an exact comparison of tenant keys, never an e-mail suffix. A visitor from
another tenant gets the same opaque `404` as for a wrong token. It needs
`vaulter.tenancy.enabled`; without tenancy, creating a `tenant` link throws
`InvalidArgumentException`.

## How it works

Minting and resolving a share are governed by two different authorities:

- **Minting** a link goes through the **identity ACL**: the authenticated owner must hold
  `Ability::Share` on the node. This is the gate on *who may create a public link*.
- **Resolving** a link is the **separate sharing pass**: the public endpoints carry no auth
  middleware — only the `ResolveShareToken` middleware, which hashes the token, looks up the
  live share, runs the gates, intersects with item-safety, and produces a `ShareContext`.
  That context grants only the role's abilities on its node-set — nothing inherited from any
  identity.

The raw token is **never stored**. It is `base64url(random_bytes(32))` (43 characters). Only its
`sha256` hash (`token_hash`, a `UNIQUE` `char(64)`) and a short, non-secret `token_prefix` are
persisted. The raw token is returned to the creator **exactly once** at mint time
(`MintedShareLink`) and is never re-derivable.

## The owner surface

The authenticated owner routes of the [REST API v1](/reference/rest-api#share-links) call
`Vaulter::drive($drive)->actingAs($user)->shares()`. Every action requires `Ability::Share` on
the node: a caller who can see the node but not share it gets `403 permission_denied`, one who
cannot see it `404 node_not_found`.

| Method   | Route                                        | Name             |
| -------- | -------------------------------------------- | ---------------- |
| `GET`    | `/v1/nodes/{node}/shares`                    | `shares.index`   |
| `POST`   | `/v1/nodes/{node}/shares`                    | `shares.store`   |
| `PATCH`  | `/v1/nodes/{node}/shares/{share}`            | `shares.update`  |
| `POST`   | `/v1/nodes/{node}/shares/{share}/rotate`     | `shares.rotate`  |
| `DELETE` | `/v1/nodes/{node}/shares/{share}`            | `shares.destroy` |

Routes are mounted under the configured prefix plus `/v1` (default `api/vaulter/v1`) and named
`vaulter.v1.*`. The `{share}` id is a plain id — update, rotate and revoke verify it belongs to
the authorized node, so a foreign share id can never be changed through this node (and yields a
`404` that leaks nothing).

### Create a link

`POST /v1/nodes/{node}/shares` mints a link and returns the raw token **once** (`201`). Every
input is optional. The validated request (`StoreShareLinkRequest`) accepts:

| Field        | Type     | Default        | Notes |
| ------------ | -------- | -------------- | ----- |
| `role`       | string   | `reader`       | A role key. Which roles fit which audience is decided by the Action (`share_role_not_allowed`). |
| `audience`   | string   | `anyone`       | `anyone`, `authenticated` or `tenant`. |
| `scope`      | string   | `item`         | `item` (the node only) or `subtree` (the node + its descendants). |
| `password`   | string   | `null`         | 1–255 chars; hashed before it touches the DB (`Hash::make`) — never stored raw. |
| `expires_at` | ISO-8601 | now + 72 h     | Must be `after:now`, within `vaulter-drive.sharing.max_ttl_hours` **and** the drive profile's `maxShareLinkTtlDays`. |
| `max_uses`   | int      | `null` (∞)     | The number of successful redemptions; `min:1`. |

::: code-group

<!-- snippet: shell -->

```bash [curl]
# Mint a read-only, subtree, password-gated link for signed-in users that expires in 7 days.
curl -X POST https://app.test/api/vaulter/v1/nodes/01J.../shares \
  -H 'Authorization: Bearer <user-token>' \
  -H 'Content-Type: application/json' \
  -d '{
        "role": "reader",
        "audience": "authenticated",
        "scope": "subtree",
        "password": "s3cret",
        "expires_at": "2026-07-07T00:00:00Z",
        "max_uses": 100
      }'
```

<!-- snippet: host -->

```php [PHP]
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Sharing\ShareAudience;

// shares() authorizes Ability::Share on the node; the Action only mints + persists.
$minted = Vaulter::drive($owner)->actingAs($user)->shares()->create($node, new ShareLinkInput(
    role: 'reader',
    audience: ShareAudience::Authenticated,
    scope: GrantScope::Subtree,
    expiresAt: now()->addDays(7),
    password: 's3cret',
    maxUses: 100,
));

// The raw token is here and ONLY here — returned exactly once.
$rawToken = $minted->token;   // 43 characters
$link     = $minted->link;    // ShareLinkSnapshot (no token)
```

<!-- snippet: host -->

```vue [Vue]
<script setup lang="ts">
import { useShares } from '@vaulter/vue'

const { create, lastCreated, clearLastCreated } = useShares(nodeId)

async function mint() {
  const created = await create({
    role: 'reader',
    audience: 'authenticated',
    scope: 'subtree',
    password: 's3cret',
    maxUses: 100,
  })
  // created.token + created.url are surfaced ONCE via lastCreated.
  navigator.clipboard.writeText(created.url)
}
</script>
```

:::

The create response carries the raw `token` and a full `url` (built from
`config('vaulter-drive.sharing.url_base')` + the token). **Every other response omits them** — the
token is unrecoverable from the stored row, so capture it now. The response is never stored by a
cache (`Cache-Control: no-store`).

```json
{
  "data": {
    "id": "01J...",
    "node_id": "01J...",
    "role": "reader",
    "role_version": 1,
    "audience": "authenticated",
    "scope": "subtree",
    "expires_at": "2026-07-07T00:00:00.000000Z",
    "max_uses": 100,
    "use_count": 0,
    "has_password": true,
    "token_generation": 1,
    "created_by": "42",
    "created_at": "2026-06-30T10:00:00.000000Z",
    "token": "kQ3aB7xZ...43-char-raw-token...",
    "url": "/share/kQ3aB7xZ...43-char-raw-token..."
  }
}
```

### List links

`GET /v1/nodes/{node}/shares` returns the node's live links as `{ "data": [ … ], "meta": { "next_cursor": … } }`,
in pages (default 50, at most 200; the pages show the current state, not a snapshot). Each carries the role, audience, scope, the password flag,
the token generation and the counters (`use_count`, `max_uses`, `expires_at`) — but **never**
the `token`, its hash or its prefix.

An empty page may still carry a non-null `next_cursor`, so read until it is `null`.
`client.shares.list()` does that for you and returns the complete list or rejects (no partial
list); `client.shares.page()` is the manual loop:

<!-- snippet: host -->

```ts
let cursor: string | null = null
do {
  const page = await client.shares.page(nodeId, { cursor })
  apply(page.data)
  cursor = page.meta.next_cursor
} while (cursor !== null)
```

### Change a link

`PATCH /v1/nodes/{node}/shares/{share}` changes `expires_at`, `max_uses`, `password` and
`audience` of a **live** link. **The token does not change.** A field you leave out stays;
`"password": null` removes the password and `"max_uses": null` removes the budget. The role
and the scope are fixed at creation — a different grant is a different link — and an audience
change is refused (`share_role_not_allowed`) when the stored role does not fit the new
audience. The new expiry obeys the same bounds as at creation. Changing the password or the
audience ends the link sessions opened before the change.

<!-- snippet: host -->

```php
use Vaulter\Drive\Data\Sharing\ShareLinkUpdate;

Vaulter::drive($owner)->actingAs($user)->shares()->update($node, $share, new ShareLinkUpdate(
    expiresAt: now()->addDay(),
    removePassword: true,
));
```

### Rotate a link

`POST /v1/nodes/{node}/shares/{share}/rotate` replaces the token. The **old token stops
resolving the moment the rotation commits**, `token_generation` is raised by one so every
visit opened with the old token is void, and the new raw token comes back **once**. Role,
audience, expiry, budget, password and `use_count` stay. Rotating a revoked link is a `404`.

<!-- snippet: host -->

```php
$minted = Vaulter::drive($owner)->actingAs($user)->shares()->rotate($node, $share);
$minted->token; // the new raw token, shown once
```

### Revoke a link

`DELETE /v1/nodes/{node}/shares/{share}` stamps `revoked_at` so the share **never resolves
again** (the resolver filters on `revoked_at`). It is **idempotent** — re-revoking an
already-revoked share is a silent no-op that emits nothing.

## The public surface

The public, token-resolved routes run on the `public` stack: **no auth middleware**, the
`throttle:vaulter-public` limiter, then `ResolveShareToken`, which produces the `ShareContext`.
The only authorization here is the role of the share on its own node-set. Every answer — errors
and throttling included — is `Cache-Control: private, no-store` with `Referrer-Policy: no-referrer`,
so neither a cache nor a page opened from the link keeps the token-bearing URL.

| Method | Route                                 | Name                     | Required ability |
| ------ | ------------------------------------- | ------------------------ | ---------------- |
| `GET`  | `/v1/shares/{token}`                  | `public-shares.show`     | `view` |
| `GET`  | `/v1/shares/{token}/nodes`            | `public-shares.nodes`    | `view`, `scope=subtree` only |
| `GET`  | `/v1/shares/{token}/download?node=`   | `public-shares.download` | `download` |

- **`show`** returns the target node's metadata (`SharedNodeResource` — deliberately leaner
  than the owner-side node resource; it carries **no** per-actor abilities map, and its `id`
  and `parent_id` are opaque projections, never the real node ids).
- **`nodes`** lists the in-scope direct children as a cursor page — **only** when
  `scope=subtree`. A `scope=item` share never lists; it exposes exactly one node
  (`404 share_not_found` otherwise).
- **`download`** streams a file: the shared node, or with `?node=<opaque id>` one of the
  children `nodes` lists — any other id is `404 share_not_found`. A **previewer** link gets
  `403 permission_denied`: the original is never handed out. Inheritance boundaries below the
  shared folder apply live, with the current tree. See [Downloads and revocation](#downloads-and-revocation).

### Redemptions and link sessions

`max_uses` counts **successful redemptions**, not requests. The first request that passes
every gate redeems one use (a single conditional `UPDATE` bounded by `max_uses`, `token_generation`
and `revoked_at`, so two concurrent visits can never overspend the budget, and a rotation or
revocation that commits first wins) and the response carries a signed **link session** in the
`X-Share-Session` header. Send it back and the requests that follow are the same visit —
opening a preview, paging a listing and fetching ranges cost **one** use. A request without
it is a new visit.

The session is an HMAC of the link, its generation and the settings that decide who may open
it (token, role, audience, password), valid for an hour and never beyond the link's expiry. It
carries no secret and grants nothing by itself: every request still passes the full resolve,
including the audience, the password and the policy checks below. A visitor whose session has
redeemed keeps working after the budget is spent; every *new* visit gets `404`.

::: code-group

<!-- snippet: shell -->

```bash [curl]
# The password travels in a header so the secret stays out of the URL/logs.
curl -i https://app.test/api/vaulter/v1/shares/<token> \
  -H 'X-Share-Password: s3cret'
# → X-Share-Session: eyJ2MXx...

# The rest of the visit presents the session and costs no further use:
curl https://app.test/api/vaulter/v1/shares/<token>/nodes \
  -H 'X-Share-Password: s3cret' -H 'X-Share-Session: eyJ2MXx...'

# Download the file behind the link (reader, commenter, writer):
curl https://app.test/api/vaulter/v1/shares/<token>/download \
  -H 'X-Share-Password: s3cret' -H 'X-Share-Session: eyJ2MXx...' -o file.bin
```

<!-- snippet: host -->

```vue [Vue]
<script setup lang="ts">
import { useVaulterClient } from '@vaulter/vue'

const client = useVaulterClient()

const node      = await client.publicShares.get(token)           // GET /shares/{token}
const children  = await client.publicShares.nodes(token)         // GET /shares/{token}/nodes
const dlUrl     = client.publicShares.downloadUrl(token)         // GET /shares/{token}/download
</script>
```

:::

### Resolution outcomes

`ResolveShareToken` translates the resolver's `ShareResult` into a problem+json answer. The
denial reasons are deliberately conflated so a probe cannot tell *which* gate failed.

| Outcome            | Answer | When |
| ------------------ | ------ | ---- |
| **Ok**             | proceed | A live share resolved; the `ShareContext` is stashed and the visit is redeemed. |
| **NotFound**       | `404 share_not_found` | Wrong / expired / revoked / rotated token, a new visit past `max_uses`, a visitor outside the `tenant` audience, a role or expiry the rules no longer allow, **or** the target failed item-safety (trashed / detached). |
| **PasswordRequired** | `401 share_password_required` | The share is password-gated and no/incorrect password was supplied. |
| **LoginRequired**  | `401 actor_required` | The audience is `authenticated` or `tenant` and no host user is signed in. |

::: tip Password header vs query
The middleware reads the password from the `X-Share-Password` header **first** (so the secret
never lands in a URL or access log), then falls back to a `password` query/body param.
:::

## Lifetime and the drive policy

A link always expires. Without `expires_at` the Action stamps now plus
`vaulter-drive.sharing.default_ttl_hours`; an explicit value may not pass
`vaulter-drive.sharing.max_ttl_hours`. On top of that, the drive profile can cap every link of its
drives:

<!-- snippet: host -->

```php
$profile->drivePolicy(fn (DrivePolicyBuilder $policy) => $policy->maxShareLinkTtlDays(7));
```

With the cap, `expires_at` may not be later than *now + 7 days* — when the link is created or
changed (`422 share_link_ttl_exceeded`) **and on every visit**. Tightening the policy therefore
takes effect at once: an older link whose expiry is beyond the new limit resolves to `404`
until its owner shortens it.

## Scope and item-safety

A share grants its role on a bounded **node-set** (`ShareContext`):

- **`scope=item`** → only the shared node itself.
- **`scope=subtree`** → the shared node **plus its descendants**, bounded to the **same
  drive tree** and live: access to a descendant follows the current tree and stops at the
  first node with `inherit_grants = false` (see `restrictInheritance()`). The walk can never
  escape to a sibling, an ancestor, or another drive.

Every resolution additionally passes **item-safety** (`§2.7`): the target node must not be
trashed (`trashed_at`) and must belong to a real drive (the tenant/drive boundary), the drive's
profile must still have `share_links` enabled, and the issuer of the link must still hold
`Ability::Share` on the node. A share on an unsafe node resolves to `NotFound` —
**indistinguishable from a wrong token**. On download, the revision must additionally be
servable — committed bytes and a clean scan state — or the answer is `423 content_unavailable`
before any byte.

## Downloads and revocation

Public share downloads go through the same single egress point as every authenticated
download ([File delivery](/reference/rest-api#file-delivery)): each request re-resolves the
link (token generation, revocation, expiry, audience, policy) and re-checks the scan state of
the exact revision before the first byte. Public share downloads are **always streamed**
through the application, also when `vaulter-drive.downloads.delivery_mode` is `direct_signed`, so a
link that is revoked or rotated stops serving at the next request. A download that has already
started is not interrupted. The headers are `Content-Disposition: attachment` with an RFC 6266
`filename*`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox`,
`Accept-Ranges: none` and `Cache-Control: private, no-store`.

## Security model

The hardening is in the code, not bolted on:

- **The raw token is never persisted.** Only `sha256(token)` and a non-secret prefix are
  stored; the raw token is returned once. (`ShareToken::hash`, `MintedShareLink`.) Tokens,
  hashes and passwords never reach the activity feed, the outbox, logs or exceptions — also on
  update and rotation.
- **Timing-safe compare.** Even after the hash lookup matches, the resolver re-verifies with
  `hash_equals` (`ShareToken::hashesMatch`) so the resolve path is never a timing oracle.
- **Opaque denials.** Wrong / expired / revoked / rotated / over-budget / foreign-tenant /
  unsafe-target all collapse to a single `404`.
- **Read-time liveness.** Expiry, revocation, rotation, the use budget and the policy limit are
  enforced **read-time**, never by a cron.
- **Atomic, race-free budget.** The redemption is a single conditional `UPDATE`, so two
  concurrent visits can never push `use_count` past the budget.
- **Allow-only.** A share is an allow-only grant of exactly its role's abilities — the identity
  ACL's override plays no part on this path.

## Configuration

<!-- snippet: config -->

```php
// config/vaulter-drive.php
'sharing' => [
    // The \Vaulter\Drive\Contracts\Sharing\ShareTokenGenerator used to mint the raw token.
    // Only its sha256 hash is ever stored. Default: RandomShareTokenGenerator (43 chars).
    'token_generator' => RandomShareTokenGenerator::class,

    // The public base a share URL is built from in the create response (the raw token
    // is appended). env: VAULTER_SHARE_URL_BASE
    'url_base' => env('VAULTER_SHARE_URL_BASE', '/share'),

    // How many leading characters of the raw token are stored as the non-secret prefix.
    'prefix_length' => 8,

    // Default and maximum lifetime of a link (hours).
    'default_ttl_hours' => env('VAULTER_SHARE_DEFAULT_TTL_HOURS', 72),
    'max_ttl_hours' => env('VAULTER_SHARE_MAX_TTL_HOURS', 720),
],
```

The swappable `NodeShare` model is bound via `config('vaulter-drive.models.node_share')`. The default share-token generator
mints 43 characters of `base64url(random_bytes(32))`; swap it by binding your own
`ShareTokenGenerator` — see [Custom share-token generator](/guides/recipes).

## Errors

| Code | Status | When |
| ---- | ------ | ---- |
| `share_role_not_allowed` | `422` | The role cannot be carried for the audience (writer for `anyone`; organizer or owner). |
| `share_link_ttl_exceeded` | `422` | `expires_at` is later than the profile's `maxShareLinkTtlDays` allows. |
| `unknown_role` | `422` | The role key is not registered. |
| `permission_denied` | `403` | A previewer link asked for the original, or the owner lacks `Share`. |
| `share_not_found` | `404` | A public request that opens nothing (see [Resolution outcomes](#resolution-outcomes)). |
| `share_password_required` | `401` | A password-protected link without its password. |
| `content_unavailable` | `423` | The shared file's revision is not clean. |

## Events

Both events are dispatched synchronously inside the transaction, so the activity and outbox
rows stage atomically with the change. Neither carries the raw token (the persisted
`NodeShare` holds the hash only), and their activity/outbox context lists `role`,
`role_version`, `audience` and `scope`.

| Event                                          | Dispatched when |
| ---------------------------------------------- | --------------- |
| `Vaulter\Drive\Events\Sharing\NodeShareCreated`      | A link is minted. Carries the `NodeShare` + `actorId`. |
| `Vaulter\Drive\Events\Sharing\NodeShareRevoked`      | A link is revoked (only on a real state change — re-revoke is silent). |

Updating and rotating a link emit no event of their own yet; the event pipeline of 1.0 adds
them.

## See also

- [Authorization](/concepts/access-model) — the identity ACL that gates *who may mint* a link.
- [Uploads](/guides/uploads) — the resumable ingest path that creates the file nodes you share.
- [Custom share-token generator](/guides/recipes) — swap the token format.
- [Frontend composables](/guides/vue) — `useShares` and the public client namespace.

---
title: Sharing
description: Bearer share-links — a separate, token-resolved public channel that grants exactly its own permission bitmask on its node-set, intersected with item-safety.
outline: deep
---

# Sharing

A **bearer share-link** is a secret token that grants access to a single node (and,
optionally, its subtree) **without the recipient being a registered subject**. It is the
**separate sharing channel** — a share is resolved in its *own* pass and grants **exactly
its own permission bitmask** on its own node-set, intersected with item-safety. It is
**never unioned with the identity ACL**: the `AccessResolver` is never consulted on a
share-authorized request.

::: info Two distinct channels
This page documents **node** share-links (`v_node_shares`, the Drive channel). The legacy
**document** shares (`GET /shares/{token}`, `Vaulter\Models\Documents\DocumentShare`) are a
separate, older surface that grants a stringly-typed level on a `Document`. New code should
use node share-links.
:::

## How it works

Minting and resolving a share are governed by two different authorities:

- **Minting** a link goes through the **identity ACL**: the authenticated owner must hold
  `Ability::Share` on the node (the `SHARE` bit). This is the gate on *who may create a
  public link*.
- **Resolving** a link is the **separate sharing pass**: the public endpoints carry no auth
  middleware — only the `ResolveShareToken` middleware, which hashes the token, looks up the
  live share, runs the gates, intersects with item-safety, and produces a `ShareContext`.
  That context grants only the share's bits on its node-set — nothing inherited from any
  identity.

The raw token is **never stored**. Only its `sha256` hash (`token_hash`, a `UNIQUE`
`char(64)`) and a short, non-secret `token_prefix` are persisted. The raw token is returned
to the creator **exactly once** at mint time (`MintedShareLink`) and is never re-derivable.

## The owner surface

`ShareController` (`Vaulter\Http\Controllers\Sharing\ShareController`) is the authenticated
owner surface. Every action requires `Ability::Share` on the node, enforced by the
`AccessResolver` — a denial is a `403` via `PermissionDeniedException`.

| Method   | Route                                | Action    | Ability        |
| -------- | ------------------------------------ | --------- | -------------- |
| `GET`    | `/nodes/{node}/shares`               | `index`   | `Share` on node |
| `POST`   | `/nodes/{node}/shares`               | `store`   | `Share` on node |
| `DELETE` | `/nodes/{node}/shares/{share}`       | `destroy` | `Share` on node |

Routes are mounted under the configured prefix (default `api/vaulter`). The `{share}` id is a
plain scalar (not route-model bound) — `destroy` verifies it belongs to the authorized node
before revoking, so a foreign share id can never be revoked through this node (and yields a
`404` that leaks nothing).

### Create a link

`POST /nodes/{node}/shares` mints a link and returns the raw token **once** (`201`). Every
input is optional; `permissions` defaults to plain `READ` (the safe minimum for a public
link). The validated request (`StoreShareLinkRequest`) accepts:

| Field             | Type      | Default          | Notes |
| ----------------- | --------- | ---------------- | ----- |
| `permissions`     | int       | `1` (`READ`)     | CRUDS bitmask, bounded `0..31` (`Permission::allBits()`). |
| `level`           | string    | `reader`         | `reader`/`commenter`/`writer`/`organizer`/`owner`; if `permissions` is omitted, the level's `bits()` are used. |
| `scope`           | string    | `item`           | `item` (the node only) or `subtree` (the node + its descendants). |
| `password`        | string    | `null`           | 1–255 chars; hashed before it touches the DB (`Hash::make`) — never stored raw. |
| `require_login`   | bool      | `false`          | Require an authenticated host user to resolve. |
| `allow_discovery` | bool      | `false`          | A non-secret discovery flag for owner-side UX. |
| `expires_at`      | ISO-8601  | `null` (never)   | Must be `after:now`. |
| `max_uses`        | int       | `null` (∞)       | The use budget; `min:1`. |

::: code-group

```bash [curl]
# Mint a read-only, subtree, password-gated link that expires in 7 days.
curl -X POST https://app.test/api/vaulter/nodes/01J.../shares \
  -H 'Authorization: Bearer <user-token>' \
  -H 'Content-Type: application/json' \
  -d '{
        "level": "reader",
        "permissions": 1,
        "scope": "subtree",
        "password": "s3cret",
        "expires_at": "2026-07-07T00:00:00Z",
        "max_uses": 100
      }'
```

```php [PHP]
use Vaulter\Actions\Sharing\CreateShareLinkAction;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;

// The controller authorizes Ability::Share first; the Action only mints + persists.
$minted = app(CreateShareLinkAction::class)->execute(
    node: $node,
    data: new ShareLinkInput(
        permissions: Permission::Read->value,
        level: GrantLevel::Reader,
        scope: GrantScope::Subtree,
        password: 's3cret',
        requireLogin: false,
        allowDiscovery: false,
        expiresAt: now()->addDays(7),
        maxUses: 100,
        grantedBy: $actorId,
    ),
);

// The raw token is here and ONLY here — returned exactly once.
$rawToken = $minted->token;   // e.g. "kQ3...48-char..."
$share    = $minted->share;   // persisted NodeShare (token_hash only)
```

```vue [Vue]
<script setup lang="ts">
import { useNodeShares } from '@vaulter/vue'

const { create, lastCreated, clearLastCreated } = useNodeShares(nodeId)

async function mint() {
  const created = await create({
    level: 'reader',
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
`config('vaulter.sharing.url_base')` + the token). **Every list/show response omits them** —
the token is unrecoverable from the stored row, so capture it now.

```json
{
  "data": {
    "id": "01J...",
    "node_id": "01J...",
    "token_prefix": "kQ3aB7xZ",
    "permissions": 1,
    "level": "reader",
    "scope": "subtree",
    "has_password": true,
    "require_login": false,
    "allow_discovery": false,
    "expires_at": "2026-07-07T00:00:00.000000Z",
    "max_uses": 100,
    "use_count": 0,
    "revoked_at": null,
    "created_at": "2026-06-30T10:00:00.000000Z",
    "token": "kQ3aB7xZ...48-char-raw-token...",
    "url": "/share/kQ3aB7xZ...48-char-raw-token..."
  }
}
```

### List links

`GET /nodes/{node}/shares` returns the live + revoked links for the node, cursor-paginated
(`per_page`, 1–200, default 50). The listing carries the grant shape, the gates and the
lifecycle counters (`use_count`, `max_uses`, `expires_at`, `revoked_at`) — but **never** the
`token` or `token_hash`. The non-secret `token_prefix` is exposed so an owner can recognise a
link in their UI.

### Revoke a link

`DELETE /nodes/{node}/shares/{share}` stamps `revoked_at` so the share **never resolves
again** (the resolver filters on `revoked_at`). It is **idempotent** — re-revoking an
already-revoked share is a silent no-op that emits nothing.

```php
use Vaulter\Actions\Sharing\RevokeShareLinkAction;

app(RevokeShareLinkAction::class)->execute(share: $share, actorId: $actorId);
```

## The public surface

`PublicShareController` is the public, token-resolved surface. Its routes carry **no auth
middleware** — only `ResolveShareToken`, which has already produced the `ShareContext`. The
only authorization here is the share's own bitmask on its own node-set.

| Method | Route                       | Action     | Required bit |
| ------ | --------------------------- | ---------- | ------------ |
| `GET`  | `/share/{token}`            | `show`     | `View` (`READ`) |
| `GET`  | `/share/{token}/nodes`      | `nodes`    | `View` (`READ`), `scope=subtree` only |
| `GET`  | `/share/{token}/download`   | `download` | `Download` (`READ`) |

- **`show`** returns the target node's metadata (`SharedNodeResource` — deliberately leaner
  than the owner-side node resource; it carries **no** per-actor abilities map). For a
  `scope=subtree` folder share the immediate children are embedded so a holder can browse one
  level without a second call.
- **`nodes`** lists the in-scope direct children — **only** when `scope=subtree`. A
  `scope=item` share never lists; it exposes exactly one node (a `404` otherwise).
- **`download`** streams the target file's current head version. The use budget is consumed
  **atomically before streaming** (a single conditional `UPDATE` bounded by `max_uses`), so an
  over-budget hit loses the race and is denied before a byte is served. A
  `temporaryUrl` off the `StorageDriver` is preferred (a 5-minute signed redirect); otherwise
  the bytes are streamed from the disk.

::: code-group

```bash [curl]
# The password travels in a header so the secret stays out of the URL/logs.
curl https://app.test/api/vaulter/share/<token> \
  -H 'X-Share-Password: s3cret'

# Subtree listing (scope=subtree only):
curl https://app.test/api/vaulter/share/<token>/nodes \
  -H 'X-Share-Password: s3cret'

# Download the file behind the link:
curl -L https://app.test/api/vaulter/share/<token>/download \
  -H 'X-Share-Password: s3cret' -o file.bin
```

```vue [Vue]
<script setup lang="ts">
import { useVaulterClient } from '@vaulter/vue'

const client = useVaulterClient()

const node      = await client.nodeShares.publicResolve(token)   // GET /share/{token}
const children  = await client.nodeShares.publicNodes(token)     // GET /share/{token}/nodes
const dlUrl     = client.nodeShares.publicDownloadUrl(token)     // GET /share/{token}/download
</script>
```

:::

### Resolution outcomes

`ResolveShareToken` translates the resolver's `ShareResult` into HTTP status. The denial
reasons are deliberately conflated so a probe cannot tell *which* gate failed.

| Outcome            | Status | When |
| ------------------ | ------ | ---- |
| **Ok**             | proceed | A live share resolved; the `ShareContext` is stashed. |
| **NotFound**       | `404`   | Wrong / expired / revoked / over-`max_uses` token, **or** the target failed item-safety (trashed / soft-deleted / detached). |
| **PasswordRequired** | `401` | The share is password-gated and no/incorrect password was supplied. The body carries `"password_required": true`. |
| **LoginRequired**  | `401`   | The share has `require_login` and no authenticated host user is present. The body carries `"login_required": true`. |

::: tip Password header vs query
The middleware reads the password from the `X-Share-Password` header **first** (so the secret
never lands in a URL or access log), then falls back to a `password` query/body param.
:::

## Scope and item-safety

A share grants its bits on a bounded **node-set** (`ShareContext`):

- **`scope=item`** → only the shared node itself.
- **`scope=subtree`** → the shared node **plus its descendants**, bounded to the **same
  storage tree**. The subtree walk can never escape to a sibling, an ancestor, or another
  storage — `containsNode` rejects any candidate outside the storage boundary, so a subtree
  share can never be used to climb out of its tree.

Every resolution additionally passes **item-safety** (`§2.7`): the target node must not be
trashed (`trashed_at`) or soft-deleted, and must belong to a real storage (the tenant/storage
boundary). A share on an unsafe node resolves to `NotFound` — **indistinguishable from a wrong
token**. On download, the blob must additionally be `Committed` (a pending or tombstoned blob
is never served publicly).

## Security model

The hardening is in the code, not bolted on:

- **The raw token is never persisted.** Only `sha256(token)` and a non-secret prefix are
  stored; the raw token is returned once. (`ShareToken::hash`, `MintedShareLink`.)
- **Timing-safe compare.** Even after the hash lookup matches, the resolver re-verifies with
  `hash_equals` (`ShareToken::hashesMatch`) so the resolve path is never a timing oracle.
- **Opaque denials.** Wrong / expired / revoked / over-budget / unsafe-target all collapse to
  a single `404`, so a probe can't distinguish "wrong token" from "trashed target".
- **Read-time liveness.** Expiry, revocation and the use budget are enforced **read-time**,
  never by a cron — an expired or revoked share simply never resolves (`NodeShare::scopeLive`,
  `isExhausted`).
- **Atomic, race-free budget.** `max_uses` is enforced with a single conditional `UPDATE`
  (`consumeUse`), so two concurrent public hits can never push `use_count` past the budget.
- **Allow-only.** A share is an allow-only grant of exactly its bits — the identity ACL's
  deny-veto plays no part on this path. `ShareContext::allows()` reads purely the share's
  bitmask.

## Configuration

```php
// config/vaulter.php
'sharing' => [
    // The \Vaulter\Contracts\Sharing\ShareTokenGenerator used to mint the raw token.
    // Only its sha256 hash is ever stored. Default: RandomShareTokenGenerator (48 chars).
    'token_generator' => RandomShareTokenGenerator::class,

    // The public base a share URL is built from in the create response (the raw token
    // is appended). env: VAULTER_SHARE_URL_BASE
    'url_base' => env('VAULTER_SHARE_URL_BASE', '/share'),

    // How many leading characters of the raw token are stored as the non-secret prefix.
    'prefix_length' => 8,
],
```

The swappable `NodeShare` model is bound via `config('vaulter.models.node_share')` and its
table name via `config('vaulter.table_names.node_shares')`. The default share-token generator
mints a 48-character random alphanumeric string; swap it for short/prefixed tokens by binding
your own `ShareTokenGenerator` — see [Custom share-token generator](/guide/recipes).

## Events

Both events implement `ShouldDispatchAfterCommit`, so a listener never sees an uncommitted
row. Neither carries the raw token (the persisted `NodeShare` holds the hash only).

| Event                                          | Dispatched when |
| ---------------------------------------------- | --------------- |
| `Vaulter\Events\Sharing\NodeShareCreated`      | A link is minted. Carries the `NodeShare` + `actorId`. |
| `Vaulter\Events\Sharing\NodeShareRevoked`      | A link is revoked (only on a real state change — re-revoke is silent). |

## See also

- [Authorization](/guide/authorization) — the identity ACL that gates *who may mint* a link.
- [Uploads](/guide/uploads) — the resumable ingest path that creates the file nodes you share.
- [Custom share-token generator](/guide/recipes) — swap the token format.
- [Frontend composables](/guide/vue) — `useNodeShares` and the public client namespace.

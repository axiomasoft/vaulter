---
title: Key rotation
---

# Key rotation

Operational procedure for the host owner. This page documents actions; it does not authorize or execute them.

## Symptom

A webhook receiver rejects signatures, a signing secret may be exposed, or the application encryption key must be replaced.

## Read-only diagnosis

Identify the affected endpoint/key version and rotation time from redacted deployment records. Inspect delivery status and receiver verification failures; never log signatures, raw keys or payload secrets. Check the webhook configuration:
<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --json --check=core.webhooks
```
A healthy configuration does not prove the receiver has the right secret. Check whether errors began with an `APP_KEY` change and whether encrypted secrets/receipts still decrypt.

## Bounded action

Require explicit owner authorization and a protected backup of encrypted data with its matching keys. For one endpoint, use the existing authorized webhook secret-rotation operation described in [Webhooks](/guides/webhooks). There is no Vaulter CLI key-rotation command. `vaulter-webhooks.secret_rotation_overlap` defaults to 86400 seconds; overlap is capped at seven days. During overlap both secrets sign; zero overlap removes the old secret immediately. Deliver the newly returned secret once via a private channel, update the receiver, and avoid a second rotation until it is ready: only one previous secret is retained.

Treat `APP_KEY` as a separate migration: replacing it can prevent decrypting receipts and stored webhook secrets. Rehearse a host-supported encryption migration with old keys available securely before switching writers. Direct storage URLs use the storage signing credentials: coordinate their rotation with the storage provider. Existing URLs are not revoked by Vaulter ACL changes; use short TTLs and `strict` delivery where immediate authorization is required. Hub key rotation belongs in private operations documentation.

## Postcondition

Verify a controlled delivery with the new secret, then confirm verification after overlap expiry. Inspect terminal delivery outcomes and decrypt representative encrypted records without exposing contents. Confirm new direct URLs work and account for outstanding URL TTLs; generic HTTP failures alone do not identify which key failed.

## Rollback

During planned overlap, restore the receiver configuration to the still-valid previous secret if necessary. After expiry or a second rotation, that fallback is unavailable. Never reinstate a compromised secret; contain delivery and rotate again with the owner. For failed encryption migration, stop writers and restore the matched data/key checkpoint, accounting for writes since it.

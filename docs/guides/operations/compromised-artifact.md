---
title: Compromised artifact
---

# Compromised artifact

Operational procedure for the host owner. This page documents actions; it does not authorize or execute them.

## Symptom

A published package/image digest differs from its approved provenance, a signing/build credential leaks, or a dependency/release is reported malicious.

## Read-only diagnosis

Record the affected package/version, immutable digest, source commit and redacted provenance/CI evidence. Compare the approved manifest and lockfile to deployed artifacts without installing or executing suspect code. Check registry metadata through a trusted read-only channel. Preserve access-controlled evidence; generic application health cannot prove artifact integrity. No Vaulter doctor check certifies registry revocation.

## Bounded action

Require explicit owner authorization and a verified backup before replacing deployments or changing registry/CI state. Isolate affected deployment and release pipelines, block further installation of the affected version through the owner registry policy and quarantine caches/artifacts while preserving evidence. Follow the repository SECURITY.md private reporting process; keep private implementation details, credentials and customer data out of public advisories.

The owner must consult the current primary registry documentation before any registry action. Packagist abandon is not a security yank/revoke. Removing an OCI tag does not invalidate its digest or already downloaded copies. There is no Vaulter artifact-revoke command. Rotate affected CI/publishing credentials in the private credential system, review their use, and rebuild a reviewed fix from trusted inputs in a clean pipeline. Coordinate disclosure and customer instructions privately; this runbook does not execute or qualify those operations.

## Postcondition

Verify the replacement immutable digest and provenance against the approved source/lockfile. Inventory affected deployments and caches; record which consumers still have the bad digest. Obtain owner evidence that installation controls and credential revocations took effect. Application HTTP outcomes alone cannot prove containment or removal from downstream caches.

## Rollback

If containment disrupts service, deploy only a verified clean version using the [release rollback](./release-rollback) procedure. Never restore a compromised artifact or credential to recover availability. Preserve quarantine and evidence; reverse an overbroad installation block only after owner verification of the clean replacement.

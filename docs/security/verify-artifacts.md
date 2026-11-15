---
title: Verifying release artifacts
---

# Verifying release artifacts

Every release flow publishes ONE accepted artifact; the publisher never rebuilds it. See
[ADR-0038](/adr/0038-supply-chain-and-artifact-verification) for the decision and its limits.

| Flow | Accepted artifact | What the publisher checks |
| --- | --- | --- |
| PHP | the prepared split record from `scripts/release/build-artifacts.php` (split tree and commit, content map, ZIP, SBOM, `SHA256SUMS`, resolution, policy and tool digests) | `--require-approved` authenticates the external approval bytes against the protected `VAULTER_PREPARED_APPROVAL_SHA256`, then binds every identity field; `--materialize-split` rebuilds the commit from the prepared bytes only; the push is atomic and never forced |
| npm | the single `npm pack` tgz of the pack job (no credential) | `scripts/release/artifact-gate.py npm` first authenticates the external approval bytes against the protected `VAULTER_ARTIFACT_APPROVAL_SHA256`, then requires the tgz sha256, integrity, name, version and consumer evidence to be exactly what that approval accepted; only then `npm publish --ignore-scripts` runs on the verified file |
| OCI | the tested manifest digest | `scripts/release/artifact-gate.py oci`, same authentication, before any registry login: a `sha256:` digest (never a tag) equal to the approved tested digest, same platform set, SBOM, policy and tool digests and visibility; a private channel needs a destination probed and approved private |

## Checking by hand

1. Recompute the SHA-256 of the file you received and compare it with `SHA256SUMS` (it lists the ZIP and the SBOM, not itself).
2. For npm compare `npm view <name>@<version> dist.integrity` with the recorded `integrity` after the authorized publish; before it the identity is pending.
3. For OCI resolve the tag to its digest and compare it with the tested digest.

## Limits

- A private npm line needs `--channel private` with a destination probed private AND accepted private by the approval; a public, unprobed or missing visibility refuses even with valid approval bytes. The access (`restricted`/`public`) comes only from the verified record.
- `SHA256SUMS` is a checksum, not a signature. Build-provenance attestations exist only when `vars.VAULTER_ATTESTATIONS` is `true`; when it is `false` the provenance is **unavailable**, the artifact is not signed.
- Packagist may repack a ZIP: compare the exported file bytes and the source or dist ref, not our archive hash.
- The control plane that produces the approval, the protected variables (`VAULTER_PREPARED_APPROVAL_SHA256`, `VAULTER_ARTIFACT_APPROVAL_SHA256`), real qualification, signatures, registry integrity and installs are owner work and are not provisioned. Nothing in the repository creates an approval; without one every publisher refuses.

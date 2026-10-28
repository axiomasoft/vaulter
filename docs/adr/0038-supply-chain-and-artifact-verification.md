# ADR-0038 — Supply chain and artifact verification

Status: Accepted.

## Context

D11 pins actions and images and audits dependencies. A consumer must also be able to tell that what is published is what was
qualified: the source SHA alone, or a second build after qualification, proves nothing about the bytes (D18).

## Decision

- PHP: one prepare from the tagged commit produces the split tree and commit, the ZIP, the SBOM and `SHA256SUMS` from the same bytes. The publisher authenticates the external approval against the protected `VAULTER_PREPARED_APPROVAL_SHA256` before parsing it, binds every identity field, and pushes the prepared commit atomically without force; an existing branch or tag that differs refuses.
- npm: Changesets only opens the version PR. One credential-free job packs the tgz once; the publish job first runs `scripts/release/artifact-gate.py npm` without any npm credential: the exact approval bytes must hash to the protected `VAULTER_ARTIFACT_APPROVAL_SHA256` before they are parsed, and the tgz must be exactly what that approval accepted for the source, package and version (sha256, integrity, consumer evidence). Only the secret step then runs `npm publish --ignore-scripts` of the verified file; it never packs, builds or installs. A tgz packed again after the approval does not inherit it.
- OCI: the tested manifest digest is promoted (`imagetools create` from `@digest`) only after the same authenticated approval accepted the digest, platforms, SBOM, policy, tool and visibility, before the registry login; a tag or a rebuild is not an identity. A private channel needs a destination probed and approved private.
- `vars.VAULTER_ATTESTATIONS=false` means attestation is unavailable; the artifact is not described as signed. `SHA256SUMS` is not a signature.
- `scripts/ci/workflow-policy.py` (`check_artifacts`) enforces the workflow shape; `tests/Repo/WorkflowPolicyTest.py` carries the named `artifact` fixtures.

## Consequences

- The approval control plane, the protected variables, real approvals, signatures and registry integrity do not exist yet; they are owner work (P8.2) and every real record stays pending and refused.
- The prepared split commit has no parent, so publishing onto an existing history stays refused until a history-aware derivation is qualified.
- Changesets no longer creates release tags from the publish job; tag creation for npm is deferred to the owner runbook.
- See [verification guide](/security/verify-artifacts).

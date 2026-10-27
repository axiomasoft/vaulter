# Private channel owner runbook

Status: **NOT QUALIFIED / NO PUBLISH**. This is an owner procedure, not a record of execution.
External repositories, accounts, credentials, permissions and visibility have not been verified.
Canonical P7/P8 qualification producers are not yet operational; do not treat the current routing
checks or this document as evidence that a release is ready. No tags or publication are authorized here.

The product decision is [ADR-0035](../docs/adr/0035-product-boundary-and-release-channels.md).
The destination and line authority is [packages.json](packages.json); resolve its exact entries
before any future release. This runbook stays outside the public docs site.

## Owner prerequisites and destination map

The owner must hold organization administration and billing authority, repository administration,
registry package administration and release-environment approval authority. Record the responsible
person, account/organization IDs, approved permissions and dated visibility evidence outside source.
CODEOWNERS and this file do not establish branch protection or environment enforcement.

| Artifact | Release line | Exact tag prefix | Destination and required access |
| --- | --- | --- | --- |
| PHP add-on | `php-collaboration` | `collaboration-v` | `axioma-studio/vaulter-collaboration` private split; Private Packagist for Vendors |
| Vue add-on | `npm-vue-collaboration` | `@vaulter/vue-collaboration@` | `@vaulter/vue-collaboration` on `https://registry.npmjs.org`, restricted |
| Hub image | `image-collaboration-hub` | `collaboration-hub-v` | `ghcr.io/axiomasoft/vaulter-collaboration-hub`, private |

Public PHP uses `php-public` / `v`; public Vue uses `npm-vue` / `@vaulter/vue@`.
A private tag must select exactly its own line. Reject the old image prefix
`vaulter-collaboration-hub-v`. Do not infer the GHCR namespace from the PHP split organization:
they differ. The workflow's actual repository-owner expansion must resolve to the manifest destination;
a mismatch blocks delivery and requires owner follow-up.

## Configure the private PHP channel (owner follow-up)

1. Create or verify the private split repository `axioma-studio/vaulter-collaboration`.
   Confirm it remains private and that the split writer has only the necessary destination access.
   The owner provisions `GH_ACCESS_TOKEN` in the protected release environment using the secret
   manager; never put a token in source, command history, URLs or validation logs.
2. Establish a Private Packagist organization with the **for Vendors** service and connect this
   private split through its authorized integration. Record the real organization short name and
   integration permissions. Do not submit the commercial package to public Packagist.
3. Create each customer in the vendor interface, assign only the purchased package and supported
   version range, set the contractual update cutoff and minimum stability, and issue that customer's token.
   A release-date cutoff limits future updates and does not revoke access to older versions;
   remove the package entitlement when revoking all access. Keep source URL delivery disabled unless explicitly contracted and authorized. A general
   organization token is not a substitute for customer credentials.
4. Record a dated successful provider access/visibility probe and a denied unauthorized access probe.
   Missing API access, unknown visibility, a public destination or unproven permissions blocks release.

Vendor customer repository URLs include both organization and customer short names. Copy the exact
URL supplied by the vendor interface; `https://repo.packagist.com/<org>/` alone is not the customer
endpoint. The customer configures the repository in the **consumer root** composer.json, because
repositories declared by dependencies do not propagate:

```sh
composer config repositories.vaulter composer 'https://<org>.repo.packagist.com/<customer>/'
```

In consumer-local, ignored `auth.json`, copy the provider's HTTP Basic host and username from the customer's Composer Instructions page.
The following template illustrates the token form; confirm the actual username before use:

```json
{
  "http-basic": {
    "<org>.repo.packagist.com": {
      "username": "token",
      "password": "${VAULTER_VENDOR_TOKEN}"
    }
  }
}
```

This JSON is a template: Composer does not expand that string automatically. The customer's
secret manager must supply the real value privately, or provide equivalent `COMPOSER_AUTH`.
Do not commit auth.json, echo secrets, or use a publisher token. Install only a supported,
qualified stable PHP version and lock the resolved dependencies in the consumer project.

## Configure restricted npm delivery (owner follow-up)

The owner establishes the paid `@vaulter` organization and appropriate teams, verifies that
`@vaulter/vue-collaboration` is restricted, and grants customers read access while limiting
publish access to the protected release identity. Provision a scoped, expiring `NPM_TOKEN`
through the secret manager with permissions required by the approved publisher. Private-package
provenance remains disabled under the current channel policy. Do not attempt a public fallback.
The hub retains `private: true` and is never an npm distribution.

Consumer-local `.npmrc` template (token supplied privately in the environment):

```ini
@vaulter:registry=https://registry.npmjs.org/
//registry.npmjs.org/:_authToken=${VAULTER_NPM_READ_TOKEN}
```

Use the customer's read identity, lock the supported client version and integrity, and keep token
values out of source and CI output. Organization membership and package permissions must actually
allow that customer to read the restricted package. A scope name alone grants no access.

## Configure private GHCR delivery (owner follow-up)

The owner verifies the exact GHCR package visibility is **private**, its linked repository and
inherited or explicit permissions, and the release workflow's write access. Obtain provider-side
evidence before enabling publication; a missing package or probe error is not a private verdict.
Bootstrap/provisioning of a missing destination requires a separate owner-controlled procedure;
this runbook does not bypass the fail-closed gate to create it. Never follow a stale workflow
comment suggesting the commercial image should be made public.

For consumers, authorize package read access and organization SSO as required. Use a classic PAT
with `read:packages` (or an explicitly granted consumer workflow identity). Load the read token
privately, then authenticate with standard input:

```sh
printf '%s' "${VAULTER_GHCR_READ_TOKEN}" | docker login ghcr.io --username "${VAULTER_GHCR_USER}" --password-stdin
```

Pull and deploy `ghcr.io/axiomasoft/vaulter-collaboration-hub@sha256:<qualified-digest>`.
The digest placeholder must be replaced by the accepted OCI identity; a mutable tag is not
qualification evidence. Consumers must use the supported PHP/client/hub tuple and public floors.

## Qualification and future delivery gate

1. Select source **S**, the exact release line/version and independently approved trust closure
   **T** plus authorization/exception record **A**. Candidate policy cannot authorize itself.
2. Through the future canonical P7/P8 producers, qualify the PHP split commit/tree and archive,
   immutable npm tgz and its integrity/hash, and OCI digest against S. Preserve source-to-artifact
   provenance and exact bytes. Build/qualification use S; freeze-metadata commit **F** only records
   the manifest. Never rebuild from F or replace an accepted artifact with a fresh build.
3. Validate the declared compatibility floors, wire produced/accepted versions, persisted formats,
   supported tuples and upgrade/rollback lanes. Current public floors are `^1.0`; raise them to
   `^1.N` when the consumed stable surface requires it. Before first release use the explicit seed;
   historical qualification is not yet applicable. Real installs, builds and runtime/server checks
   belong to the owner's P8.2 runbook and have not been executed by P4.4.
4. Bind owner-approved environment/branch enforcement and current destination visibility/access
   evidence to the same candidate. Check exact SHA, split tree/commit, tgz bytes and digest at the
   delivery boundary. Missing or mismatched identity, evidence, API permission, visibility or approval
   yields **NOT QUALIFIED / NO PUBLISH**. Do not tag, publish or claim completion.
5. Only a separately authorized owner release may deliver the exact qualified artifacts after all
   canonical gates work and pass. Existing publishers are not evidence that these producers exist.

## Revoke access and roll back (owner follow-up)

For a terminated customer, revoke the vendor customer token/package entitlement, npm team/package
read permissions and consumer tokens, and GHCR package access/token; rotate compromised publisher
credentials separately. Verify subsequent unauthorized downloads fail without logging credentials.
Revocation cannot retract archives or images already downloaded and does not itself define legal rights.

For a faulty release, suspend new delivery, preserve its immutable evidence and select an already
qualified supported tuple. Check persisted state format and upgrade/rollback compatibility before
rolling back the hub or client; do not assume that equal wire versions make storage downgrade safe.
If no supported rollback exists, stop and use the owner's recovery procedure. Never silently replace
published bytes or retag another digest as the qualified artifact.

## Follow-up register for the owner

- Verify organizations, private split repository, provider subscriptions and named administrators.
- Provision protected release secrets and least-privilege publisher/customer permissions privately.
- Configure Private Packagist vendor customers, constraints, expiry and exact consumer endpoints.
- Verify npm restricted access and private GHCR destination, namespace and denied-access evidence.
- Enforce branch/environment approval and supply independently approved T/A records.
- Supply commercial legal text and preserve third-party notices.
- Complete canonical P7/P8 producers and owner P8.2 artifact/runtime qualification before any release.

## Provider references

Provider procedures were consulted on 2026-10-27; account configuration remains unverified.

- [Private Packagist vendor customer setup](https://packagist.com/docs/setup-vendor)
- [npm private packages](https://docs.npmjs.com/creating-and-publishing-private-packages/)
- [GHCR authentication and access](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry)

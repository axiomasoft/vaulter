# ADR-0035 — Product boundary and release channels

Status: Accepted. Amends [ADR-0010](/adr/0010-php-npm-monorepo-release) and
[ADR-0031](/adr/0031-collaboration-package-and-durability).

## Context

The private monorepo is the source of both the public platform and commercial add-ons.
No release history has been established. Keeping these products together must not make
public consumers depend on private code, credentials or a commercial installation.
PLAN4 D3, D17 and D18 define the product and release boundary.

## Decision

Keep the private monorepo and select the destination by an explicit artifact channel.
The public MIT platform includes core, media, drive, documents, webhooks, filament,
the metapackage and the public Vue client. AzGuard remains excluded from release artifacts
until its upstream has a stable tag. The metapackage contains only core, media, drive and documents.
Public document sessions, authorization policy and protocol-neutral API/SPI remain usable
without a commercial backend. Public manifests, code, types and guides must not depend on
private packages; narrowly declared boundary notices are the only documentation exception.

Commercial PHP add-ons use private split repositories and Private Packagist for Vendors;
commercial npm clients use restricted scoped packages; backend images use private GHCR.
Their distribution and operational documentation stay in the private channel. The hub
package itself is image-only and is not published to npm. Public OpenAPI and Vue types own
the public contract; backend-specific schemas and clients remain privately owned.

Public PHP siblings share the `php-public` line and `v` prefix. Each private PHP, npm and
image product has its own release line and tag prefix, recorded in the release manifest.
A private patch or hotfix does not require releasing the other products. Private dependencies
use tested stable public floors (`^1.N`), never `self.version`, wildcards or unrestricted dev
constraints in a released artifact. The current initial floor is `^1.0`; future floors follow
the public API/SPI actually consumed and the minimum qualified compatibility lane.

Compatibility records must bind supported PHP/client/hub tuples to their public dependency
floors, produced and accepted wire versions, and persisted state formats. They include
upgrade and rollback constraints. Before the first stable release, use an explicit seed;
historical released-consumer qualification is not yet applicable. A declaration alone does
not prove parser acceptance or a successful installed-consumer run.

Public artifacts retain MIT. Private PHP metadata is `proprietary`; private npm/hub metadata
is `UNLICENSED`. Placeholder LICENSE files grant no rights. The owner supplies the commercial
agreement and final legal text, preserving all third-party notices. These metadata choices
do not themselves establish a legal grant.

Qualification binds source commit **S** to the canonical PHP split commit/tree, immutable npm
tarball bytes and integrity, and OCI digest. Freeze-metadata commit **F** records that result;
it is not the source to rebuild or publish. Trusted policy/tool closure **T** and approved
exceptions/authorization **A** must come from an independently approved authority, not from
the candidate being checked. Candidate edits cannot approve their own compatibility break.
Publication must use the exact qualified identities without rebuilding or substituting them.
Missing evidence, unknown destination visibility or a failed visibility probe means
**NOT QUALIFIED / NO PUBLISH**.

## Alternatives considered

- Moving commercial code to another source repository now adds coordination and duplication
  without improving the typed boundary; private splits provide the distribution separation.
- Leaving the channel undecided prevents controlled commercial delivery and independent releases.
- A single version for every product couples unrelated fixes and hides compatibility requirements.

## Consequences

The owner must configure external accounts, private destinations, customer permissions and
protected release approval before delivery can be qualified. The private owner runbook is
kept outside the public documentation site. Offline checks and this ADR do not prove external
access controls, archive installation or runtime compatibility. Canonical P7/P8 evidence producers
and owner qualification are still pending; existing release routing is not a completed release.
Final documentation review belongs to PLAN4 P8.5.

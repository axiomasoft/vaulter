# Security policy

Before 1.0, security fixes target `main` only. For the public 1.x line, the
current minor receives fixes; upgrade to that minor to obtain them. This policy
does not assert that a stable version has already been released.

Report vulnerabilities privately to Dmitry Vostrikov at
**dv.vostrikov@gmail.com**, the existing author contact in the monorepo
`composer.json`. Do not put credentials, personal data, exploit details or
customer documents in public issues. Send a minimal redacted reproduction,
affected package/version and impact; arrange any sensitive evidence privately.

GitHub private vulnerability reporting on the public split repository is an
additional route only when its owner enables it. If unavailable, use the author
contact above. Enabling reporting is an owner prerequisite, not a claim that it
is currently enabled. The canonical repository is
https://github.com/axiomasoft/vaulter (root Composer homepage).

Target acknowledgement is within three working days and initial assessment
within ten working days. Agree critical fix and disclosure dates privately;
these are response targets, not guaranteed remediation deadlines. Coordinate
publication with the maintainer after a fix is available; agree GHSA/CVE handling
and redact personal data and private customer details before disclosure.

Public MIT packages are in scope. Commercial collaboration artifacts use their
existing customer support channel and private distribution entitlements; if that
channel is unavailable, use the author contact to arrange private routing. Do
not post private source or commercial reports publicly. Public fixes are obtained
from the public package release channel; private fixes require the existing
Private Packagist, restricted npm or private image entitlement described in
CONTRIBUTING.md. Provisioning customer access and recovery remains an owner
prerequisite; this document grants no access or commercial license.

Please test only systems you own or have permission to assess, minimize data
access, and stop if customer data is exposed. Responsible private reporting is
welcomed; this policy makes no legal immunity or third-party safe-harbor promise.

CODEOWNERS supplies review routing only. Required code-owner reviews, branch and
environment protection, private reporting, and external release approvals must
be configured and verified by the owner before enforcement is qualified.

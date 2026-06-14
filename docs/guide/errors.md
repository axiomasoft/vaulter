# Error Reference

Every DocVault exception extends `DocVault\Exceptions\DocVaultException` and carries a
message in the form `[DocVault] {what}. Why: {…}. Fix: {…}. Docs: {url}` where the URL
points at the matching anchor below.

## document-not-found

`DocumentNotFoundException` — the requested document id does not exist, was soft-deleted,
or is outside the current workspace. Verify the id and the workspace filter. The API maps
this to `404`.

## permission-denied

`PermissionDeniedException` — your `PermissionResolver` returned `false` for the attempted
action. Grant the required level or adjust the resolver. The API maps this to `403`.

## unknown-document-type

`UnknownDocumentTypeException` — the supplied `type` is not in
`config('docvault.enabled_document_types')`. Enable it, or register a custom type via
`DocVault::registerDocumentType()`.

## invalid-tiptap-content

`InvalidTipTapContentException::missingDocNode()` — the content root must be
`{"type":"doc", …}`. Pass a valid TipTap JSON document or use `TipTapContent::empty()`.

## content-too-large

`InvalidTipTapContentException::tooLarge()` — the content exceeds
`config('docvault.max_document_size_kb')`. Split the document or raise the limit.

## unknown-collaboration-driver

`DocVaultException` from `DocVault::driver()` — the named driver is not registered under
`config('docvault.collaboration.drivers')`. Add an entry whose `class` implements
`CollaborationDriver`.

## missing-jwt

The Hocuspocus driver needs `firebase/php-jwt` to mint room tokens. Run
`composer require firebase/php-jwt`.

## missing-collab-secret

The Hocuspocus driver needs `DOCVAULT_COLLAB_SECRET`. Set the shared secret in your
environment.

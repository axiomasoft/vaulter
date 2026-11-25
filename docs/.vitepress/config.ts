import { defineConfig } from 'vitepress'

const repo = 'https://github.com/axioma-studio/vaulter'

// ---------------------------------------------------------------------------
// Guide sidebar
// ---------------------------------------------------------------------------

const guideSidebar = [
  {
    text: 'Getting Started',
    collapsed: false,
    items: [
      { text: 'What is Vaulter?', link: '/getting-started/introduction' },
      { text: 'Quick Start', link: '/getting-started/quick-start' },
      { text: 'Installation', link: '/getting-started/installation' },
      { text: 'Configuration', link: '/guides/configuration' },
      { text: 'Compatibility (API & database)', link: '/reference/compatibility' },
    ],
  },
  {
    text: 'Core Concepts',
    collapsed: false,
    items: [
      { text: 'Architecture', link: '/concepts/architecture' },
      { text: 'Glossary', link: '/concepts/glossary' },
      { text: 'Modules', link: '/concepts/modules' },
      { text: 'Operation pipeline', link: '/concepts/operations' },
      { text: 'Idempotency', link: '/concepts/idempotency' },
      { text: 'Storage Panels', link: '/concepts/drives-and-profiles' },
      { text: 'Drive — storages, folders & nodes', link: '/concepts/nodes-and-revisions' },
      { text: 'Storage & Blobs', link: '/concepts/storage-and-blobs' },
      { text: 'Document Handlers', link: '/extending/file-handlers' },
      { text: 'Document Types', link: '/extending/document-types' },
      { text: 'Collaboration Protocols', link: '/extending/collaboration-protocols' },
      { text: 'Exporters', link: '/extending/exporters' },
      { text: 'Upload Inspectors', link: '/extending/upload-inspectors' },
      { text: 'Profiles', link: '/extending/profiles' },
      { text: 'Writing a Handler/Engine', link: '/extending/viewers' },
    ],
  },
  {
    text: 'Working with Content',
    collapsed: false,
    items: [
      { text: 'Document Nodes', link: '/guides/documents' },
      { text: 'Uploads', link: '/guides/uploads' },
      { text: 'Trash & Search', link: '/guides/trash' },
      { text: 'Blob integrity & GC', link: '/guides/blob-integrity' },
      { text: 'Sharing', link: '/guides/sharing' },
      { text: 'Change Journal', link: '/concepts/events' },
      { text: 'The Outbox', link: '/concepts/events-outbox' },
      { text: 'Comments', link: '/guides/comments' },
      { text: 'Export', link: '/guides/export' },
    ],
  },
  {
    text: 'Authorization',
    collapsed: false,
    items: [
      { text: 'Authorization', link: '/concepts/access-model' },
      { text: 'Permission Drivers', link: '/extending/permission-drivers' },
      { text: 'Grants', link: '/guides/grants' },
      { text: 'Abilities', link: '/guides/abilities' },
      { text: 'Multi-tenancy', link: '/concepts/tenancy' },
    ],
  },
  {
    text: 'Realtime & UI',
    collapsed: false,
    items: [
      { text: 'Collaboration adapters', link: '/extending/collaboration-protocols' },
      { text: 'Vue package (@vaulter/vue)', link: '/guides/vue' },
      { text: 'Filament admin plugin', link: '/guides/filament' },
    ],
  },
  {
    text: 'Going Further',
    collapsed: false,
    items: [
      { text: 'Recipes', link: '/guides/recipes' },
      { text: 'Troubleshooting', link: '/guides/troubleshooting' },
      { text: 'Error Reference', link: '/guides/errors' },
      {
        text: 'Operations',
        link: '/guides/operations',
        collapsed: true,
        items: [
          { text: 'Backup and restore', link: '/guides/operations/backup-and-restore' },
          { text: 'Key rotation', link: '/guides/operations/key-rotation' },
          { text: 'Outbox dead letters', link: '/guides/operations/outbox-dead-letters' },
          { text: 'CAS integrity incident', link: '/guides/operations/cas-integrity-incident' },
          { text: 'Tenant export and deletion', link: '/guides/operations/tenant-export-and-deletion' },
          { text: 'Release rollback', link: '/guides/operations/release-rollback' },
          { text: 'Migration recovery', link: '/guides/operations/migration-recovery' },
          { text: 'Compromised artifact', link: '/guides/operations/compromised-artifact' },
        ],
      },
      { text: 'Threat Model', link: '/security/threat-model' },
      { text: 'Hardening Checklist', link: '/security/hardening-checklist' },
    ],
  },
]

const referenceSidebar = [
  {
    text: 'API Reference',
    collapsed: false,
    items: [
      { text: 'PHP API (facade & contracts)', link: '/reference/php-api' },
      { text: 'REST API', link: '/reference/rest-api' },
      { text: 'Configuration keys', link: '/reference/config' },
      { text: 'Events', link: '/reference/events' },
      { text: 'Error codes', link: '/reference/errors' },
      { text: 'Concurrency', link: '/reference/concurrency' },
      { text: 'Operation catalogue', link: '/reference/operations' },
      { text: 'Artisan commands', link: '/reference/commands' },
    ],
  },
]

const adrSidebar = [
  {
    text: 'Architecture Decision Records',
    items: [
      { text: 'Overview', link: '/adr/' },
      { text: '0001 — Collaboration transport', link: '/adr/0001-collaboration-transport' },
      { text: '0002 — Authorization delegation', link: '/adr/0002-authorization-delegation' },
      { text: '0003 — Embeddability & data model', link: '/adr/0003-embeddability-data-model' },
      { text: '0004 — Content format & versioning', link: '/adr/0004-content-format' },
      { text: '0005 — Unified node tree', link: '/adr/0005-unified-node-tree' },
      { text: '0006 — Content-addressed blobs & GC', link: '/adr/0006-content-addressed-blobs-gc' },
      { text: '0007 — Pluggable authorization', link: '/adr/0007-pluggable-authorization' },
      { text: '0008 — Media Library decision', link: '/adr/0008-media-library-decision' },
      { text: '0009 — Storage Panels', link: '/adr/0009-storage-panels' },
      { text: '0010 — PHP + npm monorepo & release', link: '/adr/0010-php-npm-monorepo-release' },
      { text: '0011 — Id strategy', link: '/adr/0011-id-strategy' },
      { text: '0012 — Dedup scope', link: '/adr/0012-dedup-scope' },
      { text: '0013 — Two sharing channels', link: '/adr/0013-two-sharing-channels' },
      { text: '0014 — Legacy DocVault excise', link: '/adr/0014-legacy-docvault-strangler' },
      { text: '0015 — Drive and documents package boundaries', link: '/adr/0015-drive-documents-package-split' },
      { text: '0031 — Collaboration package & durability', link: '/adr/0031-collaboration-package-and-durability' },
      { text: '0032 — Operation catalogue & concurrency', link: '/adr/0032-operation-catalog-and-concurrency' },
    ],
  },
]

export default defineConfig({
  lang: 'en-US',
  title: 'Vaulter',
  description:
    'Embeddable document & file management for Laravel — Storage Panels, a unified node tree, content-addressed blobs, pluggable authorization and real-time collaboration.',
  cleanUrls: true,
  lastUpdated: true,
  ignoreDeadLinks: false,

  themeConfig: {
    siteTitle: 'Vaulter',
    outline: { level: [2, 3] },
    nav: [
      { text: 'Guide', link: '/getting-started/introduction', activeMatch: '^/(getting-started|concepts|guides|extending|security)/' },
      { text: 'Reference', link: '/reference/php-api', activeMatch: '/reference/' },
      { text: 'ADRs', link: '/adr/', activeMatch: '/adr/' },
      { text: 'Changelog', link: `${repo}/blob/main/CHANGELOG.md` },
      { text: 'GitHub', link: repo },
    ],
    sidebar: {
      '/getting-started/': guideSidebar,
      '/concepts/': guideSidebar,
      '/guides/': guideSidebar,
      '/extending/': guideSidebar,
      '/security/': guideSidebar,
      '/reference/': referenceSidebar,
      '/adr/': adrSidebar,
    },
    socialLinks: [{ icon: 'github', link: repo }],
    search: { provider: 'local' },
    editLink: {
      pattern: `${repo}/edit/main/docs/:path`,
      text: 'Edit this page on GitHub',
    },
    footer: {
      message: 'Internals & ADRs · Released under the MIT License.',
      copyright: 'Copyright © 2026-present Axioma Studio',
    },
  },
})

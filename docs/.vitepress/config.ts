import { defineConfig } from 'vitepress'

const repo = 'https://github.com/axioma-studio/vaulter'

// ---------------------------------------------------------------------------
// English (root) sidebar
// ---------------------------------------------------------------------------

const guideSidebar = [
  {
    text: 'Getting Started',
    collapsed: false,
    items: [
      { text: 'What is Vaulter?', link: '/guide/introduction' },
      { text: 'Quick Start', link: '/guide/quick-start' },
      { text: 'Installation', link: '/guide/installation' },
      { text: 'Configuration', link: '/guide/configuration' },
      { text: 'Database compatibility', link: '/guide/database-compatibility' },
    ],
  },
  {
    text: 'Core Concepts',
    collapsed: false,
    items: [
      { text: 'Architecture', link: '/guide/architecture' },
      { text: 'Storage Panels', link: '/guide/storage-panels' },
      { text: 'Drive — storages, folders & nodes', link: '/guide/drive' },
      { text: 'Storage & Blobs', link: '/guide/storage-and-blobs' },
      { text: 'Document Handlers', link: '/guide/document-handlers' },
    ],
  },
  {
    text: 'Working with Content',
    collapsed: false,
    items: [
      { text: 'Document Nodes', link: '/guide/documents' },
      { text: 'Uploads', link: '/guide/uploads' },
      { text: 'Trash & Restore', link: '/guide/trash' },
      { text: 'Search & Trash', link: '/guide/search-trash' },
      { text: 'Sharing', link: '/guide/sharing' },
      { text: 'Comments', link: '/guide/comments' },
      { text: 'Export', link: '/guide/export' },
    ],
  },
  {
    text: 'Authorization',
    collapsed: false,
    items: [
      { text: 'Authorization', link: '/guide/authorization' },
      { text: 'Permission Drivers', link: '/guide/permission-drivers' },
      { text: 'Grants', link: '/guide/grants' },
      { text: 'Abilities', link: '/guide/abilities' },
      { text: 'Multi-tenancy', link: '/guide/multi-tenancy' },
    ],
  },
  {
    text: 'Realtime & UI',
    collapsed: false,
    items: [
      { text: 'Realtime Collaboration', link: '/guide/collaboration' },
      { text: 'Hocuspocus Server (Docker)', link: '/guide/hocuspocus-server' },
      { text: 'Vue package (@vaulter/vue)', link: '/guide/vue' },
      { text: 'Filament admin plugin', link: '/guide/filament' },
    ],
  },
  {
    text: 'Going Further',
    collapsed: false,
    items: [
      { text: 'Recipes', link: '/guide/recipes' },
      { text: 'Troubleshooting', link: '/guide/troubleshooting' },
      { text: 'Error Reference', link: '/guide/errors' },
    ],
  },
]

const referenceSidebar = [
  {
    text: 'API Reference',
    collapsed: false,
    items: [
      { text: 'Facade & Manager', link: '/reference/facade' },
      { text: 'REST API', link: '/reference/rest-api' },
      { text: 'Configuration keys', link: '/reference/configuration' },
      { text: 'Contracts', link: '/reference/contracts' },
      { text: 'Events', link: '/reference/events' },
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
    ],
  },
]

// ---------------------------------------------------------------------------
// Russian sidebar — lists ONLY translated pages; everything else falls back
// to the English tree (cross-locale links are kept absolute, e.g. /guide/*).
// ---------------------------------------------------------------------------

const ruGuideSidebar = [
  {
    text: 'Начало работы',
    collapsed: false,
    items: [
      { text: 'Что такое Vaulter?', link: '/ru/guide/introduction' },
      { text: 'Быстрый старт', link: '/ru/guide/quick-start' },
      { text: 'Установка', link: '/ru/guide/installation' },
      { text: 'Конфигурация', link: '/ru/guide/configuration' },
    ],
  },
  {
    text: 'Концепции',
    collapsed: false,
    items: [
      { text: 'Storage Panels', link: '/ru/concepts/storage-panels' },
      { text: 'Авторизация', link: '/ru/concepts/authorization' },
    ],
  },
  {
    text: 'Возможности',
    collapsed: false,
    items: [
      { text: 'Совместное редактирование', link: '/ru/guide/collaboration' },
    ],
  },
]

// ---------------------------------------------------------------------------

export default defineConfig({
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
      { text: 'Guide', link: '/guide/introduction', activeMatch: '/guide/' },
      { text: 'Reference', link: '/reference/facade', activeMatch: '/reference/' },
      { text: 'ADRs', link: '/adr/', activeMatch: '/adr/' },
      { text: 'Changelog', link: `${repo}/blob/main/CHANGELOG.md` },
      { text: 'GitHub', link: repo },
    ],
    sidebar: {
      '/guide/': guideSidebar,
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

  locales: {
    root: { label: 'English', lang: 'en' },
    ru: {
      label: 'Русский',
      lang: 'ru',
      link: '/ru/',
      themeConfig: {
        nav: [
          { text: 'Руководство', link: '/ru/guide/introduction', activeMatch: '/ru/guide/' },
          { text: 'Концепции', link: '/ru/concepts/storage-panels', activeMatch: '/ru/concepts/' },
          { text: 'Reference (EN)', link: '/reference/facade' },
          { text: 'ADRs (EN)', link: '/adr/' },
          { text: 'GitHub', link: repo },
        ],
        sidebar: {
          '/ru/guide/': ruGuideSidebar,
          '/ru/concepts/': ruGuideSidebar,
        },
      },
    },
  },
})

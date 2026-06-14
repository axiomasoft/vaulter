import { defineConfig } from 'vitepress'

const repo = 'https://github.com/axioma-studio/doc-vault'

const guideSidebar = [
  {
    text: 'Introduction',
    items: [
      { text: 'What is DocVault?', link: '/guide/introduction' },
      { text: 'Architecture', link: '/guide/architecture' },
      { text: 'Installation', link: '/guide/installation' },
      { text: 'Quick Start', link: '/guide/quick-start' },
    ],
  },
  {
    text: 'Usage',
    items: [
      { text: 'Configuration', link: '/guide/configuration' },
      { text: 'Search & Trash', link: '/guide/search-trash' },
      { text: 'Embedding', link: '/guide/embedding' },
      { text: 'Permission Resolver', link: '/guide/permission-resolver' },
      { text: 'Abilities', link: '/guide/abilities' },
      { text: 'Vue Components', link: '/guide/vue-components' },
      { text: 'Filament Admin', link: '/guide/filament' },
      { text: 'Collaboration', link: '/guide/collaboration' },
      { text: 'Comments', link: '/guide/comments' },
      { text: 'Share Links', link: '/guide/sharing' },
      { text: 'Templates', link: '/guide/templates' },
      { text: 'Export', link: '/guide/export' },
      { text: 'Error Reference', link: '/guide/errors' },
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
    ],
  },
]

const ruGuideSidebar = [
  {
    text: 'Введение',
    items: [{ text: 'Что такое DocVault?', link: '/ru/guide/introduction' }],
  },
]

export default defineConfig({
  title: 'DocVault',
  description:
    'Embeddable document management for Laravel — file manager, TipTap editor, version history and real-time collaboration.',
  cleanUrls: true,
  lastUpdated: true,

  themeConfig: {
    siteTitle: 'DocVault',
    nav: [
      { text: 'Guide', link: '/guide/introduction' },
      { text: 'ADRs', link: '/adr/' },
      { text: 'Changelog', link: 'https://github.com/axioma-studio/doc-vault/blob/main/CHANGELOG.md' },
      { text: 'GitHub', link: repo },
    ],
    sidebar: {
      '/guide/': guideSidebar,
      '/adr/': adrSidebar,
    },
    socialLinks: [{ icon: 'github', link: repo }],
    search: { provider: 'local' },
    editLink: {
      pattern: `${repo}/edit/main/docs/:path`,
      text: 'Edit this page on GitHub',
    },
    footer: {
      message: 'Released under the MIT License.',
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
          { text: 'Руководство', link: '/ru/guide/introduction' },
          { text: 'GitHub', link: repo },
        ],
        sidebar: {
          '/ru/guide/': ruGuideSidebar,
        },
      },
    },
  },
})

import { defineConfig } from 'vitepress'

const repo = 'https://github.com/axioma-studio/doc-vault'

const guideSidebar = [
  {
    text: 'Introduction',
    items: [
      { text: 'What is DocVault?', link: '/guide/introduction' },
      { text: 'Installation', link: '/guide/installation' },
      { text: 'Quick Start', link: '/guide/quick-start' },
    ],
  },
  {
    text: 'Usage',
    items: [
      { text: 'Configuration', link: '/guide/configuration' },
      { text: 'Permission Resolver', link: '/guide/permission-resolver' },
      { text: 'Vue Components', link: '/guide/vue-components' },
      { text: 'Collaboration', link: '/guide/collaboration' },
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
      { text: 'Changelog', link: 'https://github.com/axioma-studio/doc-vault/blob/main/CHANGELOG.md' },
      { text: 'GitHub', link: repo },
    ],
    sidebar: {
      '/guide/': guideSidebar,
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

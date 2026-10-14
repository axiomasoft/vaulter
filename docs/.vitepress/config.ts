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
      { text: 'Storage Panels', link: '/concepts/drives-and-profiles' },
      { text: 'Drive — storages, folders & nodes', link: '/concepts/nodes-and-revisions' },
      { text: 'Storage & Blobs', link: '/concepts/storage-and-blobs' },
      { text: 'Document Handlers', link: '/extending/file-handlers' },
      { text: 'Document Types', link: '/extending/document-types' },
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
      { text: 'Sharing', link: '/guides/sharing' },
      { text: 'Change Journal', link: '/concepts/events' },
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
      { text: 'Realtime Collaboration', link: '/guides/collaboration' },
      { text: 'Hocuspocus Server (Docker)', link: '/guides/hub' },
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
      { text: 'Operations', link: '/guides/operations' },
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
    ],
  },
]

// ---------------------------------------------------------------------------
// Russian sidebar — lists ONLY translated pages; everything else falls back
// to the English tree (cross-locale links are kept absolute, e.g. /guides/*).
// ---------------------------------------------------------------------------

const ruGuideSidebar = [
  {
    text: 'Начало работы',
    collapsed: false,
    items: [
      { text: 'Что такое Vaulter?', link: '/ru/getting-started/introduction' },
      { text: 'Быстрый старт', link: '/ru/getting-started/quick-start' },
      { text: 'Установка', link: '/ru/getting-started/installation' },
      { text: 'Конфигурация', link: '/ru/guides/configuration' },
      { text: 'Совместимость (API и БД)', link: '/ru/reference/compatibility' },
    ],
  },
  {
    text: 'Основные концепции',
    collapsed: false,
    items: [
      { text: 'Архитектура', link: '/ru/concepts/architecture' },
      { text: 'Глоссарий', link: '/ru/concepts/glossary' },
      { text: 'Модули', link: '/ru/concepts/modules' },
      { text: 'Storage Panels', link: '/ru/concepts/drives-and-profiles' },
      { text: 'Drive — хранилища, папки и узлы', link: '/ru/concepts/nodes-and-revisions' },
      { text: 'Хранилище и блобы', link: '/ru/concepts/storage-and-blobs' },
      { text: 'Обработчики документов', link: '/ru/extending/file-handlers' },
      { text: 'Типы документов', link: '/ru/extending/document-types' },
      { text: 'Экспортеры', link: '/ru/extending/exporters' },
      { text: 'Инспекторы загрузок', link: '/ru/extending/upload-inspectors' },
      { text: 'Профили', link: '/ru/extending/profiles' },
      { text: 'Написание движка/обработчика', link: '/ru/extending/viewers' },
    ],
  },
  {
    text: 'Работа с контентом',
    collapsed: false,
    items: [
      { text: 'Документы-узлы', link: '/ru/guides/documents' },
      { text: 'Загрузки', link: '/ru/guides/uploads' },
      { text: 'Корзина и поиск', link: '/ru/guides/trash' },
      { text: 'Публичные ссылки', link: '/ru/guides/sharing' },
      { text: 'Журнал изменений', link: '/ru/concepts/events' },
      { text: 'Комментарии', link: '/ru/guides/comments' },
      { text: 'Экспорт', link: '/ru/guides/export' },
    ],
  },
  {
    text: 'Авторизация',
    collapsed: false,
    items: [
      { text: 'Авторизация', link: '/ru/concepts/access-model' },
      { text: 'Драйверы прав', link: '/ru/extending/permission-drivers' },
      { text: 'Гранты', link: '/ru/guides/grants' },
      { text: 'Права (abilities)', link: '/ru/guides/abilities' },
      { text: 'Мультитенантность', link: '/ru/concepts/tenancy' },
    ],
  },
  {
    text: 'Realtime и интерфейс',
    collapsed: false,
    items: [
      { text: 'Совместное редактирование', link: '/ru/guides/collaboration' },
      { text: 'Hocuspocus-сервер (Docker)', link: '/ru/guides/hub' },
      { text: 'Vue-пакет (@vaulter/vue)', link: '/ru/guides/vue' },
      { text: 'Плагин Filament', link: '/ru/guides/filament' },
    ],
  },
  {
    text: 'Дальше',
    collapsed: false,
    items: [
      { text: 'Рецепты', link: '/ru/guides/recipes' },
      { text: 'Диагностика', link: '/ru/guides/troubleshooting' },
      { text: 'Справочник ошибок', link: '/ru/guides/errors' },
      { text: 'Эксплуатация', link: '/ru/guides/operations' },
      { text: 'Модель угроз', link: '/ru/security/threat-model' },
      { text: 'Чек-лист усиления защиты', link: '/ru/security/hardening-checklist' },
    ],
  },
]

const ruReferenceSidebar = [
  {
    text: 'Справочник API',
    collapsed: false,
    items: [
      { text: 'PHP API (фасад и контракты)', link: '/ru/reference/php-api' },
      { text: 'REST API', link: '/ru/reference/rest-api' },
      { text: 'Ключи конфигурации', link: '/ru/reference/config' },
      { text: 'События', link: '/ru/reference/events' },
      { text: 'Коды ошибок', link: '/ru/reference/errors' },
      { text: 'Конкурентность', link: '/ru/reference/concurrency' },
      { text: 'Artisan-команды', link: '/ru/reference/commands' },
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

  locales: {
    root: { label: 'English', lang: 'en' },
    ru: {
      label: 'Русский',
      lang: 'ru',
      link: '/ru/',
      themeConfig: {
        nav: [
          { text: 'Руководство', link: '/ru/getting-started/introduction', activeMatch: '^/ru/(getting-started|concepts|guides|extending|security)/' },
          { text: 'Справочник', link: '/ru/reference/php-api', activeMatch: '/ru/reference/' },
          { text: 'ADRs (EN)', link: '/adr/' },
          { text: 'GitHub', link: repo },
        ],
        sidebar: {
          '/ru/getting-started/': ruGuideSidebar,
          '/ru/concepts/': ruGuideSidebar,
          '/ru/guides/': ruGuideSidebar,
          '/ru/extending/': ruGuideSidebar,
          '/ru/security/': ruGuideSidebar,
          '/ru/reference/': ruReferenceSidebar,
        },
      },
    },
  },
})

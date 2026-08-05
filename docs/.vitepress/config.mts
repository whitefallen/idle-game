import { defineConfig } from 'vitepress';

/**
 * Renders docs/ as-is: no content lives under .vitepress, so this file is the
 * only thing added to what was already the authoritative design record. The
 * reading order and ADR table below mirror README.md's own tables rather than
 * inventing a second structure for the same documents.
 */
export default defineConfig({
  title: 'Emberwatch',
  description: 'Design documentation for Emberwatch, a browser MMORPG with idle mechanics.',

  // Served from https://whitefallen.github.io/idle-game/ as a project page.
  base: '/idle-game/',

  // README.md is the file GitHub renders at the repo root; rewriting it to
  // index.md makes it the site's homepage too, instead of maintaining two
  // copies of the same landing page.
  rewrites: {
    'README.md': 'index.md',
  },

  cleanUrls: true,

  themeConfig: {
    nav: [
      { text: 'Guide', link: '/game-bible' },
      { text: 'ADRs', link: '/adr/' },
    ],

    sidebar: [
      {
        text: 'Reading order',
        items: [
          { text: '1. Game bible', link: '/game-bible' },
          { text: '2. Progression', link: '/progression' },
          { text: '3. Combat', link: '/combat' },
          { text: '4. Items', link: '/items' },
          { text: '5. Content', link: '/content' },
          { text: '6. Idle layer', link: '/idle' },
          { text: '7. Economy', link: '/economy' },
          { text: '8. Architecture', link: '/architecture' },
          { text: '9. Data model', link: '/data-model' },
          { text: '10. API', link: '/api' },
          { text: '11. Account & authentication', link: '/account' },
          { text: '12. Frontend architecture', link: '/frontend-architecture' },
        ],
      },
      {
        text: 'Architecture Decision Records',
        link: '/adr/',
        items: [
          { text: '0001 — Monorepo layout', link: '/adr/0001-monorepo-layout' },
          { text: '0002 — Deterministic combat math', link: '/adr/0002-integer-deterministic-combat' },
          { text: '0003 — Passive accrual idle model', link: '/adr/0003-passive-accrual-idle-model' },
          { text: '0004 — Transactional outbox', link: '/adr/0004-transactional-outbox' },
          { text: '0005 — UUIDv7 primary keys', link: '/adr/0005-uuidv7-primary-keys' },
          { text: '0006 — Denormalised power score', link: '/adr/0006-denormalised-power-score' },
        ],
      },
    ],

    search: {
      provider: 'local',
    },

    socialLinks: [{ icon: 'github', link: 'https://github.com/whitefallen/idle-game' }],

    editLink: {
      pattern: 'https://github.com/whitefallen/idle-game/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    lastUpdated: {
      text: 'Last updated',
    },
  },
});

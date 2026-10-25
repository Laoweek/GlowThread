import { describe, expect, it } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { revealInvisible } from '@/lib/names'
import { rankCandidates } from '@/comments/mentions'
import UserName from '@/shared/UserName.vue'
import CommentItem from '@/comments/components/CommentItem.vue'
import PopularStrip from '@/comments/components/PopularStrip.vue'
import Composer from '@/comments/components/Composer.vue'
import MentionList from '@/comments/components/MentionList.vue'
import { createStore } from '@/comments/store'
import { OVERLAYS, STORE } from '@/comments/context'
import type { Author, Comment, ListResponse, PageConfig } from '@/shared/types'

// Lookalike user names: MediaWiki without AntiSpoof accepts "Moderator" plus a soft hyphen.
const SPOOF = 'Moder\u00ADator'

describe('characters that show nothing', () => {
  // Tests run from client/: the server's patterns are read from its source and run here.
  const php = readFileSync(path.resolve('..', 'includes', 'Text', 'TextProcessor.php'), 'utf8')
  const pattern = (name: string) => {
    const source = new RegExp(name + ' = ([\\s\\S]*?);').exec(php)![1]
    const body = [...source.matchAll(/'([^']*)'/g)].map((m) => m[1]).join('')
    return new RegExp(body.slice(1, body.lastIndexOf('/')).replace(/\\x\{/g, '\\' + 'u{'), 'gu')
  }
  const strip = pattern('STRIP_PATTERN')
  const tags = pattern('TAG_PATTERN')
  const ignorable = pattern('IGNORABLE_PATTERN')
  const server = (s: string) => s.replace(strip, '').replace(tags, '$1')
  const each = (fn: (c: string) => void) => {
    for (let cp = 0; cp <= 0xE0FFF; cp++) if (cp < 0xD800 || cp > 0xDFFF) fn(String.fromCodePoint(cp))
  }

  it('keep a lookalike name out of the mention suggestions: the reader could not tell whom they pick', () => {
    const lookalikes = ['Moderator\u2060', 'Moderator\uFEFF', '\u2067Moderator\u2069', 'Mod\u061Cerator', 'Moderator\u{E0041}', 'Moderator\u3164']
    expect(rankCandidates('mod', ['Moderator', SPOOF], lookalikes, null)).toEqual(['Moderator'])
    // Joiners are part of some scripts: names with them are offered.
    expect(rankCandidates('مح', ['مح\u200Cمد'], [], null)).toEqual(['مح\u200Cمد'])
  })

  it('are written out for moderators, with every other character that shows nothing', () => {
    // What the server ignores as invisible, except joiners and variation selectors, which
    // some scripts and emoji need.
    const kept = (cp: number) => cp === 0x200C || cp === 0x200D || (cp >= 0xFE00 && cp <= 0xFE0F) || (cp >= 0xE0100 && cp <= 0xE01EF)
    const missed: string[] = []
    each((c) => {
      const cp = c.codePointAt(0) as number
      const hidden = server(c) !== c || (c.replace(ignorable, '') === '' && !kept(cp))
      if (hidden && revealInvisible(c) === c) missed.push(cp.toString(16))
    })
    expect(missed).toEqual([])
  })
})

describe('names for moderators', () => {
  it('write out every character that shows nothing', () => {
    expect(revealInvisible(SPOOF)).toBe('Moder[U+00AD]ator')
    expect(revealInvisible('\u2067Moderator\u2069')).toBe('[U+2067]Moderator[U+2069]')
    // Fillers and blanks that are letters or symbols, and tag characters.
    expect(revealInvisible('Moderator\u3164')).toBe('Moderator[U+3164]')
    expect(revealInvisible('\u2800Mod\u034F')).toBe('[U+2800]Mod[U+034F]')
    expect(revealInvisible('Mod\u{E0041}')).toBe('Mod[U+E0041]')
  })

  it('leave ordinary names alone', () => {
    for (const name of ['Moderator', '张三', 'O\'Brien', 'Anna Maria', 'مح\u200Cمد', '👩\u200D💻', '❤️']) {
      expect(revealInvisible(name)).toBe(name)
    }
  })

  it('show in the name, while the link keeps the real name', () => {
    const author: Author = { name: SPOOF, id: 3, anon: false, temp: false, hidden: false }
    const render = (reveal: boolean) => {
      const el = document.createElement('div')
      createApp({ render: () => h(UserName, { author, reveal }) }).mount(el)
      return el.querySelector('a') as HTMLAnchorElement
    }
    const shown = render(true)
    expect(shown.textContent).toBe('Moder[U+00AD]ator')
    // In full on hover, where a long name is cut.
    expect(shown.getAttribute('title')).toBe('Moder[U+00AD]ator')
    expect(decodeURIComponent(shown.getAttribute('href')!)).toBe('/wiki/User:' + SPOOF)
    expect(render(false).textContent).toBe(SPOOF)
    expect(render(false).hasAttribute('title')).toBe(false)
  })

  it('show in the comment section to moderators only: names, reply-to lines, top comments, reply box and mentions', async () => {
    const config: PageConfig = {
      pageId: 42, enabled: true, state: 'open', defaultSort: 'hot',
      listUrls: { hot: '/hot', new: '/new' }, restBase: '/rest.php/glowthread/v1',
      features: { legacyCompat: true },
    }
    const spoofer: Author = { name: SPOOF, id: 3, anon: false, temp: false, hidden: false }
    const viewer = { own: false, liked: false, reported: false, canReply: true, canLike: true, canReport: true, canDelete: false }
    const base = { pageId: 42, threadId: null, status: 'visible' as const, created: '2026-10-08T00:00:00Z', edited: null, segments: [{ type: 'text' as const, text: 'hi' }], likes: 0, pinned: false, viewer, replyCount: 0, totalReplies: 0 }
    // A reply by Bob that answers the lookalike's other reply (so it says "Reply to @…").
    const reply: Comment = { ...base, id: 2, seq: 2, parentId: 1, threadId: 1, depth: 1, author: { ...spoofer, name: 'Bob', id: 4 }, replyTo: { id: 5, seq: 5, visible: true, author: spoofer }, replies: [] }
    const c: Comment = { ...base, id: 1, seq: 1, parentId: null, depth: 0, author: spoofer, replyTo: null, replies: [reply], replyCount: 1, totalReplies: 1 }
    const render = async (canModerate: boolean) => {
      const data: ListResponse = {
        page: { id: 42, title: 'Sourdough starter', state: 'open' }, sort: 'hot',
        viewer: { loggedIn: true, canPost: true, postBlockedReason: null, canReact: true, canReport: true, canModerate, canErase: false, canConfigure: false },
        config: { maxLength: 1000, maxMentions: 5, reportReasons: ['spam'], pageSize: 20, replyPreview: 3 },
        count: 1, pinned: [], popular: [c], comments: [c], next: null,
      }
      const store = createStore({ config, get: (async (url: string) => (url.indexOf('/moderation/') >= 0 ? { counts: { held: 0, reported: 0 } } : JSON.parse(JSON.stringify(data)))) as any, post: (async () => ({})) as any })
      await store.load()
      const el = document.createElement('div')
      document.body.appendChild(el)
      const picked: string[] = []
      const app = createApp({
        render: () => [
          h(CommentItem, { comment: store.find(1)!, portal: document.body }),
          h(PopularStrip, { comments: store.data.value!.popular }),
          h(Composer, { replyTo: store.find(1)!, portal: document.body }),
          h(MentionList, { anchor: null, items: [SPOOF], active: 0, keyed: false, listId: 'gt-test-mentions', portal: el, label: 'People', reveal: canModerate, onPick: (n: string) => picked.push(n) }),
        ],
      })
      app.provide(STORE, store)
      app.provide(OVERLAYS, { phone: ref(false), openReport() {} })
      app.mount(el)
      await nextTick()
      const option = el.querySelector('[role="option"]') as HTMLElement
      option.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }))
      const out = {
        name: (el.querySelector('.ext-glowthread-name') as HTMLElement).textContent,
        replyTo: (el.querySelector('[data-comment="2"] p') as HTMLElement).textContent!.trim(),
        popular: (el.querySelector('.ext-glowthread-popular button') as HTMLElement).textContent!,
        popularLabel: (el.querySelector('.ext-glowthread-popular button') as HTMLElement).getAttribute('aria-label'),
        placeholder: (el.querySelector('textarea') as HTMLTextAreaElement).getAttribute('placeholder'),
        mention: (option.querySelector('span[dir="auto"]') as HTMLElement).textContent,
        picked,
      }
      app.unmount()
      el.remove()
      return out
    }
    const shown = 'Moder[U+00AD]ator'
    const mod = await render(true)
    expect(mod.name).toBe(shown)
    expect(mod.replyTo).toBe('Reply to @' + shown)
    expect(mod.popular).toContain(shown)
    expect(mod.popularLabel).toContain(shown)
    expect(mod.placeholder).toBe('Reply to @' + shown)
    expect(mod.mention).toBe(shown)
    // Picking still mentions the real name.
    expect(mod.picked).toEqual([SPOOF])
    const reader = await render(false)
    expect([reader.name, reader.replyTo, reader.placeholder, reader.mention]).toEqual([SPOOF, 'Reply to @' + SPOOF, 'Reply to @' + SPOOF, SPOOF])
    expect(reader.popular).toContain(SPOOF)
    expect(reader.popular).not.toContain('[U+')
    expect(reader.popularLabel).not.toContain('[U+')
  })
})

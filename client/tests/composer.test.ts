import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'
import Composer from '@/comments/components/Composer.vue'
import { createStore } from '@/comments/store'
import { OVERLAYS, STORE } from '@/comments/context'
import { draftKey } from '@/comments/drafts'
import type { Comment, ListResponse, PageConfig } from '@/shared/types'

// The composer's mention units (MENTIONS-SPEC.md): picked names are units sent as <@ID>, typed
// text stays plain, Backspace at the end removes one, an edit inside makes it plain text.
const config: PageConfig = {
  pageId: 42, enabled: true, state: 'open', defaultSort: 'hot',
  listUrls: { hot: '/hot', new: '/new' }, restBase: '/rest.php/glowthread/v1',
  features: { legacyCompat: true },
}
const KEY = draftKey('', 'Alice', 'page:42')

function comment(id: number, name: string): Comment {
  return {
    id, seq: id, pageId: 42, parentId: null, threadId: null, depth: 0, status: 'visible',
    author: { name, id: id + 100, anon: false, temp: false, hidden: false },
    created: '2026-10-08T00:00:00Z', edited: null, segments: [{ type: 'text', text: 'hi' }],
    likes: 0, pinned: false, replyTo: null,
    viewer: { own: false, liked: false, reported: false, canReply: true, canLike: true, canReport: true, canDelete: false },
    replies: [], replyCount: 0, totalReplies: 0,
  }
}

// Bob is user 104 and 张三 user 105: comment authors, so the composer suggests them with their IDs.
const data: ListResponse = {
  page: { id: 42, title: 'Sourdough starter', state: 'open' }, sort: 'hot',
  viewer: { loggedIn: true, canPost: true, postBlockedReason: null, canReact: true, canReport: true, canModerate: false, canErase: false, canConfigure: false },
  config: { maxLength: 1000, maxMentions: 5, reportReasons: ['spam'], pageSize: 20, replyPreview: 3 },
  count: 2, pinned: [], popular: [], comments: [comment(4, 'Bob'), comment(5, '张三')], next: null,
}

// Core Action API reads: list=users by ID answers from `users`.
let users: Record<number, string> = {}
const apiGet = vi.fn(async (p: Record<string, string>) => {
  if (p.list === 'users') return { query: { users: String(p.ususerids).split('|').map((id) => (users[+id] ? { userid: +id, name: users[+id] } : { userid: +id, missing: true })) } }
  return { query: { allusers: String(p.auprefix).startsWith('Ca') ? [{ userid: 77, name: 'Carol' }] : [] } }
})

const mounted: Array<{ unmount(): void }> = []
beforeEach(() => {
  users = {}
  apiGet.mockClear()
  ;(globalThis as any).mw.Api = class { get(p: Record<string, string>) { return apiGet(p) } }
})
afterEach(() => {
  mounted.splice(0).forEach((app) => app.unmount())
  document.body.innerHTML = ''
  window.localStorage.clear()
  delete (document as any).execCommand
  vi.useRealTimers()
})

async function render() {
  const post = vi.fn(async (_m: string, p: Record<string, unknown>) => ({ result: 'success', id: 9, seq: 9, status: 'visible', comment: { ...comment(9, 'Alice'), segments: [{ type: 'text', text: String(p.text) }] } }))
  const store = createStore({ config, get: (async () => JSON.parse(JSON.stringify(data))) as any, post: post as any })
  await store.load()
  const el = document.createElement('div')
  document.body.appendChild(el)
  const app = createApp({ render: () => h(Composer, { portal: document.body }) })
  app.provide(STORE, store)
  app.provide(OVERLAYS, { phone: ref(false), openReport() {} })
  app.mount(el)
  mounted.push(app)
  await nextTick()
  const ta = el.querySelector('textarea') as HTMLTextAreaElement
  ta.focus()
  return { el, ta, post }
}

/** What the browser does when the reader types, pastes or composes: the new value, then input. */
async function input(ta: HTMLTextAreaElement, value: string, caret = value.length, inputType = 'insertText') {
  ta.value = value
  ta.setSelectionRange(caret, caret)
  ta.dispatchEvent(new InputEvent('input', { inputType, bubbles: true }))
  await nextTick()
}

function key(ta: HTMLTextAreaElement, k: string, init: KeyboardEventInit = {}): KeyboardEvent {
  const e = new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...init })
  ta.dispatchEvent(e)
  return e
}

/** Types "hi @bo" and picks Bob with Enter. */
async function pickBob(ta: HTMLTextAreaElement) {
  await input(ta, 'hi @bo')
  key(ta, 'Enter')
  await nextTick()
}

async function send(ta: HTMLTextAreaElement, post: ReturnType<typeof vi.fn>): Promise<string> {
  key(ta, 'Enter', { ctrlKey: true })
  await vi.waitFor(() => expect(post).toHaveBeenCalled())
  return String(post.mock.calls[0][1].text)
}

const marks = (el: HTMLElement) => [...el.querySelectorAll('mark')].map((m) => m.textContent)
const live = (el: HTMLElement) => (el.querySelector('[aria-live="polite"].gt-sr-only') as HTMLElement).textContent

describe('picking a mention', () => {
  it('makes a highlighted unit that is sent as a token with the user ID', async () => {
    const { el, ta, post } = await render()
    await pickBob(ta)
    expect(ta.value).toBe('hi @Bob ')
    expect(marks(el)).toEqual(['@Bob'])
    expect(live(el)).toBe('Mentioned Bob')
    // The mirror behind the textarea is decoration only.
    expect(el.querySelector('mark')!.closest('[aria-hidden="true"]')).not.toBeNull()
    expect(await send(ta, post)).toBe('hi <@104> ')
  })

  it('leaves typed names plain text: never a mention', async () => {
    const { el, ta, post } = await render()
    await input(ta, 'hi @Bob, <@104 is not a token> ')
    expect(marks(el)).toEqual([])
    expect(await send(ta, post)).toBe('hi @Bob, <@104 is not a token> ')
  })

  it('picks other users found by the search, with the ID it returns', async () => {
    const { el, ta, post } = await render()
    await input(ta, '@ca')
    await vi.waitFor(() => expect(document.querySelector('[role="option"]')).not.toBeNull(), { timeout: 2000 })
    key(ta, 'Enter')
    await nextTick()
    expect(marks(el)).toEqual(['@Carol'])
    expect(await send(ta, post)).toBe('<@77> ')
  })

  it('describes the mentions in the box to screen readers while they are there', async () => {
    const { el, ta } = await render()
    await pickBob(ta)
    const ids = ta.getAttribute('aria-describedby')!.split(' ')
    expect(ids.map((id) => document.getElementById(id)!.textContent).join('|')).toContain('Mentions: Bob')
  })

  it('keeps mentions when the @ button adds text before them', async () => {
    const { el, ta, post } = await render()
    await pickBob(ta)
    ta.setSelectionRange(0, 0)
    ;(el.querySelector('button[aria-label="Mention someone"]') as HTMLButtonElement).click()
    await nextTick()
    expect(ta.value).toBe('@hi @Bob ')
    expect(marks(el)).toEqual(['@Bob'])
    // The new @ opened the suggestions: Esc closes them, then Ctrl + Enter sends.
    key(ta, 'Escape')
    expect(await send(ta, post)).toBe('@hi <@104> ')
  })

  it('does not suggest a mention\'s own name again right after it', async () => {
    const { el, ta } = await render()
    await pickBob(ta)
    ta.setSelectionRange(7, 7)
    ta.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await nextTick()
    expect(document.querySelector('[role="listbox"]')).toBeNull()
    expect(marks(el)).toEqual(['@Bob'])
  })
})

describe('changing a mention', () => {
  it('removes the whole unit with one Backspace at its end', async () => {
    const { el, ta, post } = await render()
    await pickBob(ta)
    ta.setSelectionRange(7, 7)
    expect(key(ta, 'Backspace').defaultPrevented).toBe(true)
    await nextTick()
    expect(ta.value).toBe('hi  ')
    expect(marks(el)).toEqual([])
    expect(live(el)).toBe('Bob is no longer mentioned')
    expect(await send(ta, post)).toBe('hi  ')
  })

  it('leaves Backspace alone elsewhere, with a selection or a modifier', async () => {
    const { ta } = await render()
    await pickBob(ta)
    ta.setSelectionRange(8, 8)
    expect(key(ta, 'Backspace').defaultPrevented).toBe(false)
    ta.setSelectionRange(6, 7)
    expect(key(ta, 'Backspace').defaultPrevented).toBe(false)
    ta.setSelectionRange(7, 7)
    expect(key(ta, 'Backspace', { ctrlKey: true }).defaultPrevented).toBe(false)
  })

  it('turns into plain text when edited inside', async () => {
    const { el, ta, post } = await render()
    await pickBob(ta)
    await input(ta, 'hi @Bxob ', 6)
    expect(marks(el)).toEqual([])
    expect(live(el)).toBe('Bob is no longer mentioned')
    expect(await send(ta, post)).toBe('hi @Bxob ')
  })

  it('moves with pasted text, and becomes plain text when a paste covers part of it', async () => {
    const { el, ta, post } = await render()
    await pickBob(ta)
    await input(ta, 'Well, hi @Bob ', 6, 'insertFromPaste')
    expect(marks(el)).toEqual(['@Bob'])
    // Pasted "@Carol" is typed text, not a mention.
    await input(ta, 'Well, hi @Bob @Carol', 20, 'insertFromPaste')
    expect(await send(ta, post)).toBe('Well, hi <@104> @Carol')
  })

  it('goes whole when an Android keyboard deletes its last character (Backspace with key code 229)', async () => {
    const { el, ta, post } = await render()
    await pickBob(ta)
    ta.setSelectionRange(7, 7)
    expect(key(ta, 'Backspace', { keyCode: 229 } as KeyboardEventInit).defaultPrevented).toBe(false)
    await input(ta, 'hi @Bo ', 6, 'deleteContentBackward')
    expect(ta.value).toBe('hi  ')
    expect(marks(el)).toEqual([])
    expect(live(el)).toBe('Bob is no longer mentioned')
    expect(await send(ta, post)).toBe('hi  ')
  })

  it('becomes plain text when a cut or paste takes part of it', async () => {
    const { el, ta } = await render()
    await pickBob(ta)
    await input(ta, 'hi @ ', 4, 'deleteByCut')
    expect(marks(el)).toEqual([])
  })
})

describe('Undo', () => {
  // A browser's editing: execCommand changes the value and fires input, so Undo can take it back.
  function browserEditing() {
    ;(document as any).execCommand = (cmd: string, _ui: boolean, value: string) => {
      const ta = document.activeElement as HTMLTextAreaElement
      const s = ta.selectionStart
      const add = cmd === 'delete' ? '' : value
      ta.value = ta.value.slice(0, s) + add + ta.value.slice(ta.selectionEnd)
      ta.setSelectionRange(s + add.length, s + add.length)
      ta.dispatchEvent(new InputEvent('input', { inputType: cmd === 'delete' ? 'deleteContentBackward' : 'insertText', bubbles: true }))
      return true
    }
  }

  it('brings a removed mention back with its text, and Redo takes it again', async () => {
    browserEditing()
    const { el, ta, post } = await render()
    await pickBob(ta)
    expect(marks(el)).toEqual(['@Bob'])
    ta.setSelectionRange(7, 7)
    key(ta, 'Backspace')
    await nextTick()
    expect(ta.value).toBe('hi  ')
    await input(ta, 'hi @Bob ', 7, 'historyUndo')
    expect(marks(el)).toEqual(['@Bob'])
    expect(live(el)).toBe('Mentioned Bob')
    await input(ta, 'hi  ', 3, 'historyRedo')
    expect(marks(el)).toEqual([])
    await input(ta, 'hi @Bob ', 7, 'historyUndo')
    expect(await send(ta, post)).toBe('hi <@104> ')
  })

  it('gives the same text typed by hand earlier its own units: none', async () => {
    browserEditing()
    const { el, ta, post } = await render()
    await input(ta, 'hi @Bob ')
    await input(ta, '', 0, 'deleteContentBackward')
    await pickBob(ta)
    expect(marks(el)).toEqual(['@Bob'])
    await input(ta, 'hi @bo', 6, 'historyUndo')
    await input(ta, '', 0, 'historyUndo')
    await input(ta, 'hi @Bob ', 8, 'historyUndo')
    expect(marks(el)).toEqual([])
    expect(await send(ta, post)).toBe('hi @Bob ')
  })
})

describe('Chinese input', () => {
  /** An input method: compositionstart, updates of the composed text, compositionend. */
  async function compose(ta: HTMLTextAreaElement, steps: Array<[string, number]>) {
    ta.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }))
    for (const [value, caret] of steps) await input(ta, value, caret, 'insertCompositionText')
    ta.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true }))
    await nextTick()
  }

  it('suggests and picks a Chinese name typed with an input method', async () => {
    const { el, ta, post } = await render()
    await compose(ta, [['谢谢@z', 4], ['谢谢@zhang', 8], ['谢谢@张', 4]])
    // Enter while composing belongs to the input method.
    expect(key(ta, 'Enter', { isComposing: true }).defaultPrevented).toBe(false)
    expect(ta.value).toBe('谢谢@张')
    key(ta, 'Enter')
    await nextTick()
    expect(ta.value).toBe('谢谢@张三 ')
    expect(marks(el)).toEqual(['@张三'])
    expect(await send(ta, post)).toBe('谢谢<@105> ')
  })

  it('keeps a mention when composing after it, and never changes the composed text', async () => {
    const { el, ta, post } = await render()
    await pickBob(ta)
    await compose(ta, [['hi @Bob n', 9], ['hi @Bob ni', 10], ['hi @Bob 你好', 10]])
    expect(ta.value).toBe('hi @Bob 你好')
    expect(marks(el)).toEqual(['@Bob'])
    // Backspace inside a composition (keyCode 229) is the input method's, even at a mention's end.
    ta.setSelectionRange(7, 7)
    expect(key(ta, 'Backspace', { keyCode: 229 } as KeyboardEventInit).defaultPrevented).toBe(false)
    expect(await send(ta, post)).toBe('hi <@104> 你好')
  })

  it('sends and saves what the box shows while a composition is still open', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const { el, ta, post } = await render()
    await pickBob(ta)
    ta.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }))
    await input(ta, '你hi @Bob ', 1, 'insertCompositionText')
    // The textarea is the input method's: the composer never wrote to it.
    expect(ta.value).toBe('你hi @Bob ')
    expect(el.querySelector('mark')!.parentElement!.textContent).toContain('你hi @Bob')
    vi.advanceTimersByTime(500)
    expect(JSON.parse(window.localStorage.getItem(KEY)!).text).toBe('你hi <@104> ')
    vi.useRealTimers()
    expect(await send(ta, post)).toBe('你hi <@104> ')
  })

  it('makes a mention plain text when composing inside it', async () => {
    const { el, ta } = await render()
    await pickBob(ta)
    await compose(ta, [['hi @Bo哦b ', 6]])
    expect(marks(el)).toEqual([])
  })
})

describe('drafts', () => {
  it('keep mentions as tokens with their names, and bring them back as units with current names', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const first = await render()
    await pickBob(first.ta)
    vi.advanceTimersByTime(500)
    expect(JSON.parse(window.localStorage.getItem(KEY)!)).toMatchObject({ text: 'hi <@104> ', names: { 104: 'Bob' } })
    vi.useRealTimers()
    mounted.splice(0).forEach((app) => app.unmount())
    document.body.innerHTML = ''

    // Bob was renamed to Robert since.
    users = { 104: 'Robert' }
    const again = await render()
    await vi.waitFor(() => expect(again.ta.value).toBe('hi @Robert '))
    expect(apiGet).toHaveBeenCalledWith(expect.objectContaining({ list: 'users', ususerids: '104' }))
    expect(marks(again.el)).toEqual(['@Robert'])
    expect(await send(again.ta, again.post)).toBe('hi <@104> ')
  })

  it('show a user who is gone by now as plain text under the old name', async () => {
    window.localStorage.setItem(KEY, JSON.stringify({ text: 'hi <@104> and <@105>', names: { 104: 'Bob', 105: '张三' }, at: Date.now() }))
    users = { 105: '张三' }
    const { el, ta, post } = await render()
    await vi.waitFor(() => expect(marks(el)).toEqual(['@张三']))
    expect(ta.value).toBe('hi @Bob and @张三')
    expect(await send(ta, post)).toBe('hi @Bob and <@105>')
  })

  it('leave the box alone when the reader typed before the current names came', async () => {
    window.localStorage.setItem(KEY, JSON.stringify({ text: 'hi <@104>', names: { 104: 'Bob' }, at: Date.now() }))
    let answer: (v: unknown) => void = () => undefined
    apiGet.mockImplementationOnce(() => new Promise((resolve) => { answer = resolve }))
    const { el, ta, post } = await render()
    expect(ta.value).toBe('hi @Bob')
    await input(ta, 'hi @Bob, thanks')
    answer({ query: { users: [{ userid: 104, name: 'Robert' }] } })
    await new Promise((r) => setTimeout(r, 0))
    expect(ta.value).toBe('hi @Bob, thanks')
    expect(marks(el)).toEqual(['@Bob'])
    expect(await send(ta, post)).toBe('hi <@104>, thanks')
  })

  it('still read drafts saved before mentions were units', async () => {
    window.localStorage.setItem(KEY, JSON.stringify({ text: 'hi @Bob', at: Date.now() }))
    const { el, ta } = await render()
    expect(ta.value).toBe('hi @Bob')
    expect(marks(el)).toEqual([])
    expect(apiGet).not.toHaveBeenCalled()
  })
})

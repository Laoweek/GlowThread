import { afterEach, describe, expect, it } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'
import type { Component } from 'vue'
import { ChevronDown, ChevronUp, Ellipsis, Heart, MessageSquare, User } from '@lucide/vue'
import CommentItem from '@/comments/components/CommentItem.vue'
import LikeButton from '@/comments/components/LikeButton.vue'
import { Avatar } from '@/ui/avatar'
import { createStore } from '@/comments/store'
import { OVERLAYS, STORE } from '@/comments/context'
import { comment, config, list } from './comments-fixtures'

// The icons every comment draws are Lucide's, written out as SVG in the templates (a component
// per icon and comment costs more). They must stay exactly what Lucide renders: after a Lucide
// upgrade this fails and shows the new markup to paste (Expected), with the same attributes.
const apps: Array<{ unmount(): void }> = []
afterEach(() => {
  apps.splice(0).forEach((a) => a.unmount())
  document.body.innerHTML = ''
})

function render(component: Component, props: Record<string, unknown> = {}, store = createStore({ config, get: async () => list(3) as any, post: async () => ({}) as any })) {
  const el = document.createElement('div')
  document.body.appendChild(el)
  const app = createApp({ render: () => h(component, props) })
  app.provide(STORE, store)
  app.provide(OVERLAYS, { phone: ref(false), openReport() {} })
  app.mount(el)
  apps.push(app)
  return el
}

/** What Lucide's own component renders with these attributes. */
function lucide(icon: Component, attrs: Record<string, unknown>): string {
  return render(icon, attrs).innerHTML
}

const svg = (el: Element, name: string) => {
  const found = el.querySelector('svg.lucide-' + name)
  if (!found) throw new Error('no ' + name + ' icon')
  return found.outerHTML
}

describe('icons written out as SVG', () => {
  it('match Lucide in a comment: ⋯, Reply, like, and the replies links', async () => {
    const store = createStore({ config, get: async () => list(3) as any, post: async () => ({}) as any })
    await store.load()
    const c = store.find(2)!
    // One reply loaded of three: "Show 2 more replies"; opened by the reader: "Hide replies".
    c.totalReplies = c.replyCount = 3
    store.state.folds[2] = false
    const el = render(CommentItem, { comment: c, portal: document.body }, store)
    await nextTick()
    expect(svg(el, 'ellipsis')).toBe(lucide(Ellipsis, { class: 'gt:size-icon', 'aria-hidden': 'true' }))
    expect(svg(el, 'message-square')).toBe(lucide(MessageSquare, { class: 'gt:size-icon', 'aria-hidden': 'true' }))
    expect(svg(el, 'chevron-down')).toBe(lucide(ChevronDown, { class: 'gt:size-icon-sm', 'aria-hidden': 'true' }))
    expect(svg(el, 'chevron-up')).toBe(lucide(ChevronUp, { class: 'gt:size-icon-sm', 'aria-hidden': 'true' }))
    expect(svg(el, 'heart')).toBe(lucide(Heart, { class: ['gt:size-icon', ['', '']], 'aria-hidden': 'true' }))
  })

  it('match Lucide for the like button in each state', async () => {
    const store = createStore({ config, get: async () => list(3) as any, post: async () => ({}) as any })
    await store.load()
    const c = store.find(1)!
    const el = render(LikeButton, { comment: c }, store)
    c.viewer.liked = true
    await nextTick()
    // Liked, with the pop animation running.
    expect(svg(el, 'heart')).toBe(lucide(Heart, { class: ['gt:size-icon', ['gt:fill-accent gt:text-accent', 'gt-anim-like']], 'aria-hidden': 'true' }))
    el.querySelector('svg')!.dispatchEvent(new Event('animationend'))
    await nextTick()
    expect(svg(el, 'heart')).toBe(lucide(Heart, { class: ['gt:size-icon', ['gt:fill-accent gt:text-accent', '']], 'aria-hidden': 'true' }))
    // Own comments show the count only.
    const own = render(LikeButton, { comment: comment(9, { viewer: { ...c.viewer, own: true } }) }, store)
    expect(svg(own, 'heart')).toBe(lucide(Heart, { class: 'gt:size-icon', 'aria-hidden': 'true' }))
  })

  it('match Lucide for the person icon of avatars', () => {
    expect(svg(render(Avatar, { generic: true }), 'user')).toBe(lucide(User, { class: 'gt:size-icon-lg' }))
    expect(svg(render(Avatar, { name: null, size: 'sm' }), 'user')).toBe(lucide(User, { class: 'gt:size-icon-sm' }))
  })
})

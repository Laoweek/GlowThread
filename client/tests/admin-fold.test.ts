import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import type { App } from 'vue'
import CommentBody from '@/shared/CommentBody.vue'

// Queue items in the hub use content-visibility: auto, so the text of most loaded items has no
// layout. Its fold (Show more) is measured once the browser renders it, not one item at a
// time when a page of items arrives; text near the screen is still measured at once.

let report = () => {}
class FakeResizeObserver {
  constructor(cb: () => void) {
    report = () => cb()
  }

  observe() {}
  disconnect() {}
}

const apps: App[] = []
beforeEach(() => {
  vi.stubGlobal('ResizeObserver', FakeResizeObserver)
  vi.stubGlobal('requestAnimationFrame', (fn: FrameRequestCallback) => {
    fn(0)
    return 0
  })
  // Inside an element marked data-skip, content-visibility skips the text (the browser's answer).
  vi.spyOn(Element.prototype, 'checkVisibility').mockImplementation(function (this: Element, options?: CheckVisibilityOptions) {
    const box = this.parentElement && this.parentElement.closest('[data-skip]')
    return !(options && options.contentVisibilityAuto && box)
  })
})
afterEach(() => {
  apps.splice(0).forEach((app) => app.unmount())
  document.body.innerHTML = ''
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

/** A long comment (taller than its 6 lines) in an item at `top` px, or in the comment section (null). */
function mountLong(top: number | null): HTMLElement {
  const item = document.createElement('article')
  item.setAttribute('data-comment', '1')
  if (top !== null) {
    item.setAttribute('data-skip', '')
    item.getBoundingClientRect = () => ({ top, bottom: top + 160 }) as DOMRect
  }
  const el = document.createElement('div')
  item.appendChild(el)
  document.body.appendChild(item)
  const app = createApp({ render: () => h(CommentBody, { segments: [{ type: 'text', text: 'long text' }] }) })
  app.mount(el)
  apps.push(app)
  const p = el.querySelector('p') as HTMLElement
  Object.defineProperty(p, 'scrollHeight', { get: () => 300 })
  Object.defineProperty(p, 'clientHeight', { get: () => 144 })
  return el
}

const showMore = (el: HTMLElement) => !!el.querySelector('button')

describe('the fold of a comment in a queue item', () => {
  it('waits for the browser to render text far from the screen', async () => {
    const el = mountLong(5000)
    await nextTick()
    await nextTick()
    expect(showMore(el)).toBe(false)
    // The ResizeObserver reports the text once it is rendered.
    report()
    await nextTick()
    expect(showMore(el)).toBe(true)
  })

  it('is measured at once near the screen, so Show more is in the first paint', async () => {
    const el = mountLong(600)
    await nextTick()
    await nextTick()
    expect(showMore(el)).toBe(true)
  })

  it('is measured at once where nothing skips the text (the comment section)', async () => {
    const el = mountLong(null)
    await nextTick()
    await nextTick()
    expect(showMore(el)).toBe(true)
  })
})

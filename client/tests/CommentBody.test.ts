import { describe, expect, it } from 'vitest'
import { createApp, h } from 'vue'
import CommentBody from '@/shared/CommentBody.vue'
import type { Segment } from '@/lib/segments'

function render(segments: Segment[]): HTMLElement {
  const el = document.createElement('div')
  createApp({ render: () => h(CommentBody, { segments }) }).mount(el)
  return el
}

describe('CommentBody', () => {
  it('renders markup in comment text as text, never as HTML', () => {
    const el = render([{ type: 'text', text: '<img src=x onerror=alert(1)><script>alert(2)</script>' }])
    expect(el.querySelector('img')).toBeNull()
    expect(el.querySelector('script')).toBeNull()
    expect(el.textContent).toContain('<img src=x onerror=alert(1)>')
  })

  it('opens links in a new tab with nofollow ugc noopener', () => {
    const el = render([{ type: 'link', text: 'https://example.com/x', href: 'https://example.com/x' }])
    const a = el.querySelector('a') as HTMLAnchorElement
    expect(a.getAttribute('href')).toBe('https://example.com/x')
    expect(a.getAttribute('rel')).toBe('nofollow ugc noopener')
    expect(a.getAttribute('target')).toBe('_blank')
  })

  it('links mentions of existing users to their user page only', () => {
    const el = render([
      { type: 'mention', text: '@Bob', user: 'Bob', exists: true },
      { type: 'text', text: ' ' },
      { type: 'mention', text: '@Ghost', user: 'Ghost', exists: false },
    ])
    const links = el.querySelectorAll('a')
    expect(links.length).toBe(1)
    expect(links[0].getAttribute('href')).toBe('/wiki/User:Bob')
    expect(el.textContent).toContain('@Ghost')
  })

  it('shows mentions by user ID as the current name, and the placeholder as plain text', () => {
    // The server's segments for <@4> (Bob) and for a missing or hidden user (docs/api.md).
    const el = render([
      { type: 'mention', text: '@Bob', user: 'Bob', id: 4, exists: true } as Segment,
      { type: 'text', text: ' and ' },
      { type: 'mention', text: '@…', exists: false } as unknown as Segment,
    ])
    const links = el.querySelectorAll('a')
    expect(links.length).toBe(1)
    expect(links[0].getAttribute('href')).toBe('/wiki/User:Bob')
    expect(el.textContent).toBe('@Bob and @…')
  })

  it('keeps line breaks and lets the browser pick the direction', () => {
    const el = render([{ type: 'text', text: 'a\nb' }])
    const p = el.querySelector('p') as HTMLElement
    expect(p.getAttribute('dir')).toBe('auto')
    expect(p.textContent).toBe('a\nb')
  })
})

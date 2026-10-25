import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, defineComponent, h, ref, renderSlot } from 'vue'
import type { Component } from 'vue'
import { Primitive } from 'reka-ui'
import { Button, buttonVariants } from '@/ui/button'
import { cn } from '@/lib/cn'

// Button renders its element itself unless asChild (no Reka Primitive in between): the DOM must
// stay what the Primitive-based Button rendered, written here as a render function.
const PrimitiveButton = defineComponent({
  props: { variant: String, size: String, as: { type: String, default: 'button' }, asChild: Boolean, class: String },
  setup(props, { slots }) {
    return () => h(Primitive, {
      as: props.as,
      asChild: props.asChild,
      type: props.as === 'button' && !props.asChild ? 'button' : undefined,
      'data-glowthread-variant': props.variant || 'secondary',
      class: cn(buttonVariants({ variant: props.variant as any, size: props.size as any }), props.class),
    }, { default: () => [renderSlot(slots, 'default')] })
  },
})

const apps: Array<{ unmount(): void }> = []
afterEach(() => {
  apps.splice(0).forEach((a) => a.unmount())
  document.body.innerHTML = ''
})

function render(component: Component, props: Record<string, unknown>, text = 'Reply') {
  const el = document.createElement('div')
  document.body.appendChild(el)
  const r = ref<{ $el: HTMLElement } | null>(null)
  const app = createApp({ render: () => h(component, { ...props, ref: r }, { default: () => [h('span', 'icon'), text] }) })
  app.mount(el)
  apps.push(app)
  return { el, r }
}

const CASES: Array<Record<string, unknown>> = [
  {},
  { variant: 'ghost', size: 'inline', class: 'ext-glowthread-reply', 'aria-expanded': 'false' },
  { variant: 'danger', size: 'sm', disabled: true },
  { variant: 'secondary', class: 'ext-glowthread-load-more gt:min-w-40', disabled: false, 'aria-busy': 'false' },
  { as: 'a', href: '/wiki/Special:UserLogin', variant: 'primary', size: 'sm' },
  { as: 'a', size: 'sm', variant: 'ghost', href: '/wiki/Special:GlowThreadLink/5', target: '_blank', rel: 'noopener', 'aria-label': 'Open' },
]

describe('Button', () => {
  for (const props of CASES) {
    it('renders as the Primitive-based Button did: ' + JSON.stringify(props), () => {
      expect(render(Button, props).el.innerHTML).toBe(render(PrimitiveButton, props).el.innerHTML)
    })
  }

  it('passes listeners through and exposes its element', () => {
    const onClick = vi.fn()
    const { el, r } = render(Button, { onClick })
    const button = el.querySelector('button') as HTMLButtonElement
    button.click()
    expect(onClick).toHaveBeenCalledTimes(1)
    expect(r.value!.$el).toBe(button)
  })
})

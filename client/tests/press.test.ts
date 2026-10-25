import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { afterPress } from '@/lib/press'

// A field that collapses on blur must not move the page while a click elsewhere is in
// progress, or the click lands beside its target.
describe('afterPress', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => vi.useRealTimers())

  const fire = (type: string) => window.dispatchEvent(new Event(type))

  it('runs at once when no press is in progress (keyboard)', () => {
    vi.advanceTimersByTime(5000)
    const fn = vi.fn()
    afterPress(fn)
    expect(fn).toHaveBeenCalledTimes(1)
  })

  it('waits for the click of a mouse press, then runs after its handlers', () => {
    fire('pointerdown')
    const fn = vi.fn()
    afterPress(fn)
    fire('pointerup')
    expect(fn).not.toHaveBeenCalled()
    fire('click')
    expect(fn).not.toHaveBeenCalled()
    vi.advanceTimersByTime(0)
    expect(fn).toHaveBeenCalledTimes(1)
    // Only once, also when the fallback timer would have fired.
    vi.advanceTimersByTime(2000)
    expect(fn).toHaveBeenCalledTimes(1)
  })

  it('waits for the click after a tap that already ended (touch blurs after pointerup)', () => {
    fire('pointerdown')
    fire('pointerup')
    const fn = vi.fn()
    afterPress(fn)
    expect(fn).not.toHaveBeenCalled()
    fire('click')
    vi.advanceTimersByTime(0)
    expect(fn).toHaveBeenCalledTimes(1)
  })

  it('gives up waiting shortly after a press that ends without a click', () => {
    fire('pointerdown')
    const fn = vi.fn()
    afterPress(fn)
    fire('pointerup')
    vi.advanceTimersByTime(300)
    expect(fn).not.toHaveBeenCalled()
    vi.advanceTimersByTime(200)
    expect(fn).toHaveBeenCalledTimes(1)
  })
})

import { describe, expect, it, vi } from 'vitest'
import { moderate } from '@/comments/actions'
import { toasts } from '@/ui/toast'
import type { Store } from '@/comments/store'

// A store double: moderate() answers like the server, with `old` and (optionally) `logId`.
function fakeStore(serverOld: Record<number, string>, { logIds = true } = {}) {
  const calls: Array<[number[], string, number[] | undefined]> = []
  const store = {
    // The page's own copy says every comment is visible: undo must not trust it.
    find: (id: number) => ({ id, status: 'visible' }),
    moderate: vi.fn(async (ids: number[], action: string, logids?: number[]) => {
      calls.push([ids, action, logids])
      return {
        result: 'success', do: action,
        results: ids.map((id) => ({ id, result: 'success', status: action === 'delete' ? 'deleted' : 'visible', old: serverOld[id], code: null, logId: logIds ? 1000 + id : null })),
      }
    }),
  }
  return { store: store as unknown as Store, calls }
}

describe('moderate with undo', () => {
  it('undoes exactly with the log IDs the server returned', async () => {
    toasts.splice(0)
    const { store, calls } = fakeStore({ 1: 'visible', 2: 'held' })
    await moderate(store, [1, 2], 'delete')
    const undo = toasts[toasts.length - 1].action!
    expect(undo.label).toBe('Undo')
    undo.onClick()
    await Promise.resolve()
    expect(calls[1]).toEqual([[1, 2], 'undo', [1001, 1002]])
  })

  it('without log IDs restores only what the server says was visible, never the page\'s copy', async () => {
    toasts.splice(0)
    // Held by reports after the page loaded: the page still shows it as visible.
    const { store, calls } = fakeStore({ 1: 'held', 2: 'visible' }, { logIds: false })
    await moderate(store, [1, 2], 'delete')
    toasts[toasts.length - 1].action!.onClick()
    await Promise.resolve()
    expect(calls[1]).toEqual([[2], 'restore', undefined])
    toasts.splice(0)
    const held = fakeStore({ 3: 'held' }, { logIds: false })
    await moderate(held.store, [3], 'delete')
    expect(toasts[toasts.length - 1].action).toBeNull()
  })

  it('sends every selected comment, 50 at a time', async () => {
    toasts.splice(0)
    const ids = Array.from({ length: 120 }, (_, i) => i + 1)
    const { store, calls } = fakeStore({})
    await moderate(store, ids, 'approve')
    expect(calls.map((c) => c[0].length)).toEqual([50, 50, 20])
    expect(toasts[toasts.length - 1].message).toContain('120')
  })

  it('undoes more than 50 comments 50 at a time, each ID with its log ID', async () => {
    toasts.splice(0)
    const ids = Array.from({ length: 60 }, (_, i) => i + 1)
    const { store, calls } = fakeStore(Object.fromEntries(ids.map((id) => [id, 'visible'])))
    await moderate(store, ids, 'delete')
    toasts[toasts.length - 1].action!.onClick()
    await vi.waitFor(() => expect(calls.length).toBe(4))
    expect(calls.slice(2).map((c) => [c[0].length, c[1], c[2]!.length])).toEqual([[50, 'undo', 50], [10, 'undo', 10]])
    expect(calls[3][0][0]).toBe(51)
    expect(calls[3][2]![0]).toBe(1051)
    await vi.waitFor(() => expect(toasts[toasts.length - 1].message).toContain('60'))
  })

  it('keeps the undo of what was done when a later request fails', async () => {
    toasts.splice(0)
    const ids = Array.from({ length: 70 }, (_, i) => i + 1)
    const { store, calls } = fakeStore({})
    const ok = (store.moderate as ReturnType<typeof vi.fn>).getMockImplementation()!
    ;(store.moderate as ReturnType<typeof vi.fn>).mockImplementation(async (chunk: number[], action: string, logids?: number[]) => {
      if (chunk[0] === 51) throw new Error('network')
      return ok(chunk, action, logids)
    })
    expect(await moderate(store, ids, 'approve')).toBe(true)
    const last = toasts[toasts.length - 1]
    expect(last.message).toContain('50')
    expect(last.message).toContain('20')
    last.action!.onClick()
    await vi.waitFor(() => expect(calls.length).toBe(2))
    expect(calls[1][0]).toEqual(ids.slice(0, 50))
  })
})

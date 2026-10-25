import { describe, expect, it } from 'vitest'
import { actionTargets, commandFor, inRecord, isImeEnter, isTypingTarget, mayAct, SHORTCUTS } from '@/admin/keyboard'

function el(html: string, select = '[data-t]'): HTMLElement {
  const box = document.createElement('div')
  box.innerHTML = html
  document.body.appendChild(box)
  return box.querySelector(select) as HTMLElement
}

describe('the queue keyboard model', () => {
  const body = document.body

  it('maps the keys to commands', () => {
    const map: Record<string, string> = { j: 'next', k: 'prev', x: 'toggle', a: 'pass', d: 'delete', r: 'restore', '/': 'search', '?': 'help', Escape: 'escape' }
    for (const key of Object.keys(map)) expect(commandFor({ key, target: body })).toBe(map[key])
    expect(commandFor({ key: 'J', target: body })).toBe('next') // Caps Lock
    expect(commandFor({ key: 'q', target: body })).toBeNull()
    expect(commandFor({ key: 'ArrowDown', target: body })).toBeNull()
  })

  it('never takes a key with a modifier or during IME composition', () => {
    expect(commandFor({ key: 'a', ctrlKey: true, target: body })).toBeNull()
    expect(commandFor({ key: 'r', metaKey: true, target: body })).toBeNull() // reload
    expect(commandFor({ key: 'd', altKey: true, target: body })).toBeNull()
    expect(commandFor({ key: 'j', isComposing: true, target: body })).toBeNull()
  })

  it('never takes a key while typing', () => {
    for (const html of ['<input data-t>', '<input data-t type="search">', '<textarea data-t></textarea>', '<select data-t></select>', '<div data-t contenteditable="true"></div>']) {
      const target = el(html)
      expect(isTypingTarget(target)).toBe(true)
      expect(commandFor({ key: 'd', target })).toBeNull()
      expect(commandFor({ key: 'Escape', target })).toBeNull()
    }
    // Checkboxes and buttons are not fields: the keys work there.
    expect(commandFor({ key: 'x', target: el('<input data-t type="checkbox">') })).toBe('toggle')
    expect(commandFor({ key: 'a', target: el('<button data-t>Approve</button>') })).toBe('pass')
  })

  it('leaves dialogs, sheets and menus their own keys', () => {
    expect(commandFor({ key: 'd', target: el('<div role="dialog"><button data-t>x</button></div>') })).toBeNull()
    expect(commandFor({ key: 'Escape', target: el('<div role="alertdialog"><button data-t>x</button></div>') })).toBeNull()
    expect(commandFor({ key: 'j', target: el('<div role="menu"><div data-t role="menuitem">x</div></div>') })).toBeNull()
  })

  it('repeats moving when a key is held, never acting', () => {
    expect(commandFor({ key: 'j', repeat: true, target: body })).toBe('next')
    expect(commandFor({ key: 'k', repeat: true, target: body })).toBe('prev')
    for (const key of ['a', 'd', 'r', 'x', '/', '?']) expect(commandFor({ key, repeat: true, target: body })).toBeNull()
  })

  it('tells an IME commit Enter from a real Enter', () => {
    expect(isImeEnter(new KeyboardEvent('keydown', { key: 'Enter', isComposing: true }))).toBe(true)
    // Safari: the commit Enter comes after compositionend with keyCode 229.
    expect(isImeEnter(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 229 } as KeyboardEventInit))).toBe(true)
    expect(isImeEnter(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13 } as KeyboardEventInit))).toBe(false)
  })

  it('lists every command in the shortcut sheet', () => {
    expect(SHORTCUTS.map((s) => s.command).sort()).toEqual(['delete', 'escape', 'help', 'next', 'pass', 'prev', 'restore', 'search', 'toggle'])
  })
})

describe('the record panel', () => {
  // The moderator opened one of the author's recent comments there ("View") and came back.
  const panel = () => el('<aside data-record><div class="ext-glowthread-record"><a data-t href="#">View</a></div></aside>', 'aside')
  const item = () => el('<article data-t id="gt-q-5" tabindex="0"></article>')

  it('takes no x, a, d or r while focus is in it', () => {
    const p = panel()
    expect(mayAct(p.querySelector('a'), false)).toBe(false)
    // The scroll area itself (Chrome lets the keyboard focus it).
    expect(mayAct(p, false)).toBe(false)
    expect(inRecord(p.querySelector('a'))).toBe(true)
  })

  it('takes none after a click in it left focus on the page, until the moderator is back in the queue', () => {
    // Safari does not focus links on click: focus stays on the page.
    const clicked = inRecord(panel().querySelector('a'))
    expect(mayAct(document.body, clicked)).toBe(false)
    expect(mayAct(null, clicked)).toBe(false)
    expect(mayAct(item(), clicked)).toBe(true)
    // A click anywhere else.
    expect(mayAct(document.body, inRecord(item()))).toBe(true)
  })
})

describe('what a, d and r act on', () => {
  it('acts on the item that holds focus, not a remembered one', () => {
    // j left item 3 current; the moderator Tabbed into item 2 (or came back from its context link).
    expect(actionTargets(2, [], 3)).toEqual([2])
    expect(actionTargets(null, [], 3)).toEqual([3])
  })

  it('acts on the selection from a selected item or from outside the items', () => {
    expect(actionTargets(5, [5, 6], 3)).toEqual([5, 6])
    expect(actionTargets(null, [5, 6], 3)).toEqual([5, 6])
    // Focus in an item outside the selection: that item only.
    expect(actionTargets(7, [5, 6], 3)).toEqual([7])
    expect(actionTargets(null, [], null)).toEqual([])
  })
})

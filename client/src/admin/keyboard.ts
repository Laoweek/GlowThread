// The queue's keyboard model. One listener on the document turns key presses into
// commands; it never takes a key while the moderator types (fields, IME composition),
// while a modifier is held, or while a dialog or menu has focus. Pure, unit-tested.

export type Command = 'next' | 'prev' | 'toggle' | 'pass' | 'delete' | 'restore' | 'search' | 'help' | 'escape'

/** The keys, in the order the shortcut sheet lists them. */
export const SHORTCUTS: Array<{ keys: string[], command: Command }> = [
  { keys: ['j'], command: 'next' },
  { keys: ['k'], command: 'prev' },
  { keys: ['x'], command: 'toggle' },
  { keys: ['a'], command: 'pass' },
  { keys: ['d'], command: 'delete' },
  { keys: ['r'], command: 'restore' },
  { keys: ['/'], command: 'search' },
  { keys: ['?'], command: 'help' },
  { keys: ['Esc'], command: 'escape' },
]

const LETTERS: Record<string, Command> = { j: 'next', k: 'prev', x: 'toggle', a: 'pass', d: 'delete', r: 'restore' }

interface KeyLike {
  key: string
  ctrlKey?: boolean
  metaKey?: boolean
  altKey?: boolean
  isComposing?: boolean
  repeat?: boolean
  target?: EventTarget | null
}

/** Fields where a key press is text, not a command. */
export function isTypingTarget(target: EventTarget | null | undefined): boolean {
  const el = target as HTMLElement | null
  if (!el || !el.tagName) return false
  const tag = el.tagName.toLowerCase()
  if (tag === 'textarea' || tag === 'select') return true
  if (tag === 'input') {
    const type = ((el as HTMLInputElement).type || 'text').toLowerCase()
    return ['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'color', 'file'].indexOf(type) < 0
  }
  return !!el.isContentEditable || el.getAttribute('contenteditable') === 'true'
}

/** Inside a dialog, sheet or menu the keys belong to it (Reka handles Esc there). */
function inOverlay(target: EventTarget | null | undefined): boolean {
  const el = target as HTMLElement | null
  return !!(el && el.closest && el.closest('[role="dialog"],[role="alertdialog"],[role="menu"],[role="listbox"]'))
}

export function commandFor(e: KeyLike): Command | null {
  if (e.ctrlKey || e.metaKey || e.altKey || e.isComposing) return null
  if (isTypingTarget(e.target) || inOverlay(e.target)) return null
  if (e.key === 'Escape' || e.key === 'Esc') return 'escape'
  // A held key repeats: fine for moving, never for acting (each action moves focus on, so a
  // held d would delete item after item).
  if (e.repeat) return /^[jkJK]$/.test(e.key) ? LETTERS[e.key.toLowerCase()] : null
  if (e.key === '/') return 'search'
  if (e.key === '?') return 'help'
  // Caps Lock gives upper-case letters; the commands are the same.
  return LETTERS[(e.key || '').toLowerCase()] || null
}

/** Inside the record panel beside the queue (or its sheet on narrow screens). */
export function inRecord(target: EventTarget | null | undefined): boolean {
  const el = target as HTMLElement | null
  return !!(el && el.closest && el.closest('.ext-glowthread-record, [data-record]'))
}

/**
 * Whether x, a, d and r may act. The record panel lists other comments of the author, and a key
 * there could be meant for one of them, not for the queue item it would hit: they do nothing
 * while focus is in the panel, or on the page after a click in it (Safari does not focus links
 * and buttons on click, and a click on text leaves focus on the page).
 */
export function mayAct(active: Element | null, clickedInRecord: boolean): boolean {
  if (inRecord(active)) return false
  return !(clickedInRecord && (!active || active.tagName === 'BODY'))
}

/**
 * Enter that only commits an IME candidate (Chinese input) must not submit. Safari sends that
 * Enter after compositionend, with keyCode 229.
 */
export function isImeEnter(e: KeyboardEvent): boolean {
  return e.isComposing || e.keyCode === 229
}

/**
 * What a, d and r act on, so a key never hits a comment the moderator is not looking at: the
 * item the moderator is in (keyboard focus inside it, after Tab or a click on View in
 * context), or the selection when that item is part of it or focus is in no item, else the
 * remembered current item.
 */
export function actionTargets(inItem: number | null, selected: number[], current: number | null): number[] {
  if (inItem !== null && selected.indexOf(inItem) < 0) return [inItem]
  if (selected.length) return selected.slice()
  if (inItem !== null) return [inItem]
  return current !== null ? [current] : []
}

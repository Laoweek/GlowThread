import { reactive } from 'vue'

export interface ToastAction {
  label: string
  onClick: () => void
}

export interface ToastItem {
  id: number
  message: string
  tone: 'neutral' | 'error'
  action: ToastAction | null
  duration: number
}

let nextId = 1

/** Visible toasts, newest last. Shared by every Toaster in the page. */
export const toasts = reactive<ToastItem[]>([])

/**
 * Shows a short message at the bottom of the screen. Errors are announced
 * assertively, everything else politely. An action (Undo) keeps the toast up longer.
 */
export function toast(message: string, options: { tone?: 'neutral' | 'error', action?: ToastAction, duration?: number } = {}): number {
  const id = nextId++
  toasts.push({
    id,
    message,
    tone: options.tone || 'neutral',
    action: options.action || null,
    duration: options.duration || (options.action ? 6000 : options.tone === 'error' ? 6000 : 4000),
  })
  while (toasts.length > 3) toasts.shift()
  return id
}

export function dismissToast(id: number) {
  const i = toasts.findIndex((x) => x.id === id)
  if (i >= 0) toasts.splice(i, 1)
}

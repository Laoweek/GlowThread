import { inject } from 'vue'
import type { InjectionKey, Ref } from 'vue'
import type { Store } from './store'
import type { Comment } from '@/shared/types'

export const STORE: InjectionKey<Store> = Symbol('glowthread-store')

export function useStore(): Store {
  return inject(STORE) as Store
}

/** App-level overlays a comment row can open (one instance each, anchored to the row). */
export interface Overlays {
  openReport(comment: Comment, anchor: HTMLElement): void
  phone: Ref<boolean>
}
export const OVERLAYS: InjectionKey<Overlays> = Symbol('glowthread-overlays')

export function useOverlays(): Overlays {
  return inject(OVERLAYS) as Overlays
}

/** Moves focus to the section heading, looked up inside the app. */
export const FOCUS_HEADING: InjectionKey<() => void> = Symbol('glowthread-focus-heading')

/** Shows a comment's conversation in place (list or linked conversation), scrolls to it and highlights it. */
export const REVEAL: InjectionKey<(id: number) => void> = Symbol('glowthread-reveal')

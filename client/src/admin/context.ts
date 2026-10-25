import { inject } from 'vue'
import type { InjectionKey, Ref } from 'vue'
import type { AdminConfig } from '@/shared/types'
import type { Queue } from './queue'
import type { History, Subject } from './history'
import type { Settings } from './settings'
import type { Route } from './router'
import type { ViewerInfo } from './wikiApi'
import type { Kind } from './moderation'
import type { QueueItem } from '@/shared/types'

/** Everything the hub's components share, provided once by App.vue. */
export interface Hub {
  config: AdminConfig
  route: Ref<Route>
  queue: Queue
  history: History
  settings: Settings
  viewer: Ref<ViewerInfo | null>
  /** The moderator is blocked: the server refuses every action. */
  blocked: Ref<boolean>
  phone: Ref<boolean>
  /** The record has its own column beside the queue. Otherwise it opens as a sheet. */
  wide: Ref<boolean>
  navigate(route: Route, options?: { replace?: boolean }): void
  /** The address of a route, for real links (open in a new tab works). */
  href(route: Route): string
  /** The queue as the moderator last left it (the rail's Queue returns there). */
  lastQueue: Ref<Route>
  /** Runs an action on comments with its toast, undo and focus handling. */
  act(kind: Kind, ids: number[], options?: { reason?: string }): Promise<void>
  showRecord(subject: Subject, item?: QueueItem | null): void
  /** Whether the record beside the queue follows the focused item's author or its page. */
  recordMode: Ref<'user' | 'page'>
  confirmErase(item: QueueItem): void
  openShortcuts(): void
  focusItem(id?: number | null): void
}

export const HUB: InjectionKey<Hub> = Symbol('glowthread-hub')

export function useHub(): Hub {
  return inject(HUB) as Hub
}

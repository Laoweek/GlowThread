import { defineComponent, h, ref, shallowRef } from 'vue'
import type { Component, Ref, ShallowRef } from 'vue'
import { toast } from '@/ui/toast'
import { t } from '@/lib/i18n'

/**
 * Parts of the comment section in modules of their own, loaded when needed. The build turns
 * each import() into mw.loader.using( 'ext.glowThread.app.<name>' )
 * (scripts/vite-plugin-mw-chunks.mjs):
 * - extras.ts, ext.glowThread.app.extras: what readers who can post, report or moderate use.
 *   The loader requests it with the app for logged-in readers.
 * - menu.ts, ext.glowThread.app.menu: the open ⋯ menu. It loads when a reader points at,
 *   focuses or opens a menu, or touches the section on a phone. Extras need it too.
 * Import their parts from here only: a static import would pull them into the first module.
 */
interface Lazy<M> {
  module: ShallowRef<M | null>
  failed: Ref<boolean>
  /** Starts the load once. Never rejects: a failure sets `failed` and is reported once. */
  load(): Promise<void>
}

let told = 0
/** Says that a part could not load, once for parts that ask at the same moment. */
export function tellFailed() {
  if (Date.now() - told > 3000) toast(t('error-network'))
  told = Date.now()
}

function lazy<M>(load: () => Promise<M>): Lazy<M> {
  const module = shallowRef<M | null>(null)
  const failed = ref(false)
  let pending: Promise<void> | null = null
  return {
    module,
    failed,
    load: () => pending || (pending = load().then((m) => {
      module.value = m
    }, (e) => {
      // The network, or a module from another build (the chunk refuses to run then).
      failed.value = true
      console.error('[GlowThread]', e)
      tellFailed()
    })),
  }
}

export const extras = lazy(() => import('./extras'))
export const menu = lazy(() => import('./menu'))

/**
 * A component of such a module: drawn in the same render as its parent once the module is
 * there, nothing until then (it starts the load). Props, events and slots pass through. When
 * the module failed, a part the reader asked for (`tell`) says so again.
 */
function part<M, K extends keyof M>(from: Lazy<M>, name: K, tell = false): M[K] {
  return defineComponent({
    name: 'GlowThreadLazy',
    inheritAttrs: false,
    setup(_, { attrs, slots }) {
      if (from.failed.value && tell) tellFailed()
      else if (!from.module.value) from.load()
      return () => (from.module.value ? h(from.module.value[name] as Component, attrs, slots) : null)
    },
  }) as M[K]
}

export const Composer = part(extras, 'Composer', true)
export const ReportDialog = part(extras, 'ReportDialog')
export const ModTools = part(extras, 'ModTools')
export const ModQueue = part(extras, 'ModQueue')
export const ModNotes = part(extras, 'ModNotes')
export const BulkBar = part(extras, 'BulkBar')
export const Checkbox = part(extras, 'Checkbox')
export const CommentMenuContent = part(menu, 'CommentMenuContent')

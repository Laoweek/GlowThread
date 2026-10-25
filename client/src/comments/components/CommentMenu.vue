<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref } from 'vue'
import type { Comment } from '@/shared/types'
import { DropdownMenuRoot, DropdownMenuTrigger } from 'reka-ui'
import { CommentMenuContent, menu, tellFailed } from '../lazy'
import { t } from '@/lib/i18n'

/**
 * The ⋯ menu, always visible (never hover-only): Copy link, Report, Delete for the author,
 * and for moderators Pin, Approve, Hide, Restore, Dismiss reports, View user record.
 * The items (CommentMenuContent.vue) are in a module of their own, loaded when a reader points
 * at, focuses or opens a menu. They mount at the first opening and stay, so the menu animates
 * out when it closes.
 * The icon is Lucide's ellipsis, written out as SVG because every comment draws it (cheaper than
 * the component). tests/icons.test.ts keeps the markup equal to Lucide's.
 */
defineProps<{ comment: Comment }>()
const emit = defineEmits<{ delete: [] }>()
const trigger = ref<{ $el: HTMLElement } | null>(null)
const open = ref(false)
const opened = ref(false)
let cancel: (() => void) | null = null

function setOpen(v: boolean) {
  if (cancel) cancel()
  if (!v || opened.value) {
    open.value = v
    return
  }
  if (menu.failed.value) return tellFailed()
  // The first opening: the menu opens once its items are here, mounted closed first so that ⋯
  // names them (aria-controls). Meanwhile a press elsewhere, Escape or focus moving away
  // cancels it, as it would close an open menu.
  const el = trigger.value && trigger.value.$el
  let live = true
  const away = (e: Event) => {
    if (e.type === 'keydown' ? (e as KeyboardEvent).key === 'Escape' : !(el && el.contains(e.target as Node))) stop()
  }
  const events = ['pointerdown', 'keydown', 'focusin']
  const stop = () => {
    live = false
    cancel = null
    events.forEach((name) => document.removeEventListener(name, away, true))
  }
  events.forEach((name) => document.addEventListener(name, away, true))
  cancel = stop
  menu.load().then(() => {
    if (!live || !menu.module.value) return stop()
    stop()
    opened.value = true
    nextTick(() => {
      open.value = true
    })
  })
}

onBeforeUnmount(() => cancel && cancel())

defineExpose({
  focus() {
    const el = trigger.value && trigger.value.$el
    if (el) el.focus()
  },
})
</script>

<template>
  <DropdownMenuRoot :open="open" :modal="false" @update:open="setOpen">
    <DropdownMenuTrigger
      ref="trigger"
      class="ext-glowthread-menu-trigger gt-hit gt:inline-flex gt:size-7 gt:shrink-0 gt:pointer-coarse:-my-[0.625rem] gt:pointer-coarse:size-[2.75rem] gt:items-center gt:justify-center gt:rounded-control gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg gt:data-[state=open]:bg-surface-subtle gt:data-[state=open]:text-fg"
      :aria-label="t('menu-label')"
      @pointerenter="menu.load()"
      @focus="menu.load()"
    >
      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-ellipsis lucide-more-horizontal gt:size-icon" aria-hidden="true"><circle cx="12" cy="12" r="1" /><circle cx="19" cy="12" r="1" /><circle cx="5" cy="12" r="1" /></svg>
    </DropdownMenuTrigger>
    <CommentMenuContent v-if="opened" :comment="comment" :trigger="trigger && trigger.$el" @delete="emit('delete')" />
  </DropdownMenuRoot>
</template>

<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { Smile } from '@lucide/vue'
import { Popover, PopoverTrigger, PopoverContent } from '@/ui/popover'
import { t } from '@/lib/i18n'
import { pageDir } from '@/lib/media'

/**
 * Emoji and kaomoji picker. The characters are text the reader inserts, not icons.
 * Arrow keys move through the grid; choosing one inserts it and returns to the text.
 * The set avoids emoji newer than Unicode 12, which old Android WebViews lack.
 */
const emit = defineEmits<{ pick: [text: string] }>()
const open = ref(false)
const tab = ref<'emoji' | 'kaomoji'>('emoji')
const grid = ref<HTMLElement | null>(null)
let picked = false

const EMOJI = [
  '😀', '😁', '😂', '🤣', '😊', '😍', '🥰', '😘', '😋', '😎', '🤔', '😮', '😳', '😅', '😭', '😢',
  '🥺', '😤', '😡', '😴', '🙄', '😇', '🤗', '👍', '👏', '🙏', '💪', '👀', '❤️', '💕', '💖', '✨',
  '🔥', '💯', '🎂', '🎉', '🎁', '🥳', '🎈', '🍰', '🌸', '🌹', '⭐', '🌙', '👋', '👌', '🙌', '💡',
]
const KAOMOJI = [
  'ヾ(≧▽≦*)o', '(*^▽^*)', '(๑•̀ㅂ•́)و✧', '(｡•́︿•̀｡)', '(=・ω・=)', '(￣▽￣)ノ', 'QAQ', 'orz',
  '(╯°□°）╯︵ ┻━┻', '(⊙ˍ⊙)', '(￣﹏￣；)', 'ヽ(°〇°)ﾉ', '(*/ω＼*)', '(～￣▽￣)～', '(oﾟvﾟ)ノ', 'w(ﾟДﾟ)w',
]
const COLUMNS = { emoji: 8, kaomoji: 2 }

function pick(text: string) {
  picked = true
  emit('pick', text)
  open.value = false
}

function onCloseFocus(e: Event) {
  // After a pick the composer puts the caret back in the text; otherwise the trigger gets focus.
  if (picked) e.preventDefault()
  picked = false
}

function onKey(e: KeyboardEvent) {
  const items = grid.value ? Array.from(grid.value.querySelectorAll<HTMLButtonElement>('button')) : []
  const i = items.indexOf(document.activeElement as HTMLButtonElement)
  if (i < 0) return
  const cols = COLUMNS[tab.value]
  // The grid runs from the right in a right-to-left interface.
  const ahead = pageDir() === 'rtl' ? -1 : 1
  const step: Record<string, number> = { ArrowRight: ahead, ArrowLeft: -ahead, ArrowDown: cols, ArrowUp: -cols }
  let next = -1
  if (e.key in step) next = i + step[e.key]
  else if (e.key === 'Home') next = 0
  else if (e.key === 'End') next = items.length - 1
  if (next < 0 || next >= items.length) return
  e.preventDefault()
  items[next].focus()
}

function switchTab(next: 'emoji' | 'kaomoji') {
  tab.value = next
  nextTick(() => {
    const first = grid.value && grid.value.querySelector('button')
    if (first) (first as HTMLButtonElement).focus()
  })
}
</script>

<template>
  <Popover v-model:open="open">
    <PopoverTrigger
      class="gt-hit gt:inline-flex gt:size-8 gt:items-center gt:justify-center gt:rounded-control gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg gt:data-[state=open]:bg-surface-subtle gt:data-[state=open]:text-fg"
      :aria-label="t('emoji')"
    >
      <Smile class="gt:size-icon-lg" aria-hidden="true" />
    </PopoverTrigger>
    <PopoverContent align="start" class="ext-glowthread-emoji gt:w-[min(312px,calc(100vw-16px))] gt:p-2" :aria-label="t('emoji')" @close-auto-focus="onCloseFocus">
      <div class="gt:mb-2 gt:flex gt:gap-1" role="group" :aria-label="t('emoji')">
        <button
          v-for="name in (['emoji', 'kaomoji'] as const)"
          :key="name"
          type="button"
          class="gt-hit gt:h-7 gt:rounded-control gt:px-2.5 gt:text-meta"
          :class="tab === name ? 'gt:bg-surface-subtle gt:font-medium gt:text-fg' : 'gt:text-fg-muted gt:hover:text-fg'"
          :aria-pressed="tab === name ? 'true' : 'false'"
          @click="switchTab(name)"
        >
          {{ t('emoji-tab-' + name) }}
        </button>
      </div>
      <div
        ref="grid"
        class="gt:grid gt:gap-0.5"
        :class="tab === 'emoji' ? 'gt:grid-cols-8' : 'gt:grid-cols-2'"
        @keydown="onKey"
      >
        <button
          v-for="e in (tab === 'emoji' ? EMOJI : KAOMOJI)"
          :key="e"
          type="button"
          class="gt:flex gt:h-[2.75rem] gt:items-center gt:justify-center gt:rounded-control gt:hover:bg-surface-subtle gt:focus-visible:bg-surface-subtle gt:focus-visible:outline-offset-[calc(var(--gt-v-focus-ring-width)*-1)] gt:sm:h-9"
          :class="tab === 'emoji' ? 'gt:text-[1.25rem]' : 'gt:px-1 gt:text-meta gt:whitespace-nowrap'"
          :aria-label="e"
          @click="pick(e)"
        >
          {{ e }}
        </button>
      </div>
    </PopoverContent>
  </Popover>
</template>

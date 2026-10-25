<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import type { Component } from 'vue'
import { X } from '@lucide/vue'
import { t } from '@/lib/i18n'
import { isImeEnter } from '../keyboard'
import { revealInvisible } from '@/lib/names'

/**
 * One filter of the queue: a field applied with Enter, with optional suggestions as you
 * type (page titles, user names) in the ARIA combobox pattern. Esc closes the suggestions,
 * a second Esc hands focus back to the list.
 */
const props = defineProps<{
  id: string
  value: string
  label: string
  placeholder: string
  icon: Component
  suggest?: (text: string) => Promise<string[]>
  /** Keyboard hint shown at the end of the empty field (/ for the text search). */
  hint?: string
}>()
const emit = defineEmits<{ submit: [value: string], leave: [] }>()

const draft = ref(props.value)
watch(() => props.value, (v) => {
  draft.value = v
})
const options = ref<string[]>([])
const open = ref(false)
const active = ref(-1)
const listId = computed(() => props.id + '-list')
let timer = 0
let asked = ''

function onInput() {
  window.clearTimeout(timer)
  active.value = -1
  const text = draft.value.trim()
  if (!props.suggest || !text) {
    options.value = []
    open.value = false
    return
  }
  timer = window.setTimeout(async () => {
    asked = text
    try {
      const found = await (props.suggest as (s: string) => Promise<string[]>)(text)
      if (asked !== text || draft.value.trim() !== text) return
      options.value = found
      open.value = found.length > 0
    } catch {
      options.value = []
      open.value = false
    }
  }, 200)
}

function choose(value: string) {
  draft.value = value
  open.value = false
  options.value = []
  emit('submit', value.trim())
}

function onKeydown(e: KeyboardEvent) {
  if (isImeEnter(e)) return
  if (e.key === 'ArrowDown' && open.value) {
    e.preventDefault()
    active.value = (active.value + 1) % options.value.length
  } else if (e.key === 'ArrowUp' && open.value) {
    e.preventDefault()
    active.value = active.value <= 0 ? options.value.length - 1 : active.value - 1
  } else if (e.key === 'Enter') {
    e.preventDefault()
    choose(open.value && active.value >= 0 ? options.value[active.value] : draft.value)
  } else if (e.key === 'Escape') {
    e.preventDefault()
    if (open.value) open.value = false
    else {
      // Leaving drops what was typed but not applied.
      draft.value = props.value
      emit('leave')
    }
  }
}

function clear() {
  choose('')
}

function onBlur() {
  // Let a click on an option land first.
  window.setTimeout(() => {
    open.value = false
  }, 150)
}
onBeforeUnmount(() => window.clearTimeout(timer))
</script>

<template>
  <div class="gt:relative gt:min-w-0">
    <span class="gt:pointer-events-none gt:absolute gt:top-0 gt:bottom-0 gt:left-2.5 gt:flex gt:h-8 gt:items-center gt:text-fg-muted" aria-hidden="true">
      <component :is="icon" class="gt:size-icon" />
    </span>
    <input
      :id="id"
      v-model="draft"
      type="text"
      inputmode="search"
      enterkeyhint="search"
      autocomplete="off"
      spellcheck="false"
      :aria-label="label"
      :placeholder="placeholder"
      :role="suggest ? 'combobox' : undefined"
      :aria-autocomplete="suggest ? 'list' : undefined"
      :aria-expanded="suggest ? (open ? 'true' : 'false') : undefined"
      :aria-controls="suggest ? listId : undefined"
      :aria-activedescendant="open && active >= 0 ? listId + '-' + active : undefined"
      class="gt:h-8 gt:w-full gt:rounded-control gt:border gt:border-border-control gt:bg-surface gt:pr-8 gt:pl-8 gt:text-body gt:text-fg gt:focus:border-accent"
      :class="value ? 'gt:border-fg' : ''"
      @input="onInput"
      @keydown="onKeydown"
      @blur="onBlur"
    >
    <button
      v-if="draft"
      type="button"
      class="gt-hit gt:absolute gt:top-1 gt:right-1 gt:inline-flex gt:size-6 gt:items-center gt:justify-center gt:rounded-mark gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg"
      :aria-label="t('admin-filter-clear', label)"
      @click="clear"
    >
      <X class="gt:size-icon-sm" aria-hidden="true" />
    </button>
    <span v-else-if="hint" class="gt-kbd-hint gt:pointer-events-none gt:absolute gt:top-0 gt:right-2 gt:flex gt:h-8 gt:items-center" aria-hidden="true">
      <kbd class="gt-kbd">{{ hint }}</kbd>
    </span>
    <ul
      v-if="suggest"
      v-show="open"
      :id="listId"
      role="listbox"
      :aria-label="label"
      class="gt-anim-pop gt:absolute gt:top-full gt:right-0 gt:left-0 gt:z-[20] gt:mt-1 gt:max-h-64 gt:overflow-y-auto gt:rounded-surface gt:border gt:border-overlay-border gt:bg-surface gt:p-1 gt:shadow-overlay"
      data-state="open"
    >
      <li
        v-for="(o, i) in options"
        :id="listId + '-' + i"
        :key="o"
        role="option"
        :aria-selected="i === active ? 'true' : 'false'"
        class="gt:flex gt:min-h-8 gt:cursor-pointer gt:items-center gt:truncate gt:rounded-control gt:px-2.5 gt:text-body gt:text-fg"
        :class="i === active ? 'gt:bg-surface-strong' : 'gt:hover:bg-surface-subtle'"
        dir="auto"
        @mousedown.prevent="choose(o)"
      >
        {{ revealInvisible(o) }}
      </li>
    </ul>
  </div>
</template>

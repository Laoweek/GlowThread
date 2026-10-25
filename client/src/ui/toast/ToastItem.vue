<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { X } from '@lucide/vue'
import { dismissToast } from './toast'
import type { ToastItem } from './toast'

const props = defineProps<{ item: ToastItem, closeLabel: string }>()
let timer = 0
let remaining = props.item.duration
let started = 0
const paused = ref(false)

function start() {
  started = Date.now()
  timer = window.setTimeout(() => dismissToast(props.item.id), remaining)
}
function pause() {
  if (paused.value) return
  paused.value = true
  window.clearTimeout(timer)
  remaining -= Date.now() - started
}
function resume() {
  if (!paused.value) return
  paused.value = false
  start()
}
function act() {
  if (props.item.action) props.item.action.onClick()
  dismissToast(props.item.id)
}
onMounted(start)
onBeforeUnmount(() => window.clearTimeout(timer))
</script>

<template>
  <div
    class="ext-glowthread-toast gt-anim-enter gt:pointer-events-auto gt:flex gt:min-h-11 gt:w-full gt:items-center gt:gap-2 gt:rounded-surface gt:border gt:border-overlay-border gt:bg-surface gt:py-1.5 gt:pr-1.5 gt:pl-4 gt:text-body gt:text-fg gt:shadow-overlay"
    @mouseenter="pause"
    @mouseleave="resume"
    @focusin="pause"
    @focusout="resume"
  >
    <p class="gt:min-w-0 gt:flex-1 gt:py-1" :class="item.tone === 'error' ? 'gt:text-danger' : ''">
      {{ item.message }}
    </p>
    <button
      v-if="item.action"
      type="button"
      data-toast-action
      class="gt-hit gt:h-8 gt:shrink-0 gt:rounded-control gt:px-2.5 gt:font-medium gt:text-accent-text gt:hover:bg-surface-subtle"
      @click="act"
    >
      {{ item.action.label }}
    </button>
    <button
      type="button"
      class="gt-hit gt:inline-flex gt:size-8 gt:shrink-0 gt:items-center gt:justify-center gt:rounded-control gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg"
      :aria-label="closeLabel"
      @click="dismissToast(item.id)"
    >
      <X class="gt:size-icon" aria-hidden="true" />
    </button>
  </div>
</template>

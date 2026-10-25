<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { toasts } from './toast'
import ToastItem from './ToastItem.vue'

/**
 * Toast region, teleported to the portal container so no skin stacking context or
 * transform can trap it. The two live regions exist before any toast appears, so screen
 * readers announce each message (errors assertively).
 */
defineProps<{ to: HTMLElement, closeLabel: string }>()
const polite = computed(() => toasts.filter((x) => x.tone !== 'error'))
const urgent = computed(() => toasts.filter((x) => x.tone === 'error'))
const region = ref<HTMLElement | null>(null)

// F8 jumps to the newest toast's action (Undo), so it can be reached from the keyboard in
// time (the same key Reka UI and Radix use for their toast regions). Without an action, to the
// first close button.
function onKey(e: KeyboardEvent) {
  if (e.key !== 'F8' || !region.value) return
  const actions = region.value.querySelectorAll<HTMLButtonElement>('[data-toast-action]')
  const target = actions.length ? actions[actions.length - 1] : region.value.querySelector<HTMLButtonElement>('button')
  if (!target) return
  e.preventDefault()
  target.focus()
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport :to="to">
    <div ref="region" data-gt-floating class="gt-toaster gt:pointer-events-none gt:fixed gt:right-0 gt:left-0 gt:z-toast gt:flex gt:justify-center gt:px-4">
      <div class="gt:flex gt:w-full gt:max-w-(--gt-v-toast-max-width) gt:flex-col gt:gap-2">
        <div role="alert" aria-live="assertive" class="gt:flex gt:flex-col gt:gap-2">
          <ToastItem v-for="item in urgent" :key="item.id" :item="item" :close-label="closeLabel" />
        </div>
        <div role="status" aria-live="polite" class="gt:flex gt:flex-col gt:gap-2">
          <ToastItem v-for="item in polite" :key="item.id" :item="item" :close-label="closeLabel" />
        </div>
      </div>
    </div>
  </Teleport>
</template>

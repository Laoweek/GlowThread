<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useHub } from '../context'

/**
 * An item's checkbox: a native checkbox styled like the design system's, which costs far less
 * than the Reka one when hundreds change at once. It reads the selection itself and marks its
 * article with an attribute the item's own template never binds (so a re-render of the item
 * keeps it). Selecting, or selecting all of hundreds, then touches only these small parts.
 */
const props = defineProps<{ id: number, label: string }>()
const hub = useHub()
const on = computed(() => hub.queue.selectedSet.value.has(props.id))
const box = ref<HTMLElement | null>(null)

function mark() {
  const article = box.value && box.value.closest('article')
  if (!article) return
  if (on.value) article.setAttribute('data-selected', '')
  else article.removeAttribute('data-selected')
}
watch(on, mark, { flush: 'post' })
onMounted(mark)
</script>

<template>
  <label ref="box" class="gt-hit gt:relative gt:flex gt:h-8 gt:items-center" @click.stop>
    <input type="checkbox" class="gt-check" :checked="on" :aria-label="label" @change="hub.queue.toggleSelected(id)">
    <svg class="gt-check-mark" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
  </label>
</template>

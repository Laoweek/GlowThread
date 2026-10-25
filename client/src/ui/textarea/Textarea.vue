<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue'
import { cn } from '@/lib/cn'

/**
 * A plain textarea that grows with its content from `rows` up to `maxRows` lines,
 * then scrolls. (CSS field-sizing needs Chrome 123, so this measures scrollHeight.)
 */
const props = withDefaults(defineProps<{ rows?: number, maxRows?: number, class?: string }>(), { rows: 1, maxRows: 10 })
const model = defineModel<string>({ default: '' })
const el = ref<HTMLTextAreaElement | null>(null)

function resize() {
  const ta = el.value
  if (!ta) return
  const cs = getComputedStyle(ta)
  const line = parseFloat(cs.lineHeight) || 20
  const chrome = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom) + parseFloat(cs.borderTopWidth) + parseFloat(cs.borderBottomWidth)
  ta.style.height = 'auto'
  const min = line * props.rows + chrome
  const max = line * props.maxRows + chrome
  const want = Math.min(Math.max(ta.scrollHeight + parseFloat(cs.borderTopWidth) + parseFloat(cs.borderBottomWidth), min), max)
  ta.style.height = want + 'px'
  ta.style.overflowY = ta.scrollHeight + chrome > max + 1 ? 'auto' : 'hidden'
}

watch(model, () => nextTick(resize))
watch(() => props.rows, () => nextTick(resize))
onMounted(resize)
defineExpose({ el, resize })
</script>

<template>
  <textarea
    ref="el"
    v-model="model"
    :rows="rows"
    :class="cn('gt-scroll gt:block gt:w-full gt:resize-none gt:bg-transparent gt:text-body gt:leading-[var(--gt-v-line-height-body)] gt:text-fg gt:outline-none', props.class)"
  />
</template>

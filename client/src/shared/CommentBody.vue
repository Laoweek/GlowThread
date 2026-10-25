<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { toParts } from '@/lib/segments'
import type { Segment } from '@/lib/segments'
import { userUrl } from '@/lib/wiki'
import { t } from '@/lib/i18n'

/**
 * Comment text rendered from segments with text bindings only (never v-html). Long
 * text folds after 6 lines with Show more. Links open in a new tab with rel="nofollow ugc
 * noopener" (docs/api.md) and keep their left-to-right order inside right-to-left text;
 * mentions link to the user page when the user exists. The text takes its own direction
 * (dir="auto") and the box is only as wide as its text, so English text in a right-to-left
 * interface, or Arabic text in a left-to-right one, still starts next to the avatar.
 */
const props = withDefaults(defineProps<{ segments: Segment[], fold?: boolean }>(), { fold: true })
const parts = computed(() => toParts(props.segments, userUrl))
const el = ref<HTMLElement | null>(null)
const expanded = ref(false)
const overflowing = ref(false)

function measure() {
  const node = el.value
  if (!node || !props.fold || expanded.value) return
  overflowing.value = node.scrollHeight > node.clientHeight + 2
}
// Text that content-visibility: auto skips (queue items in the hub) has no layout: reading its
// size lays out each comment on its own. Only text near the screen is measured now, so Show more
// is in the first paint. The ResizeObserver measures the rest once the browser renders it.
function firstMeasure() {
  const node = el.value
  const box = node && observer && 'checkVisibility' in node && !node.checkVisibility({ contentVisibilityAuto: true }) && node.closest('[data-comment]')
  const r = box && box.getBoundingClientRect()
  if (r && (r.bottom < -innerHeight || r.top > 2 * innerHeight)) return
  measure()
}
// Measure again when the width changes (rotation, resizing): a fold without Show more would hide text.
let observer: ResizeObserver | null = null
onMounted(() => {
  nextTick(firstMeasure)
  if (el.value && 'ResizeObserver' in window) {
    // In the next frame: showing Show more changes the layout, which must not happen while the
    // browser delivers size changes (Safari reports a ResizeObserver loop error).
    observer = new ResizeObserver(() => requestAnimationFrame(measure))
    observer.observe(el.value)
  }
})
onBeforeUnmount(() => observer && observer.disconnect())
watch(() => props.segments, () => nextTick(measure))
</script>

<template>
  <div>
    <p
      ref="el"
      dir="auto"
      class="ext-glowthread-body gt:w-fit gt:max-w-[min(var(--gt-v-text-max-width),100%)] gt:font-text gt:text-body gt:break-words gt:whitespace-pre-wrap gt:text-fg"
      :class="fold && !expanded ? 'gt-fold' : ''"
    ><template v-for="(p, i) in parts" :key="i"><a
      v-if="p.kind === 'link'"
      :href="p.href"
      rel="nofollow ugc noopener"
      target="_blank"
      dir="ltr"
      class="gt:text-accent-text gt:underline"
    >{{ p.text }}</a><a
      v-else-if="p.kind === 'mention' && p.href"
      :href="p.href"
      class="gt:font-medium gt:text-accent-text gt:hover:underline"
    >{{ p.text }}</a><template v-else>{{ p.text }}</template></template></p>
    <button
      v-if="fold && (overflowing || expanded)"
      type="button"
      class="gt-hit gt:mt-0.5 gt:text-meta gt:font-medium gt:text-accent-text gt:hover:underline"
      :aria-expanded="expanded ? 'true' : 'false'"
      @click="expanded = !expanded"
    >
      {{ expanded ? t('collapse') : t('expand') }}
    </button>
  </div>
</template>

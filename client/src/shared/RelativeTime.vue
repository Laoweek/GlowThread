<script setup lang="ts">
import { computed, inject, ref } from 'vue'
import type { Ref } from 'vue'
import { exactTime, relativeTime } from '@/lib/time'

/**
 * Relative time with the exact time on hover and on keyboard focus. The hint is CSS only
 * (gt-tip in design/base.css), which keeps Reka's Tooltip out of the comment bundle;
 * screen readers get the exact time as text. `now` comes from the app's one-minute clock.
 */
const props = defineProps<{ iso: string }>()
const now = inject<Ref<number>>('gt-now', ref(Date.now()))
const label = computed(() => relativeTime(props.iso, now.value))
const exact = computed(() => exactTime(props.iso))
</script>

<template>
  <time :datetime="iso" :data-tip="exact" tabindex="0" class="gt-tip gt:rounded-[3px] gt:whitespace-nowrap">
    {{ label }}<span class="gt-sr-only"> ({{ exact }})</span>
  </time>
</template>

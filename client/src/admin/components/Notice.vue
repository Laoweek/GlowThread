<script setup lang="ts">
import { CircleAlert, Info } from '@lucide/vue'
import { Button } from '@/ui/button'

/** A calm inline message: an explanation, a refusal, or a failure with its recovery. */
withDefaults(defineProps<{ tone?: 'neutral' | 'warning' | 'danger', action?: string }>(), { tone: 'neutral' })
const emit = defineEmits<{ action: [] }>()
const TONES = {
  neutral: 'gt:bg-surface-subtle gt:text-fg-muted',
  warning: 'gt:bg-warning-subtle gt:text-warning',
  danger: 'gt:bg-danger-subtle gt:text-danger',
}
</script>

<template>
  <div
    class="ext-glowthread-notice gt:flex gt:items-start gt:gap-2.5 gt:rounded-surface gt:px-3 gt:py-2.5 gt:text-body"
    :class="TONES[tone]"
    :data-glowthread-tone="tone"
    :role="tone === 'danger' ? 'alert' : 'note'"
  >
    <CircleAlert v-if="tone !== 'neutral'" class="gt:mt-0.5 gt:size-icon gt:shrink-0" aria-hidden="true" />
    <Info v-else class="gt:mt-0.5 gt:size-icon gt:shrink-0" aria-hidden="true" />
    <div class="gt:min-w-0 gt:flex-1"><slot /></div>
    <Button v-if="action" size="sm" class="gt:-my-0.5 gt:shrink-0" @click="emit('action')">{{ action }}</Button>
  </div>
</template>

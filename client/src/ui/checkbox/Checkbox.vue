<script setup lang="ts">
import { CheckboxIndicator, CheckboxRoot } from 'reka-ui'
import { Check } from '@lucide/vue'
</script>

<template>
  <CheckboxRoot
    class="gt-hit gt:inline-flex gt:size-4.5 gt:shrink-0 gt:items-center gt:justify-center gt:rounded-mark gt:border gt:border-border-control gt:bg-surface gt:text-on-accent gt:transition-colors gt:data-[state=checked]:border-accent-bg gt:data-[state=checked]:bg-accent-bg"
  >
    <CheckboxIndicator>
      <Check class="gt:size-icon-sm gt:[stroke-width:calc(var(--gt-v-icon-stroke)*1.5)]" aria-hidden="true" />
    </CheckboxIndicator>
  </CheckboxRoot>
</template>

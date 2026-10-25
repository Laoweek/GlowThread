<script setup lang="ts">
import { RadioGroupIndicator, RadioGroupItem } from 'reka-ui'

/** One option with its label; the whole row, gap included, is the label and so the click target. */
defineProps<{ value: string, id: string }>()
</script>

<template>
  <label :for="id" class="gt:flex gt:min-h-[2.75rem] gt:cursor-pointer gt:items-center gt:gap-2.5 gt:sm:min-h-8">
    <RadioGroupItem
      :id="id"
      :value="value"
      class="gt:inline-flex gt:size-4.5 gt:shrink-0 gt:items-center gt:justify-center gt:rounded-full gt:border gt:border-border-control gt:bg-surface gt:transition-colors gt:data-[state=checked]:border-accent-bg"
    >
      <RadioGroupIndicator class="gt:block gt:size-2.5 gt:rounded-full gt:bg-accent-bg" />
    </RadioGroupItem>
    <span class="gt:flex-1 gt:text-body gt:text-fg">
      <slot />
    </span>
  </label>
</template>

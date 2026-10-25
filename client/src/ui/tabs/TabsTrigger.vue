<script setup lang="ts">
import { TabsTrigger } from 'reka-ui'

defineProps<{ value: string }>()
</script>

<template>
  <!-- The selected tab carries the accent as a 2 px underline; its label stays neutral. -->
  <TabsTrigger
    :value="value"
    class="ext-glowthread-tab gt-hit gt:relative gt:inline-flex gt:h-8 gt:items-center gt:rounded-control gt:px-2 gt:text-body gt:text-fg-muted gt:transition-colors gt:hover:text-fg gt:data-[state=active]:font-medium gt:data-[state=active]:text-fg gt:before:absolute gt:before:right-2 gt:before:bottom-0 gt:before:left-2 gt:before:h-(--gt-v-indicator-width) gt:before:rounded-full gt:before:bg-transparent gt:data-[state=active]:before:bg-accent"
  >
    <slot />
  </TabsTrigger>
</template>

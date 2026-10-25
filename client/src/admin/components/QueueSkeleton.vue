<script setup lang="ts">
import { Skeleton } from '@/ui/skeleton'

/** Loading: rows shaped like queue items, no spinner in content. */
</script>

<template>
  <div class="ext-glowthread-loading" aria-hidden="true">
    <div v-for="i in 5" :key="i" class="gt:flex gt:gap-3 gt:border-b gt:border-border gt:px-3 gt:py-4">
      <Skeleton class="gt:size-4.5 gt:shrink-0 gt:rounded-mark" />
      <Skeleton class="gt:hidden gt:size-9 gt:shrink-0 gt:rounded-avatar gt:sm:block" />
      <div class="gt:flex gt:flex-1 gt:flex-col gt:gap-2">
        <Skeleton class="gt:h-3.5 gt:w-48" />
        <Skeleton class="gt:h-3.5 gt:w-4/5" />
        <Skeleton class="gt:h-3.5 gt:w-3/5" />
        <div class="gt:mt-1 gt:flex gt:gap-2">
          <Skeleton class="gt:h-7 gt:w-16" />
          <Skeleton class="gt:h-7 gt:w-16" />
        </div>
      </div>
    </div>
  </div>
</template>

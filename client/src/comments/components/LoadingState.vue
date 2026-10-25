<script setup lang="ts">
import { Skeleton } from '@/ui/skeleton'

/** Skeleton rows in the space the loader reserved: no spinner in content. */
defineProps<{ rows?: number }>()
</script>

<template>
  <div class="ext-glowthread-loading gt:flex gt:flex-col" aria-hidden="true">
    <div v-for="i in (rows || 2)" :key="i" class="gt:flex gt:gap-(--gt-v-avatar-gap) gt:py-(--gt-v-comment-padding-y)">
      <Skeleton class="gt:size-9 gt:shrink-0 gt:rounded-avatar" />
      <div class="gt:flex gt:flex-1 gt:flex-col gt:gap-2">
        <Skeleton class="gt:h-3.5 gt:w-24" />
        <Skeleton class="gt:h-3.5 gt:w-4/5" />
        <Skeleton class="gt:h-3.5 gt:w-2/5" />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { cn } from '@/lib/cn'
import { monogram, nameHue } from '@/lib/avatar'

/**
 * Generated avatar: the first character of the name on a soft colour derived from the
 * name, so every commenter looks different without uploads or outside requests.
 * Hidden users, IP authors and readers without an account get a neutral person icon:
 * Lucide's user icon, written out as SVG because every comment may draw it (cheaper than
 * the component). tests/icons.test.ts keeps the markup equal to Lucide's.
 */
const props = withDefaults(defineProps<{ name?: string | null, generic?: boolean, size?: 'sm' | 'md', class?: string }>(), { size: 'md' })
const plain = computed(() => props.generic || !props.name)
const style = computed(() => (plain.value ? undefined : { '--gt-avatar-hue': String(nameHue(props.name as string)) }))
</script>

<template>
  <span
    aria-hidden="true"
    :style="style"
    :class="cn(
      'ext-glowthread-avatar gt-avatar gt:inline-flex gt:shrink-0 gt:items-center gt:justify-center gt:overflow-hidden gt:rounded-avatar gt:font-avatar gt:select-none',
      size === 'sm' ? 'gt:size-avatar-sm gt:text-small' : 'gt:size-avatar gt:text-[0.9375rem]',
      plain ? 'gt:bg-surface-subtle gt:text-fg-muted' : '',
      props.class,
    )"
  >
    <svg v-if="plain" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user" :class="size === 'sm' ? 'gt:size-icon-sm' : 'gt:size-icon-lg'" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
    <template v-else>{{ monogram(name as string) }}</template>
  </span>
</template>

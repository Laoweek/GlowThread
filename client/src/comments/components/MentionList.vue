<script setup lang="ts">
import { ref, toRef } from 'vue'
import { useFloating, offset, flip, shift, autoUpdate } from '@floating-ui/vue'
import { Avatar } from '@/ui/avatar'
import { displayName } from '@/lib/names'

/**
 * Suggestion list for @mentions, shown under the textarea. Focus stays in the textarea
 * (it carries aria-activedescendant); arrow keys and Enter are handled by the composer.
 * The active option has the menus' neutral highlight; the pink focus ring only when the
 * arrow keys moved there (`keyed`), as focus rings show for keyboard focus only.
 * `reveal` (moderators): characters that show nothing are written out, so lookalikes differ.
 */
const props = defineProps<{ anchor: HTMLElement | null, items: string[], active: number, keyed: boolean, listId: string, portal: HTMLElement, label: string, reveal?: boolean }>()
const emit = defineEmits<{ pick: [name: string], hover: [index: number] }>()
const floating = ref<HTMLElement | null>(null)
const { floatingStyles } = useFloating(toRef(props, 'anchor'), floating, {
  placement: 'bottom-start',
  strategy: 'fixed',
  middleware: [offset(4), flip({ padding: 8 }), shift({ padding: 8 })],
  whileElementsMounted: autoUpdate,
})
</script>

<template>
  <Teleport :to="portal">
    <ul
      :id="listId"
      ref="floating"
      role="listbox"
      data-gt-floating
      :aria-label="label"
      :style="floatingStyles"
      class="ext-glowthread-mentions gt-scroll gt:z-popover gt:max-h-64 gt:w-60 gt:rounded-surface gt:border gt:border-overlay-border gt:bg-surface gt:p-1 gt:text-body gt:text-fg gt:shadow-overlay"
    >
      <li
        v-for="(name, i) in items"
        :id="listId + '-' + i"
        :key="name"
        role="option"
        :aria-selected="i === active ? 'true' : 'false'"
        class="gt:flex gt:min-h-[2.75rem] gt:cursor-pointer gt:items-center gt:gap-2 gt:rounded-control gt:px-2 gt:select-none gt:sm:min-h-9"
        :class="i === active ? (keyed ? 'gt:bg-surface-subtle gt:shadow-[inset_0_0_0_var(--gt-v-focus-ring-width)_var(--gt-v-color-focus-ring)]' : 'gt:bg-surface-subtle') : ''"
        @mousedown.prevent="emit('pick', name)"
        @mousemove="emit('hover', i)"
      >
        <Avatar :name="name" size="sm" />
        <span class="gt:min-w-0 gt:flex-1 gt:truncate" dir="auto">{{ displayName(name, !!reveal) }}</span>
      </li>
    </ul>
  </Teleport>
</template>

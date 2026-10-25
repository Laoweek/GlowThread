<script setup lang="ts">
import { Primitive } from 'reka-ui'
import { cn } from '@/lib/cn'
import { buttonVariants } from './variants'
import type { ButtonVariants } from './variants'

const props = withDefaults(defineProps<{
  variant?: ButtonVariants['variant']
  size?: ButtonVariants['size']
  as?: string
  asChild?: boolean
  class?: string
}>(), { as: 'button' })
// Reka's Primitive only for asChild. Otherwise the element itself, exactly as Primitive would
// render it, without a component in between: every comment has buttons.
</script>

<template>
  <Primitive
    v-if="asChild"
    :as="as"
    :as-child="asChild"
    :data-glowthread-variant="variant || 'secondary'"
    :class="cn(buttonVariants({ variant, size }), props.class)"
  >
    <slot />
  </Primitive>
  <component
    :is="as"
    v-else
    :type="as === 'button' ? 'button' : undefined"
    :data-glowthread-variant="variant || 'secondary'"
    :class="cn(buttonVariants({ variant, size }), props.class)"
  >
    <slot />
  </component>
</template>

import { cva } from 'class-variance-authority'
import type { VariantProps } from 'class-variance-authority'

/**
 * Buttons. One shape (6 px radius), one height scale on the 4 px grid. `primary` is the
 * only filled accent and marks the one main action of a view (Send, Send report).
 * `gt-hit` widens the touch target to 44 px on touch screens without changing the layout.
 */
export const buttonVariants = cva(
  'ext-glowthread-button gt-hit gt:inline-flex gt:items-center gt:justify-center gt:gap-1.5 gt:whitespace-nowrap gt:rounded-control gt:font-medium gt:select-none gt:transition-colors gt:disabled:pointer-events-none gt:disabled:opacity-50 gt:aria-disabled:opacity-50',
  {
    variants: {
      variant: {
        primary: 'gt:bg-accent-bg gt:text-on-accent gt:hover:bg-accent-bg-hover',
        secondary: 'gt:border gt:border-border-control gt:bg-surface gt:text-fg gt:hover:bg-surface-subtle',
        ghost: 'gt:text-fg-muted gt:hover:bg-surface-subtle gt:hover:text-fg',
        subtle: 'gt:bg-surface-subtle gt:text-fg gt:hover:bg-surface-strong',
        danger: 'gt:border gt:border-border-control gt:bg-surface gt:text-danger gt:hover:bg-danger-subtle',
        link: 'gt:text-accent-text gt:underline-offset-2 gt:hover:underline',
      },
      size: {
        sm: 'gt:h-7 gt:px-2.5 gt:text-meta',
        md: 'gt:h-8 gt:px-3 gt:text-body',
        icon: 'gt:size-8',
        'icon-sm': 'gt:size-7',
        inline: 'gt:h-7 gt:px-1.5 gt:-mx-1.5 gt:text-meta',
      },
    },
    defaultVariants: { variant: 'secondary', size: 'md' },
  },
)

export type ButtonVariants = VariantProps<typeof buttonVariants>

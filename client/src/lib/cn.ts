import { clsx } from 'clsx'
import type { ClassValue } from 'clsx'

/**
 * Joins class names. There is no tailwind-merge (it would cost about 9 kB gzip), so
 * components never take a conflicting utility from their caller: a `class` prop adds
 * layout classes (margins, width, flex), and variants come from cva().
 */
export function cn(...inputs: ClassValue[]): string {
  return clsx(inputs)
}

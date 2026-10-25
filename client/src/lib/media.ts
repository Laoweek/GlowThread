import { onBeforeUnmount, ref } from 'vue'
import type { Ref } from 'vue'

/** Reactive matchMedia. Phones (under 640 px) get bottom sheets instead of popovers. */
export function useMediaQuery(query: string): Ref<boolean> {
  const mql = window.matchMedia(query)
  const matches = ref(mql.matches)
  const update = () => {
    matches.value = mql.matches
  }
  // Safari 13 has only addListener; both exist from Safari 14.
  if (mql.addEventListener) mql.addEventListener('change', update)
  else mql.addListener(update)
  onBeforeUnmount(() => {
    if (mql.removeEventListener) mql.removeEventListener('change', update)
    else mql.removeListener(update)
  })
  return matches
}

export const PHONE_QUERY = '(max-width: 639px)'

/**
 * The interface direction. MediaWiki sets dir on <html> for the user language, and for a
 * right-to-left language ResourceLoader serves the stylesheets flipped (CSSJanus).
 */
export function pageDir(): 'ltr' | 'rtl' {
  return document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr'
}

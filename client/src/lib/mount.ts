import { createApp } from 'vue'
import type { Component } from 'vue'

/**
 * Mounts an app into a MediaWiki page. Overlays (menus, popovers, sheets, toasts) go to
 * one container at the end of <body>, outside any skin stacking context. Both carry the
 * scope class the stylesheet is built against (.gt-scope), so nothing styles them from
 * outside and their styles reach nothing else. Both also carry the public theming hook
 * (class ext-glowthread-theme, data-glowthread-app with the app's name), so site CSS can set
 * tokens for the app and its overlays together. One app mounts per page, so the overlay
 * container takes the name of the app that mounts.
 */
export function mountApp(name: 'comments' | 'hub', container: HTMLElement, component: Component, props: Record<string, unknown> = {}) {
  // Only our own container at the end of <body>: page text can carry the same ID.
  let portal = document.getElementById('glowthread-portal')
  if (!portal || portal.parentNode !== document.body) {
    portal = document.createElement('div')
    portal.id = 'glowthread-portal'
    document.body.appendChild(portal)
  }
  portal.className = 'gt-scope gt-portal ext-glowthread-theme'
  portal.setAttribute('data-glowthread-app', name)
  // The app lives in a child of the container: site CSS may place the container itself
  // (for example a grid area for #glowthread in MediaWiki:Vector.css or Citizen.css), and
  // the scoped reset must not undo that.
  const el = document.createElement('div')
  el.className = 'gt-scope gt-root ext-glowthread-theme'
  el.setAttribute('data-glowthread-app', name)
  container.innerHTML = ''
  container.removeAttribute('aria-busy')
  container.appendChild(el)
  const app = createApp(component, Object.assign({ portal }, props))
  app.config.errorHandler = (err) => {
    // Keep the page working: log, never rethrow into the skin's code.
    console.error('[GlowThread]', err)
  }
  app.mount(el)
  return app
}

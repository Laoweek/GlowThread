// ResourceLoader entry of ext.glowThread.admin, loaded only by Special:GlowThread (readers
// never load it). It replaces the no-JavaScript notice inside #ext-glowthread-admin.
import '../lib/polyfills'
import './styles.css'
import { mountApp } from '../lib/mount'
import App from './App.vue'

export function mount(el: HTMLElement) {
  return mountApp('hub', el, App)
}

// ResourceLoader may run the module before the page has finished parsing.
function start() {
  const container = document.getElementById('ext-glowthread-admin')
  if (container && !container.querySelector('.gt-root')) mount(container)
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start)
else start()

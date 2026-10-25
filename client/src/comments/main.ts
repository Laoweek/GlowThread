// ResourceLoader entry of ext.glowThread.app. resources/init/index.js calls
// require( 'ext.glowThread.app' ).mount( container, list ) when the section nears the viewport.
import '../lib/polyfills'
import './styles.css'
import { mountApp } from '../lib/mount'
import { useEarlyResponse } from '../lib/api'
import type { EarlyResponse } from '../lib/api'
import App from './App.vue'

export function mount(el: HTMLElement, list?: EarlyResponse | null) {
  // The first page of the list, which the loader requested while this module loaded.
  if (list) useEarlyResponse(list)
  // The height the loader's skeleton took (from the server's estimate): the app keeps it
  // until the list is drawn, so the page below does not jump twice.
  const reserve = Number(el.getAttribute('data-gt-reserve')) || 280
  return mountApp('comments', el, App, { reserve })
}

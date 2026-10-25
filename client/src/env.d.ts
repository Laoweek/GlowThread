/// <reference types="vite/client" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}

/** The parts of MediaWiki's client API these apps use (MediaWiki 1.43). */
interface MwMessage {
  text(): string
  plain(): string
  exists(): boolean
}

/** jQuery promise: failure handlers get several arguments, see lib/api.ts. */
interface MwThenable {
  then(done: (...args: any[]) => unknown, fail?: (...args: any[]) => unknown): unknown
  abort?(): void
}

interface MwApi {
  get(params: Record<string, unknown>, ajaxOptions?: Record<string, unknown>): MwThenable
  postWithToken(type: 'csrf', params: Record<string, unknown>, ajaxOptions?: Record<string, unknown>): MwThenable
}

interface MwGlobal {
  config: { get(key: string): any }
  message(key: string, ...params: Array<string | number>): MwMessage
  user: {
    getName(): string | null
    isAnon(): boolean
    isNamed?(): boolean
  }
  util: {
    getUrl(title: string, params?: Record<string, string | number>): string
    wikiUrlencode(s: string): string
  }
  language: {
    convertNumber(n: number): string
    bcp47(languageCode: string): string
  }
  Api: new (options?: Record<string, unknown>) => MwApi
  loader: { using(modules: string | string[]): MwThenable }
}

declare const mw: MwGlobal

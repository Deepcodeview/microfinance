/**
 * Build target. One codebase, three products:
 *   web      — browser app (admin/staff/customer), same-origin API, HttpOnly cookie session
 *   agent    — Android app for collection agents (Capacitor), bearer token, server fixed at build time
 *   customer — Android app for customers (Capacitor), bearer token, server fixed at build time
 */
export type AppMode = 'web' | 'agent' | 'customer'

export const APP_MODE: AppMode = (import.meta.env.VITE_APP_MODE as AppMode | undefined) ?? 'web'
export const IS_APP = APP_MODE !== 'web'

const DEFAULT_SERVER = ((import.meta.env.VITE_API_SERVER as string | undefined) ?? '').replace(/\/+$/, '')

/**
 * Server origin the app talks to (web: same origin). The apps are locked to the address they were built
 * with (VITE_API_SERVER) — people cannot change it, and an address saved by an older version is ignored.
 */
export function serverOrigin(): string {
  return IS_APP ? DEFAULT_SERVER : ''
}

export const apiBase = () => `${serverOrigin()}/api/v1`

/** Public origin for links shown to people (receipt verification). */
export const publicOrigin = () => (IS_APP ? serverOrigin().replace(/:\d+$/, ':5180') : window.location.origin)

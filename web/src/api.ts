import { IS_APP, apiBase } from './config'

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly fields: Record<string, string>
  readonly requestId: string | undefined

  constructor(status: number, code: string, message: string, fields: Record<string, string> = {}, requestId?: string) {
    super(message)
    this.status = status
    this.code = code
    this.fields = fields
    this.requestId = requestId
  }
}

/** Offline / unreachable server — callers (e.g. the agent's payment form) may queue the request. */
export const isNetworkError = (e: unknown) => e instanceof ApiError && e.code === 'NETWORK_ERROR'


// Web: the session lives in an HttpOnly cookie set by the API, so page scripts can never read it, and every
// request carries the CSRF header the API requires for cookie-authenticated writes.
// Android apps: there is no shared cookie jar with the API, so they use a bearer token kept in the app's
// private storage.
const BASE_HEADERS: Record<string, string> = IS_APP
  ? { Accept: 'application/json' }
  : { Accept: 'application/json', 'X-Requested-With': 'FinServe', 'X-Auth-Mode': 'cookie' }

const TOKEN_KEY = 'finserve.token'
export const appToken = {
  get(): string | null {
    try {
      return IS_APP ? localStorage.getItem(TOKEN_KEY) : null
    } catch {
      return null
    }
  },
  set(token: string | null): void {
    if (!IS_APP) return
    try {
      if (token) localStorage.setItem(TOKEN_KEY, token)
      else localStorage.removeItem(TOKEN_KEY)
    } catch {
      // Not persisted; the user signs in again next launch.
    }
  },
}

function authHeaders(): Record<string, string> {
  const token = appToken.get()
  return token ? { ...BASE_HEADERS, Authorization: `Bearer ${token}` } : BASE_HEADERS
}

let onUnauthenticated: () => void = () => {}
let onMfaSetupRequired: () => void = () => {}
export function setAuthHandlers(handlers: { unauthenticated: () => void; mfaSetupRequired: () => void }): void {
  onUnauthenticated = handlers.unauthenticated
  onMfaSetupRequired = handlers.mfaSetupRequired
}

/**
 * The API's JSON body. Some PHP hosts print a startup warning (e.g. an upload over post_max_size) in front
 * of it; the JSON that follows is still read so the real message reaches the user.
 */
async function readJson(response: Response): Promise<any> { // eslint-disable-line @typescript-eslint/no-explicit-any
  const text = await response.text().catch(() => '')
  try {
    return JSON.parse(text)
  } catch {
    const start = text.indexOf('{"')
    try {
      return start >= 0 ? JSON.parse(text.slice(start)) : null
    } catch {
      return null
    }
  }
}

async function request<T>(method: string, path: string, body?: unknown, extraHeaders: Record<string, string> = {}): Promise<{ data: T; meta?: unknown }> {
  const headers: Record<string, string> = { ...authHeaders(), ...extraHeaders }
  const isForm = body instanceof FormData
  if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json'

  let response: Response
  try {
    response = await fetch(`${apiBase()}${path}`, {
      method,
      headers,
      credentials: IS_APP ? 'omit' : 'same-origin',
      body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    })
  } catch {
    throw new ApiError(0, 'NETWORK_ERROR', 'Cannot reach the server. Check your connection and try again.')
  }

  if (response.status === 204) return { data: undefined as T }

  const json = await readJson(response)
  if (!response.ok) {
    const err = json?.error ?? {}
    if (response.status === 401 && !path.startsWith('/auth/')) onUnauthenticated()
    if (err.code === 'MFA_SETUP_REQUIRED') onMfaSetupRequired()
    throw new ApiError(response.status, err.code ?? 'UNKNOWN', err.message ?? `Request failed (${response.status}).`, err.fields ?? {}, err.request_id)
  }
  return json
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path).then((r) => r.data),
  getPage: <T, M>(path: string) => request<T>('GET', path) as Promise<{ data: T; meta: M }>,
  post: <T>(path: string, body?: unknown, headers?: Record<string, string>) => request<T>('POST', path, body ?? {}, headers).then((r) => r.data),
  patch: <T>(path: string, body: unknown) => request<T>('PATCH', path, body).then((r) => r.data),
  delete: <T>(path: string) => request<T>('DELETE', path).then((r) => r.data),
  upload: <T>(path: string, form: FormData) => request<T>('POST', path, form).then((r) => r.data),

  /** Authenticated binary fetch (proof images, documents) as an object URL the caller must revoke. */
  async blobUrl(path: string): Promise<{ url: string; type: string }> {
    const response = await fetch(`${apiBase()}${path}`, { headers: authHeaders(), credentials: IS_APP ? 'omit' : 'same-origin' })
    if (!response.ok) throw new ApiError(response.status, 'UNKNOWN', 'Could not load the file.')
    const blob = await response.blob()
    return { url: URL.createObjectURL(blob), type: blob.type }
  },

  /** Authenticated file download (e.g. CSV exports). */
  async download(path: string, filename: string): Promise<void> {
    const response = await fetch(`${apiBase()}${path}`, { headers: authHeaders(), credentials: IS_APP ? 'omit' : 'same-origin' })
    if (!response.ok) {
      const json = await readJson(response)
      throw new ApiError(response.status, json?.error?.code ?? 'UNKNOWN', json?.error?.message ?? 'Download failed.')
    }
    const url = URL.createObjectURL(await response.blob())
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)
  },
}

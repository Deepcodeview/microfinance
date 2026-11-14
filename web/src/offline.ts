/**
 * Offline field collection (TDD §12).
 * - Pending payments are stored in IndexedDB, encrypted with AES-GCM under a per-device key that is
 *   generated non-extractable (the browser can use it, but no script can export it).
 * - Each pending payment keeps the Idempotency-Key it was created with, so syncing twice — or syncing
 *   a payment the server already received before the connection dropped — can never duplicate it.
 * - Server rejections are kept and shown to the agent; nothing is silently changed or dropped.
 * - Only today's minimal worklist is cached, and it expires at the end of the business day.
 */
import { ApiError, api, isNetworkError } from './api'
import type { Payment } from './types'

const DB = 'finserve-offline'
const STORES = ['keys', 'queue', 'rejected', 'cache'] as const
type Store = (typeof STORES)[number]

export interface QueuedPayment {
  id: string
  idempotencyKey: string
  body: Record<string, unknown>
  label: string
  createdAt: string
}

export interface RejectedPayment extends QueuedPayment {
  reason: string
}

let dbPromise: Promise<IDBDatabase> | null = null
let opening: IDBOpenDBRequest | null = null

function openOnce(timeoutMs: number): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB, 1)
    opening = req
    // A reload while the database is being created can leave an open request that never settles
    // (the previous page still holds the half-made connection). Give up and retry instead of hanging.
    const timer = setTimeout(() => reject(new Error('IndexedDB open timed out')), timeoutMs)
    req.onupgradeneeded = () => STORES.forEach((s) => req.result.objectStoreNames.contains(s) || req.result.createObjectStore(s))
    req.onsuccess = () => {
      clearTimeout(timer)
      opening = null
      const db = req.result
      // Let other tabs (or a newer app version) upgrade instead of blocking behind us.
      db.onversionchange = () => {
        db.close()
        dbPromise = null
      }
      resolve(db)
    }
    req.onerror = () => {
      clearTimeout(timer)
      reject(req.error)
    }
  })
}

/** One shared connection per page, closed when the page is hidden so reloads never wait on it. */
function open(): Promise<IDBDatabase> {
  dbPromise ??= (async () => {
    let lastError: unknown
    for (const timeout of [3000, 6000, 10000]) {
      try {
        return await openOnce(timeout)
      } catch (err) {
        lastError = err
      }
    }
    throw lastError
  })().catch((err) => {
    dbPromise = null
    throw err
  })
  return dbPromise
}

if (typeof window !== 'undefined') {
  window.addEventListener('pagehide', () => {
    // If we're mid-way through creating the database, abort it: a page frozen in the back/forward
    // cache would otherwise hold the upgrade open and block the next page's open() indefinitely.
    try {
      opening?.transaction?.abort()
    } catch {
      // Not in an upgrade.
    }
    try {
      opening?.result.close()
    } catch {
      // Result not available yet.
    }
    opening = null
    const pending = dbPromise
    dbPromise = null
    pending?.then((db) => db.close()).catch(() => {})
  })
}

async function tx<T>(store: Store, mode: IDBTransactionMode, fn: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await open()
  return new Promise((resolve, reject) => {
    const request = fn(db.transaction(store, mode).objectStore(store))
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
}

let keyPromise: Promise<CryptoKey> | null = null

/**
 * The per-device key, created once. Memoised so concurrent first callers (worklist cache + sync)
 * can't each generate a key and overwrite each other — that would make earlier data undecryptable.
 */
function deviceKey(): Promise<CryptoKey> {
  keyPromise ??= (async () => {
    const existing = await tx<CryptoKey | undefined>('keys', 'readonly', (s) => s.get('device'))
    if (existing) return existing
    const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])
    // add() (not put) fails if another tab stored a key first; then use theirs.
    await tx('keys', 'readwrite', (s) => s.add(key, 'device')).catch(() => undefined)
    return (await tx<CryptoKey | undefined>('keys', 'readonly', (s) => s.get('device'))) ?? key
  })().catch((err) => {
    keyPromise = null
    throw err
  })
  return keyPromise
}

async function seal(value: unknown): Promise<{ iv: Uint8Array; data: ArrayBuffer }> {
  const iv = crypto.getRandomValues(new Uint8Array(12))
  const data = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, await deviceKey(), new TextEncoder().encode(JSON.stringify(value)))
  return { iv, data }
}

async function unseal<T>(sealed: { iv: Uint8Array; data: ArrayBuffer }): Promise<T> {
  const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: sealed.iv as BufferSource }, await deviceKey(), sealed.data)
  return JSON.parse(new TextDecoder().decode(plain)) as T
}

async function all<T>(store: Store): Promise<T[]> {
  const sealed = await tx<{ iv: Uint8Array; data: ArrayBuffer }[]>(store, 'readonly', (s) => s.getAll())
  return Promise.all(sealed.map((x) => unseal<T>(x)))
}

const listeners = new Set<() => void>()
export function onQueueChange(fn: () => void): () => void {
  listeners.add(fn)
  return () => listeners.delete(fn)
}
const notify = () => listeners.forEach((fn) => fn())

export async function queuePayment(item: Omit<QueuedPayment, 'id' | 'createdAt'>): Promise<QueuedPayment> {
  const full: QueuedPayment = { ...item, id: item.idempotencyKey, createdAt: new Date().toISOString() }
  // Encrypt before opening the transaction: IndexedDB transactions close if you await inside them.
  const sealed = await seal(full)
  await tx('queue', 'readwrite', (s) => s.put(sealed, full.id))
  notify()
  return full
}

export const listQueued = () => all<QueuedPayment>('queue').catch(() => [] as QueuedPayment[])
export const listRejected = () => all<RejectedPayment>('rejected').catch(() => [] as RejectedPayment[])

export async function dismissRejected(id: string): Promise<void> {
  await tx('rejected', 'readwrite', (s) => s.delete(id))
  notify()
}

let syncing = false

/** Sends queued payments in order. Stops at the first network failure; keeps server rejections for the agent. */
export async function syncQueue(): Promise<{ sent: Payment[]; rejected: number }> {
  if (syncing) return { sent: [], rejected: 0 }
  syncing = true
  const sent: Payment[] = []
  let rejected = 0
  try {
    for (const item of await listQueued()) {
      try {
        sent.push(await api.post<Payment>('/payments', item.body, { 'Idempotency-Key': item.idempotencyKey }))
        await tx('queue', 'readwrite', (s) => s.delete(item.id))
      } catch (err) {
        if (isNetworkError(err)) break
        if (err instanceof ApiError && err.status === 401) break
        const reason = err instanceof ApiError ? `${err.message}${Object.values(err.fields).length ? ' ' + Object.values(err.fields).join(' ') : ''}` : 'Rejected by the server.'
        const sealed = await seal({ ...item, reason } satisfies RejectedPayment)
        await tx('rejected', 'readwrite', (s) => s.put(sealed, item.id))
        await tx('queue', 'readwrite', (s) => s.delete(item.id))
        rejected++
      }
    }
  } finally {
    syncing = false
    notify()
  }
  return { sent, rejected }
}

export async function cacheWorklist(data: unknown, businessDate: string): Promise<void> {
  const sealed = await seal({ data, businessDate, savedAt: new Date().toISOString() })
  await tx('cache', 'readwrite', (s) => s.put(sealed, 'worklist'))
}

export async function loadWorklist<T>(): Promise<{ data: T; savedAt: string } | null> {
  const sealed = await tx<{ iv: Uint8Array; data: ArrayBuffer } | undefined>('cache', 'readonly', (s) => s.get('worklist')).catch(() => undefined)
  if (!sealed) return null
  const value = await unseal<{ data: T; businessDate: string; savedAt: string }>(sealed)
  const today = new Date().toISOString().slice(0, 10)
  // Expire after the business day: stale customer data should not linger on a field device.
  if (value.businessDate < today && Date.now() - Date.parse(value.savedAt) > 18 * 3600_000) return null
  return { data: value.data, savedAt: value.savedAt }
}

/** On sign-out, wipe cached customer data. Unsynced payments are kept (they are money already collected). */
export async function clearCache(): Promise<void> {
  await tx('cache', 'readwrite', (s) => s.clear()).catch(() => {})
}

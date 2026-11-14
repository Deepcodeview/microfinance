import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api } from './api'
import type { PageMeta } from './types'

/** Loads `path` (skips when null). `reload()` refetches; `setData` lets mutations update in place. */
export function useFetch<T>(path: string | null) {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [version, setVersion] = useState(0)

  useEffect(() => {
    if (path === null) return
    let cancelled = false
    setError(null)
    api
      .get<T>(path)
      .then((d) => !cancelled && setData(d))
      .catch((e) => !cancelled && setError(e))
    return () => {
      cancelled = true
    }
  }, [path, version])

  const reload = useCallback(() => setVersion((v) => v + 1), [])
  return { data, error, setData, reload, loading: data === null && error === null && path !== null }
}

/** Paginated list driven by URL query params, so filters survive reloads and are shareable. */
export function usePagedList<T>(endpoint: string) {
  const [params, setParams] = useSearchParams()
  const [rows, setRows] = useState<T[] | null>(null)
  const [meta, setMeta] = useState<PageMeta | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [version, setVersion] = useState(0)
  const query = params.toString()

  useEffect(() => {
    let cancelled = false
    setError(null)
    api
      .getPage<T[], PageMeta>(`${endpoint}${endpoint.includes('?') ? '&' : '?'}${query}`)
      .then((r) => {
        if (cancelled) return
        setRows(r.data)
        setMeta(r.meta)
      })
      .catch((e) => !cancelled && setError(e))
    return () => {
      cancelled = true
    }
  }, [endpoint, query, version])

  const update = useCallback(
    (changes: Record<string, string>) => {
      const next = new URLSearchParams(params)
      for (const [key, value] of Object.entries(changes)) {
        if (value) next.set(key, value)
        else next.delete(key)
      }
      if (!('page' in changes)) next.delete('page')
      setParams(next)
    },
    [params, setParams],
  )

  return { rows, meta, error, params, update, reload: () => setVersion((v) => v + 1) }
}

/**
 * A paged list whose page lives in component state, for lists inside tabs or modals where the URL already
 * holds other filters. Goes back to page 1 whenever the endpoint (its filters) changes.
 */
export function useLocalPagedList<T>(endpoint: string | null, perPage = 20) {
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<T[] | null>(null)
  const [meta, setMeta] = useState<PageMeta | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [version, setVersion] = useState(0)

  useEffect(() => setPage(1), [endpoint])
  useEffect(() => {
    if (endpoint === null) return
    let cancelled = false
    setError(null)
    api
      .getPage<T[], PageMeta>(`${endpoint}${endpoint.includes('?') ? '&' : '?'}page=${page}&per_page=${perPage}`)
      .then((r) => {
        if (cancelled) return
        setRows(r.data)
        setMeta(r.meta)
      })
      .catch((e) => !cancelled && setError(e))
    return () => {
      cancelled = true
    }
  }, [endpoint, page, perPage, version])

  return { rows, meta, error, page, setPage, reload: useCallback(() => setVersion((v) => v + 1), []) }
}

/** A stable random key per form instance, reused across retries so a payment is never recorded twice. */
export function newIdempotencyKey(): string {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  return 'web-' + Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

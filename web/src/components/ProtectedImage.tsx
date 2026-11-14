import { useEffect, useState } from 'react'
import { api } from '../api'

/** Image from an authenticated API path (works with the web cookie and the app's bearer token). */
export function ProtectedImage({ path, alt, className }: { path: string; alt: string; className?: string }) {
  const [src, setSrc] = useState<string | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let url: string | null = null
    let cancelled = false
    api
      .blobUrl(path)
      .then((r) => {
        url = r.url
        if (!cancelled) setSrc(r.url)
      })
      .catch(() => !cancelled && setFailed(true))
    return () => {
      cancelled = true
      if (url) URL.revokeObjectURL(url)
    }
  }, [path])

  if (failed) return <p className="muted">Could not load the image.</p>
  if (!src) return <p className="muted">Loading image…</p>
  return <img src={src} alt={alt} className={className} />
}

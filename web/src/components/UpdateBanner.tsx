import { useEffect, useState } from 'react'
import { api } from '../api'
import { APP_MODE, IS_APP } from '../config'

interface Latest {
  version_code: number
  version_name: string
  url: string
}

const OWN_VERSION = Number((import.meta.env.VITE_APP_VERSION_CODE as string | undefined) ?? 0)
const CHECK_EVERY_MS = 30 * 60 * 1000

/**
 * Android apps: when kbsave.in has a newer APK than the one installed, offer it. Checked on start and
 * whenever the app comes back to the foreground (at most every 30 minutes).
 */
export function UpdateBanner() {
  const [latest, setLatest] = useState<Latest | null>(null)
  const [hidden, setHidden] = useState(false)

  useEffect(() => {
    if (!IS_APP || !OWN_VERSION) return
    let lastCheck = 0
    const check = () => {
      if (document.visibilityState !== 'visible' || Date.now() - lastCheck < CHECK_EVERY_MS) return
      lastCheck = Date.now()
      api
        .get<Record<string, Latest | null>>('/public/app-version')
        .then((v) => setLatest(v[APP_MODE] ?? null))
        .catch(() => undefined)
    }
    check()
    document.addEventListener('visibilitychange', check)
    return () => document.removeEventListener('visibilitychange', check)
  }, [])

  if (hidden || !latest || latest.version_code <= OWN_VERSION) return null
  return (
    <div className="update-banner" role="status">
      <span>New version {latest.version_name} is available.</span>
      <a className="btn btn--small btn--light" href={latest.url} target="_blank" rel="noreferrer">Update</a>
      <button type="button" className="update-banner__close" aria-label="Later" onClick={() => setHidden(true)}>×</button>
    </div>
  )
}

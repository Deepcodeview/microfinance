import { locale } from '../i18n'
import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth'
import { money } from '../format'
import { dismissRejected, listQueued, listRejected, onQueueChange, syncQueue, type QueuedPayment, type RejectedPayment } from '../offline'
import { Modal } from './ui'

/** Offline banner + pending-sync indicator. Syncs automatically when the connection returns. */
export function OfflineStatus() {
  const { can } = useAuth()
  const [online, setOnline] = useState(() => navigator.onLine)
  const [queued, setQueued] = useState<QueuedPayment[]>([])
  const [rejected, setRejected] = useState<RejectedPayment[]>([])
  const [showRejected, setShowRejected] = useState(false)
  const [message, setMessage] = useState('')

  const refresh = useCallback(() => {
    listQueued().then(setQueued)
    listRejected().then(setRejected)
  }, [])

  const sync = useCallback(async () => {
    const { sent, rejected: r } = await syncQueue().catch(() => ({ sent: [], rejected: 0 }))
    if (sent.length || r) setMessage(`${sent.length} offline payment(s) synced${r ? `, ${r} rejected — please review` : ''}.`)
  }, [])

  useEffect(() => {
    if (!can('payments.create')) return
    refresh()
    const off = onQueueChange(refresh)
    const goOnline = () => {
      setOnline(true)
      void sync()
    }
    const goOffline = () => setOnline(false)
    window.addEventListener('online', goOnline)
    window.addEventListener('offline', goOffline)
    const timer = setInterval(() => navigator.onLine && void sync(), 30_000)
    void sync()
    return () => {
      off()
      window.removeEventListener('online', goOnline)
      window.removeEventListener('offline', goOffline)
      clearInterval(timer)
    }
  }, [can, refresh, sync])

  if (!can('payments.create')) return online ? null : <div className="offline-banner">You're offline.</div>

  return (
    <>
      {!online ? <div className="offline-banner">You're offline. Collections are saved securely on this device and sync when you reconnect.</div> : null}
      {queued.length > 0 ? (
        <div className="offline-banner" style={{ background: 'var(--info-soft)', color: 'var(--info)' }}>
          {queued.length} payment(s) waiting to sync ({money(String(queued.reduce((s, q) => s + Number(q.body.amount), 0)))}).{' '}
          {online ? <button type="button" className="btn btn--small" onClick={() => void sync()}>Sync now</button> : null}
        </div>
      ) : null}
      {rejected.length > 0 ? (
        <div className="offline-banner" style={{ background: 'var(--danger-soft)', color: 'var(--danger)' }}>
          {rejected.length} offline payment(s) were rejected by the server.{' '}
          <button type="button" className="btn btn--small" onClick={() => setShowRejected(true)}>Review</button>
        </div>
      ) : null}
      {message ? (
        <div className="offline-banner" style={{ background: 'var(--success-soft)', color: 'var(--success)' }}>
          {message} <button type="button" className="btn btn--ghost btn--small" onClick={() => setMessage('')}>Dismiss</button>
        </div>
      ) : null}
      {showRejected ? (
        <Modal title="Rejected offline payments" onClose={() => setShowRejected(false)}>
          <p className="muted">These were not recorded. Nothing was changed automatically — check with the customer and record again if needed.</p>
          {rejected.map((r) => (
            <div key={r.id} className="doc" style={{ marginBottom: 8 }}>
              <strong>{r.label}</strong>
              <div className="small muted">Collected {new Date(r.createdAt).toLocaleString(locale())}</div>
              <div className="small" style={{ color: 'var(--danger)', marginTop: 4 }}>{r.reason}</div>
              <button type="button" className="btn btn--small" style={{ marginTop: 8 }} onClick={() => dismissRejected(r.id)}>I've handled this</button>
            </div>
          ))}
          {rejected.length === 0 ? <p>All handled.</p> : null}
        </Modal>
      ) : null}
    </>
  )
}

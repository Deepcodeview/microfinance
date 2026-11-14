import { useCallback, useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { api } from '../api'
import { dateTime } from '../format'
import { Icons } from './icons'

interface Item {
  id: string
  event: string
  title: string
  body: string
  link: string | null
  status: 'unread' | 'read'
  created_at: string
}

/** Bell with unread badge; polls every minute and on navigation. */
export function NotificationBell({ placement = 'down' }: { placement?: 'down' | 'right' }) {
  const navigate = useNavigate()
  const location = useLocation()
  const [items, setItems] = useState<Item[]>([])
  const [unread, setUnread] = useState(0)
  const [open, setOpen] = useState(false)
  const [anchor, setAnchor] = useState<{ top: number; left: number } | null>(null)
  const ref = useRef<HTMLDivElement>(null)
  const buttonRef = useRef<HTMLButtonElement>(null)

  function toggle() {
    // The sidebar clips overflowing content, so the side panel is positioned against the viewport.
    const rect = buttonRef.current?.getBoundingClientRect()
    if (rect && placement === 'right') {
      setAnchor({ top: Math.max(12, Math.min(rect.top, window.innerHeight - 480)), left: rect.right + 10 })
    }
    setOpen((o) => !o)
  }

  const load = useCallback(() => {
    api
      .get<{ items: Item[]; unread: number }>('/notifications')
      .then((d) => {
        setItems(d.items)
        setUnread(d.unread)
      })
      .catch(() => {})
  }, [])

  useEffect(() => {
    load()
    const timer = setInterval(load, 60_000)
    return () => clearInterval(timer)
  }, [load, location.pathname])

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false)
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [open])

  async function openItem(item: Item) {
    if (item.status === 'unread') {
      await api.post(`/notifications/${item.id}/read`).catch(() => {})
      load()
    }
    setOpen(false)
    if (item.link) navigate(item.link)
  }

  async function readAll() {
    await api.post('/notifications/read-all').catch(() => {})
    load()
  }

  return (
    <div ref={ref} style={{ position: 'relative' }}>
      <button ref={buttonRef} type="button" className="icon-btn bell" onClick={toggle} aria-label={`Notifications${unread ? `, ${unread} unread` : ''}`} aria-expanded={open}>
        <Icons.bell />
        {unread > 0 ? <span className="bell__dot">{unread > 9 ? '9+' : unread}</span> : null}
      </button>
      {open ? (
        <div
          className={`popover notif-panel notif-panel--${placement}`}
          style={placement === 'right' && anchor ? { position: 'fixed', top: anchor.top, left: anchor.left, zIndex: 120 } : undefined}
        >
          <div className="row row--between" style={{ padding: '6px 10px 8px' }}>
            <strong className="serif" style={{ fontSize: 16 }}>Notifications</strong>
            {unread > 0 ? <button type="button" className="btn btn--ghost btn--small" onClick={readAll}>Mark all read</button> : null}
          </div>
          {items.length === 0 ? (
            <div className="empty" style={{ padding: 24 }}>Nothing yet.</div>
          ) : (
            <div className="notif-list">
              {items.map((n) => (
                <button key={n.id} type="button" className={`notif${n.status === 'unread' ? ' notif--unread' : ''}`} onClick={() => openItem(n)}>
                  <div className="notif__title">{n.title}</div>
                  <div className="notif__body">{n.body}</div>
                  <div className="notif__time">{dateTime(n.created_at)}</div>
                </button>
              ))}
            </div>
          )}
        </div>
      ) : null}
    </div>
  )
}

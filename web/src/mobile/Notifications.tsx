import { useNavigate } from 'react-router-dom'
import { api } from '../api'
import { Empty, ErrorBanner, Loading, PageHeader } from '../components/ui'
import { dateTime } from '../format'
import { useFetch } from '../hooks'

interface Item {
  id: string
  title: string
  body: string
  link: string | null
  status: 'unread' | 'read'
  created_at: string
}

/** Full-screen notification list for the apps. */
export function NotificationsPage() {
  const navigate = useNavigate()
  const { data, error, reload } = useFetch<{ items: Item[]; unread: number }>('/notifications')
  if (!data) return error ? <ErrorBanner error={error} /> : <Loading />

  async function open(n: Item) {
    if (n.status === 'unread') await api.post(`/notifications/${n.id}/read`).catch(() => {})
    if (n.link) navigate(n.link)
    else reload()
  }

  return (
    <>
      <PageHeader
        title="Alerts"
        actions={data.unread > 0 ? <button type="button" className="btn btn--small" onClick={() => api.post('/notifications/read-all').then(reload)}>Mark all read</button> : null}
      />
      {data.items.length === 0 ? (
        <div className="card"><Empty title="No alerts">Payment confirmations, reminders and replies will appear here.</Empty></div>
      ) : (
        <div className="card" style={{ padding: 6 }}>
          {data.items.map((n) => (
            <button key={n.id} type="button" className={`notif${n.status === 'unread' ? ' notif--unread' : ''}`} onClick={() => open(n)}>
              <div className="notif__title">{n.title}</div>
              <div className="notif__body">{n.body}</div>
              <div className="notif__time">{dateTime(n.created_at)}</div>
            </button>
          ))}
        </div>
      )}
    </>
  )
}

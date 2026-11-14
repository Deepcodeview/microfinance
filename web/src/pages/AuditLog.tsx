import { Fragment, useState } from 'react'
import { ErrorBanner, Loading, PageHeader, Pager } from '../components/ui'
import { dateTime } from '../format'
import { usePagedList } from '../hooks'

interface Entry {
  id: string
  action: string
  entity_type: string | null
  entity_id: string | null
  before_json: string | null
  after_json: string | null
  reason: string | null
  ip: string | null
  request_id: string
  created_at: string
  actor_name: string | null
}

const pretty = (json: string | null) => (json ? JSON.stringify(JSON.parse(json), null, 2) : '—')

export function AuditLog() {
  const { rows, meta, error, params, update } = usePagedList<Entry>('/audit-logs?per_page=50')
  const [open, setOpen] = useState<string | null>(null)
  const [action, setAction] = useState(params.get('action') ?? '')

  return (
    <>
      <PageHeader title="Audit log" subtitle="Append-only record of every change, approval, financial posting and sensitive read." />
      <form className="toolbar" onSubmit={(e) => { e.preventDefault(); update({ action: action.trim() }) }}>
        <input type="search" placeholder="Action prefix, e.g. payment or loan.approved" value={action} onChange={(e) => setAction(e.target.value)} />
        <input type="date" value={params.get('date_from') ?? ''} onChange={(e) => update({ date_from: e.target.value })} aria-label="From" />
        <input type="date" value={params.get('date_to') ?? ''} onChange={(e) => update({ date_to: e.target.value })} aria-label="To" />
      </form>
      <ErrorBanner error={error} />
      {rows === null && !error ? <Loading /> : null}
      {rows ? (
        <div className="table-wrap">
          <table className="table table--compact">
            <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Reason</th><th>Request</th></tr></thead>
            <tbody>
              {rows.map((r) => (
                <Fragment key={r.id}>
                  <tr onClick={() => setOpen(open === r.id ? null : r.id)} style={{ cursor: 'pointer' }}>
                    <td>{dateTime(r.created_at)}</td>
                    <td>{r.actor_name ?? <span className="muted">system</span>}</td>
                    <td className="mono">{r.action}</td>
                    <td>{r.entity_type ? `${r.entity_type} #${r.entity_id ?? ''}` : '—'}</td>
                    <td style={{ whiteSpace: 'normal', maxWidth: 280 }}>{r.reason ?? '—'}</td>
                    <td className="mono small muted">{r.request_id.slice(0, 8)}</td>
                  </tr>
                  {open === r.id ? (
                    <tr>
                      <td colSpan={6} style={{ whiteSpace: 'normal' }}>
                        <div className="grid grid--2">
                          <div><div className="small muted">Before</div><pre className="json">{pretty(r.before_json)}</pre></div>
                          <div><div className="small muted">After</div><pre className="json">{pretty(r.after_json)}</pre></div>
                        </div>
                        <div className="small muted" style={{ marginTop: 6 }}>IP {r.ip ?? '—'} · request {r.request_id}</div>
                      </td>
                    </tr>
                  ) : null}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {meta ? <Pager page={meta.page} totalPages={meta.total_pages} onPage={(p) => update({ page: String(p) })} /> : null}
    </>
  )
}

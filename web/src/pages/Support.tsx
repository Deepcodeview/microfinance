import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { CustomerPicker } from '../components/CustomerPicker'
import { ErrorBanner, Field, Loading, Modal, PageHeader, Pager, Segmented, StatusBadge, bannerError, fieldErrors } from '../components/ui'
import { dateTime, titleCase } from '../format'
import { useFetch, usePagedList } from '../hooks'

interface Row {
  id: string
  request_number: string
  category: string
  subject: string
  status: string
  priority: 'normal' | 'high'
  created_at: string
  updated_at: string
  customer_name: string
  customer_number: string
  assigned_to_name: string | null
  message_count: string
}

interface Detail extends Row {
  customer_id: string
  customer_phone: string
  assigned_to: string | null
  created_by_name: string
  messages: { id: string; body: string; is_staff: string; created_at: string; author_name: string }[]
}

const CATEGORIES = ['payment_issue', 'statement', 'loan_query', 'deposit_query', 'profile_update', 'complaint', 'other']

export function SupportList() {
  const { user } = useAuth()
  const isCustomer = user?.customer_scope === 'own'
  const { rows, meta, error, params, update } = usePagedList<Row>('/service-requests')
  // Shortcuts like "Ask for loan" arrive as ?new=<category>&subject=… and open a prefilled request.
  const preset = params.get('new')
  const [creating, setCreating] = useState(Boolean(preset))
  const navigate = useNavigate()
  const closeNew = () => {
    setCreating(false)
    if (preset) update({ new: '', subject: '' })
  }

  return (
    <>
      <PageHeader
        title={isCustomer ? 'Help & support' : 'Service requests'}
        subtitle={isCustomer ? 'Ask a question or raise a complaint. We reply here and notify you.' : 'Customer questions and complaints. Complaints are marked high priority.'}
        actions={<button type="button" className="btn btn--primary" onClick={() => setCreating(true)}>New request</button>}
      />
      <div className="toolbar">
        <Segmented
          options={[{ id: '', label: 'All' }, { id: 'open', label: 'Open' }, { id: 'in_progress', label: 'In progress' }, { id: 'resolved', label: 'Resolved' }, { id: 'closed', label: 'Closed' }]}
          value={params.get('status') ?? ''}
          onChange={(status) => update({ status })}
        />
        {!isCustomer ? (
          <label className="checkbox"><input type="checkbox" checked={params.get('mine') === '1'} onChange={(e) => update({ mine: e.target.checked ? '1' : '' })} />Assigned to me</label>
        ) : null}
      </div>
      <ErrorBanner error={error} />
      {rows === null && !error ? <Loading /> : null}
      {rows ? (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Request</th><th>Subject</th>{isCustomer ? null : <th>Customer</th>}<th>Category</th><th>Assigned</th><th>Status</th><th>Updated</th></tr></thead>
            <tbody>
              {rows.length === 0 ? (
                <tr><td colSpan={7} className="table__empty">No requests.</td></tr>
              ) : rows.map((r) => (
                <tr key={r.id}>
                  <td><Link to={`/support/${r.id}`} className="mono">{r.request_number}</Link></td>
                  <td>{r.subject}{r.priority === 'high' ? <span className="badge badge--danger" style={{ marginLeft: 6 }}>High</span> : null}</td>
                  {isCustomer ? null : <td>{r.customer_name}</td>}
                  <td>{titleCase(r.category)}</td>
                  <td>{r.assigned_to_name ?? <span className="muted">—</span>}</td>
                  <td><StatusBadge status={r.status} /></td>
                  <td>{dateTime(r.updated_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {meta ? <Pager page={meta.page} totalPages={meta.total_pages} onPage={(p) => update({ page: String(p) })} /> : null}
      {creating ? <NewRequestModal initialCategory={preset ?? undefined} initialSubject={params.get('subject') ?? ''} onClose={closeNew} onCreated={(id) => navigate(`/support/${id}`)} /> : null}
    </>
  )
}

function NewRequestModal({ initialCategory, initialSubject = '', onClose, onCreated }: { initialCategory?: string; initialSubject?: string; onClose: () => void; onCreated: (id: string) => void }) {
  const { user } = useAuth()
  const isCustomer = user?.customer_scope === 'own'
  const [customerId, setCustomerId] = useState('')
  const [category, setCategory] = useState(initialCategory ?? 'loan_query')
  const [subject, setSubject] = useState(initialSubject)
  const [message, setMessage] = useState('')
  const [error, setError] = useState<unknown>(null)
  const errors = fieldErrors(error)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      const sr = await api.post<Detail>('/service-requests', { category, subject, message, customer_id: isCustomer ? undefined : Number(customerId) })
      onCreated(sr.id)
    } catch (err) {
      setError(err)
    }
  }

  return (
    <Modal title="New request" onClose={onClose}>
      <form onSubmit={submit} className="stack">
        <ErrorBanner error={bannerError(error)} />
        {!isCustomer ? <Field label="Customer *" error={errors.customer_id}><CustomerPicker value={customerId} onChange={setCustomerId} /></Field> : null}
        <Field label="Category" error={errors.category}>
          <select value={category} onChange={(e) => setCategory(e.target.value)}>
            {CATEGORIES.map((c) => <option key={c} value={c}>{titleCase(c)}</option>)}
          </select>
        </Field>
        <Field label="Subject *" error={errors.subject}><input value={subject} onChange={(e) => setSubject(e.target.value)} /></Field>
        <Field label="Message *" error={errors.message}><textarea rows={5} value={message} onChange={(e) => setMessage(e.target.value)} /></Field>
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary">Submit</button>
        </div>
      </form>
    </Modal>
  )
}

export function SupportDetail() {
  const { id } = useParams()
  const { user, can } = useAuth()
  const { data: sr, error, setData } = useFetch<Detail>(`/service-requests/${id}`)
  const { data: staff } = useFetch<{ id: string; name: string }[]>(can('support.manage') && can('users.manage') ? '/users?per_page=100&status=active' : null)
  const [reply, setReply] = useState('')
  const [sendError, setSendError] = useState<unknown>(null)
  const isCustomer = user?.customer_scope === 'own'

  if (!sr) return error ? <ErrorBanner error={error} /> : <Loading />

  async function send(e: FormEvent) {
    e.preventDefault()
    setSendError(null)
    try {
      setData(await api.post<Detail>(`/service-requests/${id}/messages`, { message: reply }))
      setReply('')
    } catch (err) {
      setSendError(err)
    }
  }

  async function patch(body: Record<string, unknown>) {
    try {
      setData(await api.patch<Detail>(`/service-requests/${id}`, body))
    } catch (err) {
      setSendError(err)
    }
  }

  return (
    <>
      <PageHeader
        back={{ to: '/support', label: isCustomer ? 'Help & support' : 'Service requests' }}
        title={sr.subject}
        subtitle={<><span className="mono">{sr.request_number}</span><StatusBadge status={sr.status} />{sr.priority === 'high' ? <span className="badge badge--danger">High priority</span> : null}<span>· {titleCase(sr.category)}</span></>}
        actions={can('support.manage') && !isCustomer ? (
          <>
            {sr.status !== 'resolved' && sr.status !== 'closed' ? <button type="button" className="btn btn--primary" onClick={() => patch({ status: 'resolved' })}>Mark resolved</button> : null}
            {sr.status !== 'closed' ? <button type="button" className="btn" onClick={() => patch({ status: 'closed' })}>Close</button> : null}
          </>
        ) : null}
      />
      <div className="grid grid--2-1">
        <section className="card">
          <div className="thread">
            {sr.messages.map((m) => {
              const mine = isCustomer ? m.is_staff === '0' : m.is_staff === '1'
              return (
                <div key={m.id} className={`bubble${mine ? ' bubble--mine' : ''}`}>
                  <div className="bubble__meta">{m.author_name}{m.is_staff === '1' ? ' (staff)' : ''} · {dateTime(m.created_at)}</div>
                  <div className="bubble__body">{m.body}</div>
                </div>
              )
            })}
          </div>
          {sr.status !== 'closed' ? (
            <form onSubmit={send} className="stack" style={{ marginTop: 18 }}>
              <ErrorBanner error={bannerError(sendError)} />
              <Field label="Reply" error={fieldErrors(sendError).message}><textarea value={reply} onChange={(e) => setReply(e.target.value)} /></Field>
              <div className="row" style={{ justifyContent: 'flex-end' }}><button type="submit" className="btn btn--primary" disabled={!reply.trim()}>Send</button></div>
            </form>
          ) : <p className="muted" style={{ marginTop: 16 }}>This request is closed.</p>}
        </section>
        {!isCustomer ? (
          <section className="card">
            <h2 className="card__title" style={{ marginBottom: 8 }}>Details</h2>
            <dl className="details">
              <div className="details__row"><dt>Customer</dt><dd><Link to={`/customers/${sr.customer_id}`}>{sr.customer_name}</Link><div className="small muted">{sr.customer_phone}</div></dd></div>
              <div className="details__row"><dt>Opened</dt><dd>{dateTime(sr.created_at)} by {sr.created_by_name}</dd></div>
              <div className="details__row"><dt>Assigned to</dt><dd>
                {can('support.manage') && staff ? (
                  <select value={sr.assigned_to ?? ''} onChange={(e) => patch({ assigned_to: e.target.value ? Number(e.target.value) : null })}>
                    <option value="">Unassigned</option>
                    {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                  </select>
                ) : sr.assigned_to_name ?? '—'}
                {can('support.manage') && !staff && sr.assigned_to === null ? (
                  <button type="button" className="btn btn--small" style={{ marginTop: 6 }} onClick={() => patch({ assigned_to: user?.id })}>Assign to me</button>
                ) : null}
              </dd></div>
              <div className="details__row"><dt>Priority</dt><dd>
                {can('support.manage') ? (
                  <select value={sr.priority} onChange={(e) => patch({ priority: e.target.value })}><option value="normal">Normal</option><option value="high">High</option></select>
                ) : titleCase(sr.priority)}
              </dd></div>
            </dl>
          </section>
        ) : null}
      </div>
    </>
  )
}

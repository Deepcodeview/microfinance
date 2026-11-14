import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { Details, ErrorBanner, Field, Loading, Modal, Notice, PageHeader, Pager, Segmented, StatusBadge, bannerError, fieldErrors } from '../components/ui'
import { dateTime, money, titleCase } from '../format'
import { usePagedList } from '../hooks'

interface ApprovalRow {
  id: string
  request_number: string
  type: 'payment_reversal' | 'loan_write_off' | 'manual_journal'
  entity_type: string | null
  entity_id: string | null
  amount: string | null
  summary: string
  payload_json: string
  reason: string
  status: string
  requested_by: string
  requested_at: string
  decided_at: string | null
  decision_note: string | null
  requested_by_name: string
  decided_by_name: string | null
}

const PERMISSION: Record<ApprovalRow['type'], string> = {
  payment_reversal: 'approvals.financial',
  manual_journal: 'approvals.financial',
  loan_write_off: 'approvals.write_off',
}

const entityLink = (r: ApprovalRow) =>
  r.entity_type === 'payment' ? `/payments/${r.entity_id}` : r.entity_type === 'loan' ? `/loans/${r.entity_id}` : null

/** Maker-checker inbox: sensitive actions wait here until a different, authorised person decides. */
export function Approvals() {
  const { user, can } = useAuth()
  const [search] = useSearchParams()
  // Default view is the pending queue (server-side filter, so paging stays correct).
  const { rows, meta, error, params, update, reload } = usePagedList<ApprovalRow>(search.get('status') ? '/approvals' : '/approvals?status=pending')
  const [deciding, setDeciding] = useState<ApprovalRow | null>(null)
  const [notice, setNotice] = useState('')
  const status = params.get('status') ?? 'pending'

  return (
    <>
      <PageHeader title="Approvals" subtitle="Reversals, write-offs and manual journals run only after a second authorised person approves." />
      {notice ? <Notice>{notice}</Notice> : null}
      <div className="toolbar">
        <Segmented
          options={[{ id: 'pending', label: 'Pending' }, { id: 'approved', label: 'Approved' }, { id: 'rejected', label: 'Rejected' }, { id: 'cancelled', label: 'Cancelled' }]}
          value={status}
          onChange={(s) => update({ status: s })}
        />
        <select value={params.get('type') ?? ''} onChange={(e) => update({ type: e.target.value })}>
          <option value="">All types</option>
          <option value="payment_reversal">Payment reversal</option>
          <option value="loan_write_off">Loan write-off</option>
          <option value="manual_journal">Manual journal</option>
        </select>
      </div>
      <ErrorBanner error={error} />
      {rows === null && !error ? <Loading /> : null}
      {rows ? (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Request</th><th>Type</th><th>What</th><th className="num">Amount</th><th>Requested</th><th>Status</th><th /></tr></thead>
            <tbody>
              {rows.length === 0 ? (
                <tr><td colSpan={7} className="table__empty">Nothing here.</td></tr>
              ) : rows.map((r) => {
                const mine = Number(r.requested_by) === user?.id && !user?.settings.self_approval
                const canDecide = r.status === 'pending' && !mine && can(PERMISSION[r.type])
                const link = entityLink(r)
                return (
                  <tr key={r.id}>
                    <td className="mono">{r.request_number}</td>
                    <td><span className={`badge ${r.type === 'loan_write_off' ? 'badge--danger' : r.type === 'payment_reversal' ? 'badge--warning' : 'badge--info'}`}>{titleCase(r.type)}</span></td>
                    <td style={{ whiteSpace: 'normal', maxWidth: 360 }}>
                      {link ? <Link to={link}>{r.summary}</Link> : r.summary}
                      <div className="small muted">“{r.reason}”</div>
                    </td>
                    <td className="num">{money(r.amount)}</td>
                    <td>{r.requested_by_name}<div className="small muted">{dateTime(r.requested_at)}</div></td>
                    <td><StatusBadge status={r.status} />{r.decided_by_name ? <div className="small muted">by {r.decided_by_name}</div> : null}</td>
                    <td>
                      {canDecide ? <button type="button" className="btn btn--small btn--primary" onClick={() => setDeciding(r)}>Review</button> : null}
                      {r.status === 'pending' && mine ? (
                        <button type="button" className="btn btn--small btn--ghost" onClick={() => api.post(`/approvals/${r.id}/cancel`).then(reload).catch(() => {})}>Cancel</button>
                      ) : null}
                      {r.status === 'pending' && !mine && !can(PERMISSION[r.type]) ? <span className="small muted">Needs higher authority</span> : null}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      ) : null}
      {meta ? <Pager page={meta.page} totalPages={meta.total_pages} onPage={(p) => update({ page: String(p) })} /> : null}
      {deciding ? (
        <DecideModal
          request={deciding}
          onClose={() => setDeciding(null)}
          onDone={(message) => {
            setDeciding(null)
            setNotice(message)
            reload()
          }}
        />
      ) : null}
    </>
  )
}

function DecideModal({ request, onClose, onDone }: { request: ApprovalRow; onClose: () => void; onDone: (m: string) => void }) {
  const [note, setNote] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const payload = JSON.parse(request.payload_json) as { description?: string; lines?: { account_code: string; debit: string; credit: string }[] }

  async function decide(decision: 'approved' | 'rejected', e?: FormEvent) {
    e?.preventDefault()
    setSaving(true)
    setError(null)
    try {
      await api.post(`/approvals/${request.id}/decide`, { decision, note: note || null })
      onDone(decision === 'approved' ? `${request.request_number} approved and executed.` : `${request.request_number} rejected.`)
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={`Review ${request.request_number}`} onClose={onClose} wide>
      <Details
        rows={[
          ['Type', titleCase(request.type)],
          ['Request', request.summary],
          ['Reason given', request.reason],
          ['Requested by', `${request.requested_by_name}, ${dateTime(request.requested_at)}`],
          ['Amount', money(request.amount)],
        ]}
      />
      {payload.lines ? (
        <table className="table table--compact">
          <thead><tr><th>Account</th><th className="num">Debit</th><th className="num">Credit</th></tr></thead>
          <tbody>
            {payload.lines.map((l, i) => (
              <tr key={i}><td className="mono">{l.account_code}</td><td className="num">{Number(l.debit) ? money(l.debit) : ''}</td><td className="num">{Number(l.credit) ? money(l.credit) : ''}</td></tr>
            ))}
          </tbody>
        </table>
      ) : null}
      {request.type === 'loan_write_off' ? (
        <div className="banner banner--warning">Approving removes the principal from the loan book against the loan-loss provision. The customer still owes the money; later collections are booked as recoveries.</div>
      ) : request.type === 'payment_reversal' ? (
        <div className="banner banner--warning">Approving posts a linked reversal entry and restores the account balance. The original payment stays on record.</div>
      ) : null}
      <ErrorBanner error={bannerError(error)} />
      <Field label="Note (required to reject)" error={fieldErrors(error).note}>
        <textarea value={note} onChange={(e) => setNote(e.target.value)} />
      </Field>
      <div className="modal__footer">
        <button type="button" className="btn btn--danger" disabled={saving} onClick={() => decide('rejected')}>Reject</button>
        <button type="button" className="btn btn--primary" disabled={saving} onClick={() => decide('approved')}>Approve &amp; execute</button>
      </div>
    </Modal>
  )
}

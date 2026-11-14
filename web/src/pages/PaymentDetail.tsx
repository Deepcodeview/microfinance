import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { Details, ErrorBanner, Field, Loading, Modal, Notice, PageHeader, StatusBadge, bannerError, fieldErrors } from '../components/ui'
import { date, dateTime, money, titleCase } from '../format'
import { useFetch } from '../hooks'
import { ProtectedImage } from '../components/ProtectedImage'
import { mapsLink, whatsappReceiptLink } from '../field'
import type { Payment } from '../types'

type Action = 'confirm' | 'fail' | 'reverse' | null

export function PaymentDetail() {
  const { id } = useParams()
  const { user, can } = useAuth()
  const { data: p, error, setData } = useFetch<Payment>(`/payments/${id}`)
  const [action, setAction] = useState<Action>(null)
  const [notice, setNotice] = useState('')

  if (!p) return error ? <ErrorBanner error={error} /> : <Loading />

  const accountLink = p.account_type === 'loan' ? `/loans/${p.loan_id}` : `/deposits/${p.deposit_account_id}`

  return (
    <>
      <PageHeader
        back={{ to: '/payments', label: 'Payments' }}
        title={money(p.amount)}
        subtitle={
          <>
            <span className="mono">{p.payment_number}</span>
            <StatusBadge status={p.status} />
            {p.purpose === 'settlement' ? <span className="badge badge--accent">Settlement</span> : null}
          </>
        }
        actions={
          <>
            {p.status === 'pending' && can('payments.confirm') ? (
              <>
                <button type="button" className="btn btn--danger" onClick={() => setAction('fail')}>
                  Mark failed
                </button>
                <button type="button" className="btn btn--primary" onClick={() => setAction('confirm')}>
                  Confirm
                </button>
              </>
            ) : null}
            {p.status === 'confirmed' && can('payments.reverse') ? (
              <button type="button" className="btn btn--danger" onClick={() => setAction('reverse')}>
                Request reversal
              </button>
            ) : null}
            {p.receipt_number ? (
              <Link to={`/payments/${p.id}/receipt`} className="btn">
                Receipt
              </Link>
            ) : null}
            {p.receipt_number && p.status === 'confirmed' ? (
              <a className="btn whatsapp-btn" style={{ marginTop: 0 }} href={whatsappReceiptLink(p, user?.settings.company_name || 'KBSAVE')} target="_blank" rel="noreferrer">
                WhatsApp
              </a>
            ) : null}
          </>
        }
      />
      {notice ? <Notice>{notice}</Notice> : null}
      {p.status === 'reversed' ? (
        <Notice tone="warning">
          Reversed by {p.reversed_by_name} on {dateTime(p.reversed_at)}: {p.status_reason}. The original record is kept; a correcting ledger entry was posted.
        </Notice>
      ) : null}
      {p.status === 'failed' ? <Notice tone="warning">Marked failed: {p.status_reason}</Notice> : null}

      <div className="grid grid--2">
        <section className="card">
          <h2 className="card__title">Payment</h2>
          <Details
            rows={[
              ['Customer', <Link key="c" to={`/customers/${p.customer_id}`}>{p.customer_name}</Link>],
              ['Account', <Link key="a" to={accountLink} className="mono">{p.account_number}</Link>],
              ['Method', titleCase(p.method)],
              ['Reference', p.reference ? <span key="r" className="mono">{p.reference}</span> : '—'],
              ['Business date', date(p.business_date)],
              ['Collected by', `${p.collected_by_name}, ${dateTime(p.collected_at)}`],
              ['Location', p.latitude && p.longitude
                ? <a key="loc" href={mapsLink(p.latitude, p.longitude)} target="_blank" rel="noreferrer">View on map{p.location_accuracy_m ? ` (±${p.location_accuracy_m} m)` : ''}{p.located_at ? ` · ${dateTime(p.located_at)}` : ''}</a>
                : <span key="loc" className="muted">Not recorded</span>],
              ['Confirmed', p.confirmed_by_name ? `${p.confirmed_by_name}, ${dateTime(p.confirmed_at)}` : '—'],
              ['Cash handover', p.method === 'cash' ? (p.handover_id ? 'Included in handover' : 'Not yet handed over') : 'n/a'],
              ['Notes', p.notes ?? '—'],
            ]}
          />
        </section>
        {p.has_proof ? (
          <section className="card">
            <h2 className="card__title" style={{ marginBottom: 10 }}>Payment proof</h2>
            {p.upi_tr ? <p className="small muted" style={{ marginBottom: 8 }}>QR ref <span className="mono">{p.upi_tr}</span></p> : null}
            <ProtectedImage path={`/payments/${p.id}/proof`} alt={`Payment proof for ${p.payment_number}`} className="proof-image" />
            {p.status === 'pending' && !p.reference ? <p className="small muted" style={{ marginTop: 8 }}>Read the 12-digit UTR from this screenshot and enter it when confirming.</p> : null}
          </section>
        ) : null}
        <section className="card">
          <h2 className="card__title">How it was applied</h2>
          {p.allocations.length === 0 ? (
            <p className="muted">{p.status === 'pending' ? 'Allocated when confirmed.' : 'No allocations.'}</p>
          ) : (
            <table className="table table--compact">
              <thead>
                <tr>
                  <th>Component</th>
                  <th>Installment</th>
                  <th className="num">Amount</th>
                </tr>
              </thead>
              <tbody>
                {p.allocations.map((a, i) => (
                  <tr key={i} className={a.component === 'interest_waived' ? 'is-muted' : undefined}>
                    <td>{titleCase(a.component)}</td>
                    <td>{a.installment_number ? `#${a.installment_number} · ${date(a.due_date)}` : '—'}</td>
                    <td className="num">{money(a.amount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>
      </div>

      {action ? (
        <ActionModal
          payment={p}
          action={action}
          onClose={() => setAction(null)}
          onDone={(updated, message) => {
            setData(updated)
            setAction(null)
            setNotice(message)
          }}
        />
      ) : null}
    </>
  )
}

function ActionModal({
  payment,
  action,
  onClose,
  onDone,
}: {
  payment: Payment
  action: Exclude<Action, null>
  onClose: () => void
  onDone: (p: Payment, message: string) => void
}) {
  const [text, setText] = useState(action === 'confirm' ? payment.reference ?? '' : '')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const errors = fieldErrors(error)

  const config = {
    confirm: { title: 'Confirm payment', field: 'reference', label: payment.method === 'upi' ? 'UTR (12 digits) *' : 'Provider / bank reference', button: 'Confirm & issue receipt', done: 'Payment confirmed and receipt issued.' },
    fail: { title: 'Mark payment failed', field: 'reason', label: 'Reason *', button: 'Mark failed', done: 'Payment marked failed.' },
    reverse: { title: 'Request reversal', field: 'reason', label: 'Reason for reversal *', button: 'Send for approval', done: 'Reversal requested. It takes effect once another authorised person approves it in Approvals.' },
  }[action]

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      onDone(await api.post<Payment>(`/payments/${payment.id}/${action}`, { [config.field]: text || null }), config.done)
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={config.title} onClose={onClose}>
      <form onSubmit={submit} className="stack">
        <p>
          {payment.payment_number} · {money(payment.amount)} from {payment.customer_name}
        </p>
        {action === 'reverse' ? (
          <div className="banner banner--warning">Reversals need a second person's approval. Once approved, the account balance is restored and a correcting ledger entry is posted; the original payment stays on record.</div>
        ) : null}
        <ErrorBanner error={bannerError(error)} />
        <Field label={config.label} error={errors[config.field]}>
          {action === 'confirm' ? <input value={text} onChange={(e) => setText(e.target.value)} /> : <textarea value={text} onChange={(e) => setText(e.target.value)} />}
        </Field>
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" className={`btn ${action === 'confirm' ? 'btn--primary' : 'btn--danger'}`} disabled={saving}>
            {config.button}
          </button>
        </div>
      </form>
    </Modal>
  )
}

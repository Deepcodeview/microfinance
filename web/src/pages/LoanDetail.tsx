import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { RecordPaymentModal } from '../components/RecordPaymentModal'
import { Details, ErrorBanner, Field, Loading, Modal, Notice, PageHeader, Segmented, StatusBadge, Tabs, bannerError, fieldErrors } from '../components/ui'
import { date, dateTime, money, percent, titleCase } from '../format'
import { useFetch } from '../hooks'
import type { Loan, PaymentMethod } from '../types'
import { AssetClassBadge } from './Accounting'

type Dialog = 'approve' | 'reject' | 'disburse' | 'field' | 'pay' | 'writeoff' | null

export function LoanDetail() {
  const { id } = useParams()
  const { user, can } = useAuth()
  const { data: loan, error, setData, reload } = useFetch<Loan>(`/loans/${id}`)
  const [tab, setTab] = useState<'schedule' | 'payments' | 'details'>('schedule')
  const [dialog, setDialog] = useState<Dialog>(null)
  const [notice, setNotice] = useState('')

  if (!loan) return error ? <ErrorBanner error={error} /> : <Loading />

  // Maker-checker, unless single-owner mode lets this Super Admin decide their own application.
  const ownApplication = Number(loan.applied_by) === user?.id && !user?.settings.self_approval
  const pos = loan.position
  const paidShare = pos ? 1 - Number(pos.principal_outstanding) / Number(loan.principal) : 0
  const isCustomer = user?.customer_scope === 'own'

  const actions = (
    <>
      {loan.status === 'pending_approval' && can('loans.approve') && !ownApplication ? (
        <>
          <button type="button" className="btn btn--danger" onClick={() => setDialog('reject')}>
            Reject
          </button>
          <button type="button" className="btn btn--primary" onClick={() => setDialog('approve')}>
            Approve
          </button>
        </>
      ) : null}
      {loan.status === 'approved' && can('loans.disburse') ? (
        <button type="button" className="btn btn--primary" onClick={() => setDialog('disburse')}>
          Disburse {money(loan.net_disbursement)}
        </button>
      ) : null}
      {loan.status === 'approved' && can('loans.disburse_field') && !can('loans.disburse') ? (
        <button type="button" className="btn btn--primary" onClick={() => setDialog('field')}>
          Pay out {money(loan.net_disbursement)} in cash
        </button>
      ) : null}
      {loan.disbursement_date ? (
        <Link to={`/loans/${loan.id}/statement`} className="btn">Statement</Link>
      ) : null}
      {loan.status === 'closed' ? (
        <Link to={`/loans/${loan.id}/certificate`} className="btn">No Dues Certificate</Link>
      ) : null}
      {loan.status === 'active' && ['substandard', 'doubtful', 'loss'].includes(loan.asset_class) && can('loans.write_off') ? (
        <button type="button" className="btn btn--danger" onClick={() => setDialog('writeoff')}>Request write-off</button>
      ) : null}
      {(loan.status === 'active' || loan.status === 'written_off') && can('payments.create') ? (
        <button type="button" className="btn btn--primary" onClick={() => setDialog('pay')}>
          Record payment
        </button>
      ) : null}
    </>
  )

  return (
    <>
      <PageHeader
        back={isCustomer ? { to: '/', label: 'My accounts' } : { to: '/loans', label: 'Loans' }}
        title={
          <>
            {loan.product_name} <span className="mono muted" style={{ fontSize: 18 }}>{loan.loan_number}</span>
          </>
        }
        subtitle={
          <>
            <StatusBadge status={loan.status} />
            {isCustomer ? null : <Link to={`/customers/${loan.customer_id}`}>{loan.customer_name}</Link>}
            <span>· {loan.branch_name}</span>
            {loan.closure_type ? <span>· {titleCase(loan.closure_type)}</span> : null}
            {loan.status === 'active' || loan.status === 'written_off' ? <AssetClassBadge value={loan.asset_class} /> : null}
            {Number(loan.dpd) > 0 ? <span>· {loan.dpd} days past due</span> : null}
          </>
        }
        actions={actions}
      />
      {notice ? <Notice>{notice}</Notice> : null}
      {loan.status === 'written_off' ? (
        <Notice tone="warning">Written off on {dateTime(loan.written_off_at)} ({money(loan.written_off_amount)} principal). The customer still owes this; collections are booked as recoveries.</Notice>
      ) : ['substandard', 'doubtful', 'loss'].includes(loan.asset_class) && loan.status === 'active' ? (
        <Notice tone="warning">Non-performing since {date(loan.npa_since)}. Interest is recognised only when received; the loan returns to Standard once all overdue amounts are cleared.</Notice>
      ) : null}
      {loan.status === 'pending_approval' && ownApplication && can('loans.approve') ? (
        <Notice tone="info">You submitted this application, so another approver must decide on it. A Super Admin running the business alone can allow self-approval in Settings → Security.</Notice>
      ) : null}

      <div className="stats">
        <div className="stat stat--accent">
          <div className="stat__label">Installment</div>
          <div className="stat__value">{money(loan.installment_amount)}</div>
          <div className="stat__hint">
            {loan.tenure} × {loan.terms.repayment_frequency}
          </div>
        </div>
        {pos ? (
          <>
            <div className="stat">
              <div className="stat__label">Total outstanding</div>
              <div className="stat__value">{money(pos.total_outstanding)}</div>
              <div className="progress" style={{ marginTop: 8 }}>
                <div className="progress__bar" style={{ width: `${Math.round(paidShare * 100)}%` }} />
              </div>
            </div>
            <div className="stat">
              <div className="stat__label">Overdue</div>
              <div className="stat__value" style={{ color: Number(pos.overdue_amount) > 0 ? 'var(--danger)' : undefined }}>
                {money(pos.overdue_amount)}
              </div>
              <div className="stat__hint">{pos.days_past_due > 0 ? `${pos.days_past_due} days past due` : 'Up to date'}</div>
            </div>
            <div className="stat">
              <div className="stat__label">Next installment</div>
              <div className="stat__value">{pos.next_installment ? money(pos.next_installment.amount) : '—'}</div>
              <div className="stat__hint">{pos.next_installment ? date(pos.next_installment.due_date) : 'None'}</div>
            </div>
          </>
        ) : (
          <>
            <div className="stat">
              <div className="stat__label">Principal</div>
              <div className="stat__value">{money(loan.principal)}</div>
            </div>
            <div className="stat">
              <div className="stat__label">Customer receives</div>
              <div className="stat__value">{money(loan.net_disbursement)}</div>
              <div className="stat__hint">after fee {money(loan.processing_fee)} + GST {money(loan.tax_on_fee)}</div>
            </div>
          </>
        )}
      </div>

      <Tabs
        tabs={[
          { id: 'schedule', label: 'Schedule' },
          { id: 'payments', label: `Payments (${loan.payments.length})` },
          { id: 'details', label: 'Details' },
        ]}
        value={tab}
        onChange={setTab}
      />

      {tab === 'schedule' ? (
        loan.installments.length === 0 ? (
          <div className="card muted">The repayment schedule is created when the loan is disbursed.</div>
        ) : (
          <div className="table-wrap">
            <table className="table table--compact">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Due date</th>
                  <th className="num">Principal</th>
                  <th className="num">Interest</th>
                  <th className="num">Late fee</th>
                  <th className="num">Paid</th>
                  <th className="num">Balance due</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {loan.installments.map((i) => {
                  const due = Number(i.principal_due) + Number(i.interest_due) - Number(i.interest_waived) + Number(i.late_fee_due)
                  const paid = Number(i.principal_paid) + Number(i.interest_paid) + Number(i.late_fee_paid)
                  const overdue = i.status !== 'paid' && i.due_date < loan.business_date
                  return (
                    <tr key={i.id} className={overdue ? 'is-highlight' : i.status === 'paid' ? 'is-muted' : undefined}>
                      <td>{i.number}</td>
                      <td>{date(i.due_date)}</td>
                      <td className="num">{money(i.principal_due)}</td>
                      <td className="num">
                        {money(i.interest_due)}
                        {Number(i.interest_waived) > 0 ? <div className="small muted">waived {money(i.interest_waived)}</div> : null}
                      </td>
                      <td className="num">{Number(i.late_fee_due) > 0 ? money(i.late_fee_due) : '—'}</td>
                      <td className="num">{money(String(paid))}</td>
                      <td className="num">{money(String(Math.max(0, due - paid)))}</td>
                      <td>
                        <StatusBadge status={overdue ? 'overdue' : i.status} />
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )
      ) : null}

      {tab === 'payments' ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Payment</th>
                <th>Date</th>
                <th>Method</th>
                <th>Collected by</th>
                <th>Status</th>
                <th className="num">Amount</th>
                <th>Receipt</th>
              </tr>
            </thead>
            <tbody>
              {loan.payments.length === 0 ? (
                <tr>
                  <td colSpan={7} className="table__empty">
                    No payments yet.
                  </td>
                </tr>
              ) : (
                loan.payments.map((p) => (
                  <tr key={p.id} className={p.status === 'reversed' ? 'is-muted' : undefined}>
                    <td>
                      <Link to={`/payments/${p.id}`} className="mono">
                        {p.payment_number}
                      </Link>
                      {p.purpose === 'settlement' ? <span className="badge badge--accent" style={{ marginLeft: 6 }}>Settlement</span> : null}
                    </td>
                    <td>{date(p.business_date)}</td>
                    <td>{titleCase(p.method)}</td>
                    <td>{p.collected_by_name}</td>
                    <td>
                      <StatusBadge status={p.status} />
                    </td>
                    <td className="num">{money(p.amount)}</td>
                    <td>{p.receipt_number ? <Link to={`/payments/${p.id}/receipt`}>{p.receipt_number}</Link> : '—'}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      ) : null}

      {tab === 'details' ? (
        <div className="grid grid--2">
          <section className="card">
            <h2 className="card__title">Terms (version {loan.product_version})</h2>
            <Details
              rows={[
                ['Principal', money(loan.principal)],
                ['Interest', `${percent(loan.terms.annual_interest_rate)} p.a. ${titleCase(loan.terms.interest_method)}`],
                ['Total interest', money(loan.total_interest)],
                ['Processing fee', `${money(loan.processing_fee)} + GST ${money(loan.tax_on_fee)}`],
                ['Late fee', loan.terms.late_fee_type === 'fixed' ? `${money(loan.terms.late_fee_value)} after ${loan.terms.grace_period_days} days` : `${percent(loan.terms.late_fee_value)} of overdue after ${loan.terms.grace_period_days} days`],
                ['Prepayment', loan.terms.prepayment_allowed ? `Allowed, charge ${percent(loan.terms.prepayment_charge_percent)}` : 'Not allowed'],
                ['Payment order', loan.terms.allocation_order.map(titleCase).join(' → ')],
                ['Purpose', loan.purpose ?? '—'],
              ]}
            />
          </section>
          <section className="card">
            <h2 className="card__title">Timeline</h2>
            <ul className="timeline" style={{ marginTop: 8 }}>
              <li>
                Applied by {loan.applied_by_name}
                <div className="small muted">{dateTime(loan.applied_at)}</div>
              </li>
              <li className={loan.decided_at ? '' : 'is-pending'}>
                {loan.decided_at ? `${titleCase(loan.status === 'rejected' ? 'rejected' : 'approved')} by ${loan.decided_by_name}` : 'Awaiting decision'}
                {loan.decision_reason ? <div className="small">“{loan.decision_reason}”</div> : null}
                <div className="small muted">{dateTime(loan.decided_at)}</div>
              </li>
              {loan.status !== 'rejected' ? (
                <li className={loan.disbursed_at ? '' : 'is-pending'}>
                  {loan.migrated_at ? `Brought in from the old register by ${loan.disbursed_by_name} (${money(loan.opening_collected ?? '0')} received before)` : loan.disbursed_at ? `Disbursed by ${loan.disbursed_by_name} via ${titleCase(loan.disbursement_method ?? '')}` : 'Awaiting disbursement'}
                  {loan.disbursement_reference ? <div className="small mono">{loan.disbursement_reference}</div> : null}
                  <div className="small muted">{dateTime(loan.disbursed_at)}</div>
                </li>
              ) : null}
              {loan.closed_at ? (
                <li>
                  Closed ({titleCase(loan.closure_type ?? '')})
                  <div className="small muted">{dateTime(loan.closed_at)}</div>
                </li>
              ) : null}
            </ul>
            {loan.settlement_quote?.allowed ? (
              <>
                <h3 className="card__subtitle">Settle today</h3>
                <Details
                  compact
                  rows={[
                    ['Dues to date', money(loan.settlement_quote.dues_to_date)],
                    ['Remaining principal', money(loan.settlement_quote.future_principal)],
                    ['Prepayment charge', money(loan.settlement_quote.prepayment_charge)],
                    ['Future interest waived', money(loan.settlement_quote.interest_waived)],
                    [<strong key="l">Settlement amount</strong>, <strong key="v">{money(loan.settlement_quote.settlement_amount)}</strong>],
                  ]}
                />
              </>
            ) : null}
          </section>
        </div>
      ) : null}

      {dialog === 'approve' || dialog === 'reject' ? (
        <DecisionModal
          loan={loan}
          decision={dialog}
          onClose={() => setDialog(null)}
          onDone={(l) => {
            setData(l)
            setDialog(null)
            setNotice(`Loan ${dialog === 'approve' ? 'approved' : 'rejected'}.`)
          }}
        />
      ) : null}
      {dialog === 'field' ? (
        <FieldPayoutModal
          loan={loan}
          onClose={() => setDialog(null)}
          onDone={(l) => {
            setData(l)
            setDialog(null)
            setNotice('Loan paid out. Give the customer the cash and their first due date; it is part of your day-end handover.')
          }}
        />
      ) : null}
      {dialog === 'disburse' ? (
        <DisburseModal
          loan={loan}
          onClose={() => setDialog(null)}
          onDone={(l) => {
            setData(l)
            setDialog(null)
            setNotice('Loan disbursed and repayment schedule created.')
          }}
        />
      ) : null}
      {dialog === 'writeoff' ? (
        <WriteOffModal
          loan={loan}
          onClose={() => setDialog(null)}
          onDone={() => {
            setDialog(null)
            setNotice('Write-off requested. It takes effect once an Admin approves it in Approvals.')
          }}
        />
      ) : null}
      {dialog === 'pay' ? (
        <RecordPaymentModal
          target={{
            accountType: 'loan',
            accountId: loan.id,
            accountNumber: loan.loan_number,
            customerName: loan.customer_name,
            suggestedAmount: pos && Number(pos.due_now) > 0 ? pos.due_now : pos?.next_installment?.amount,
            settlementAmount: loan.settlement_quote?.allowed ? loan.settlement_quote.settlement_amount : undefined,
          }}
          onClose={() => setDialog(null)}
          onRecorded={() => reload()}
        />
      ) : null}
    </>
  )
}

function DecisionModal({ loan, decision, onClose, onDone }: { loan: Loan; decision: 'approve' | 'reject'; onClose: () => void; onDone: (l: Loan) => void }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      onDone(await api.post<Loan>(`/loans/${loan.id}/${decision}`, { reason: reason || null }))
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={decision === 'approve' ? 'Approve loan' : 'Reject loan'} onClose={onClose}>
      <form onSubmit={submit} className="stack">
        <p>
          {loan.customer_name} · {money(loan.principal)} over {loan.tenure} installments of {money(loan.installment_amount)}.
        </p>
        <ErrorBanner error={bannerError(error)} />
        <Field label={decision === 'reject' ? 'Reason *' : 'Note'} error={fieldErrors(error).reason}>
          <textarea value={reason} onChange={(e) => setReason(e.target.value)} />
        </Field>
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" className={`btn ${decision === 'approve' ? 'btn--primary' : 'btn--danger'}`} disabled={saving}>
            {decision === 'approve' ? 'Approve' : 'Reject'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function WriteOffModal({ loan, onClose, onDone }: { loan: Loan; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState<unknown>(null)
  async function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.post(`/loans/${loan.id}/write-off`, { reason })
      onDone()
    } catch (err) {
      setError(err)
    }
  }
  return (
    <Modal title="Request write-off" onClose={onClose}>
      <form onSubmit={submit} className="stack">
        <p>{loan.customer_name} · {loan.loan_number} · {loan.dpd} days past due · principal outstanding {money(loan.position?.principal_outstanding)}</p>
        <div className="banner banner--warning">A write-off removes the principal from the loan book against the loan-loss provision. It needs approval from an Admin or Super Admin. Recovery efforts continue.</div>
        <ErrorBanner error={bannerError(error)} />
        <Field label="Reason and recovery steps taken *" error={fieldErrors(error).reason}><textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={4} /></Field>
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--danger">Submit for approval</button>
        </div>
      </form>
    </Modal>
  )
}

/** Agent pays an approved loan out in cash in the field, from their float / collected cash. */
function FieldPayoutModal({ loan, onClose, onDone }: { loan: Loan; onClose: () => void; onDone: (l: Loan) => void }) {
  const { data: cash } = useFetch<{ position: { in_hand: string } }>('/cash/my-summary')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const inHand = cash ? Number(cash.position.in_hand) : null
  const enough = inHand !== null && inHand >= Number(loan.net_disbursement)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      onDone(await api.post<Loan>(`/loans/${loan.id}/disburse-field`))
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title="Pay out loan in cash" onClose={onClose}>
      <form onSubmit={submit} className="stack">
        <div className="highlight">
          <div className="highlight__label">Give the customer</div>
          <div className="highlight__value">{money(loan.net_disbursement)}</div>
        </div>
        <Details compact rows={[
          ['Customer', loan.customer_name],
          ['Loan', `${loan.loan_number} · ${money(loan.principal)} less fee ${money(String(Number(loan.processing_fee) + Number(loan.tax_on_fee)))}`],
          ['Cash you hold', inHand === null ? '…' : money(String(inHand))],
        ]} />
        {inHand !== null && !enough ? <Notice tone="warning">You don't hold enough cash. Ask the branch for a cash float first.</Notice> : null}
        <ErrorBanner error={error} />
        <p className="small muted">Count the cash in front of the customer. This amount comes off what you hand over at day end.</p>
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={saving || !enough}>{saving ? 'Paying out…' : 'Paid out — confirm'}</button>
        </div>
      </form>
    </Modal>
  )
}

function DisburseModal({ loan, onClose, onDone }: { loan: Loan; onClose: () => void; onDone: (l: Loan) => void }) {
  const [method, setMethod] = useState<PaymentMethod>('upi')
  const [reference, setReference] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      onDone(await api.post<Loan>(`/loans/${loan.id}/disburse`, { method, reference: reference || null }))
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title="Disburse loan" onClose={onClose}>
      <form onSubmit={submit} className="stack">
        <div className="highlight">
          <div className="highlight__label">Pay to {loan.customer_name}</div>
          <div className="highlight__value">{money(loan.net_disbursement)}</div>
        </div>
        <ErrorBanner error={bannerError(error)} />
        <Field label="Method">
          <Segmented
            options={[
              { id: 'upi', label: 'UPI' },
              { id: 'cash', label: 'Cash' },
            ]}
            value={method}
            onChange={setMethod}
          />
        </Field>
        {method !== 'cash' ? (
          <Field label="UTR / UPI reference *" error={fieldErrors(error).reference} hint="From the UPI app after sending money to the customer.">
            <input value={reference} onChange={(e) => setReference(e.target.value)} />
          </Field>
        ) : null}
        <p className="small muted">This posts the disbursement to the ledger and creates the repayment schedule starting today. It cannot be undone.</p>
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" className="btn btn--primary" disabled={saving}>
            {saving ? 'Disbursing…' : 'Confirm disbursement'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

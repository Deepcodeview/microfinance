import { locale } from '../i18n'
import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, isNetworkError } from '../api'
import { IS_APP } from '../config'
import { Icons } from '../components/icons'
import { cacheWorklist, loadWorklist } from '../offline'
import { useAuth } from '../auth'
import { RecordPaymentModal, type PaymentTarget } from '../components/RecordPaymentModal'
import { Empty, ErrorBanner, Field, Loading, Modal, PageHeader, Tabs, bannerError, fieldErrors } from '../components/ui'
import { getLocation } from '../field'
import { date, money, titleCase } from '../format'
import { useFetch } from '../hooks'
import { periodCount } from '../plans'

interface LoanDue {
  loan_id: string
  loan_number: string
  installment_amount: string
  customer_id: string
  full_name: string
  customer_number: string
  phone: string
  address_line: string
  city: string
  amount_due: string
  oldest_due_date: string
  overdue_installments: string
  last_payment_date: string | null
  paid_today: string
  days_past_due: number
}

interface DepositDue {
  frequency: 'daily' | 'weekly' | 'monthly'
  /** Set once this period's collection is taken; savers are collected once per period. */
  collected_on: string | null
  installments_due: number
  /** Past periods not paid (today's not counted) and the oldest unpaid one. */
  missed_installments: number
  unpaid_since: string | null
  last_paid_on: string | null
  amount_due: string
  next_due_date: string
  paid_this_period: string
  deposit_account_id: string
  deposit_number: string
  installment_amount: string
  customer_id: string
  full_name: string
  customer_number: string
  phone: string
}

const PERIOD_LABEL: Record<DepositDue['frequency'], string> = { daily: 'today', weekly: 'this week', monthly: 'this month' }

interface Visit {
  id: string
  customer_id: string
  outcome: string
  promise_date: string | null
  notes: string | null
  full_name: string
}

interface Worklist {
  business_date: string
  loans: LoanDue[]
  deposits: DepositDue[]
  visits: Visit[]
  collected_today: { method: string; count: string; total: string }[]
  totals: { due: string; collected: string }
}

export function Collections() {
  const { user, can } = useAuth()
  // Agents who may pay out loans in the field: approved loans of their customers waiting for cash.
  const { data: toPayOut } = useFetch<{ id: string; loan_number: string; customer_name: string; net_disbursement: string }[]>(
    IS_APP && can('loans.disburse_field') ? '/loans?status=approved&per_page=20' : null,
  )
  const { data: live, error, reload } = useFetch<Worklist>('/collections/today')
  const offline = isNetworkError(error)
  const [cached, setCached] = useState<{ data: Worklist; savedAt: string } | null | undefined>(undefined)
  // Keep an encrypted copy of today's list so the agent can keep working without a signal.
  useEffect(() => {
    if (live) cacheWorklist(live, live.business_date).catch(() => {})
  }, [live])
  useEffect(() => {
    if (offline) loadWorklist<Worklist>().then(setCached).catch(() => setCached(null))
  }, [offline])
  const data = live ?? (offline ? cached?.data : undefined)
  const [tab, setTab] = useState<'loans' | 'deposits' | 'visits'>('loans')
  const [paying, setPaying] = useState<PaymentTarget | null>(null)
  const [visiting, setVisiting] = useState<LoanDue | null>(null)

  if (!data) {
    if (offline && cached === null) return <ErrorBanner error={new Error("You're offline and no collection list was saved on this device today. Open this page once while online.")} />
    return error && !offline ? <ErrorBanner error={error} /> : <Loading />
  }

  const visitedToday = new Set(data.visits.map((v) => v.customer_id))
  const pending = data.loans.filter((l) => Number(l.paid_today) === 0)

  return (
    <>
      <PageHeader
        title="Today's collections"
        subtitle={new Date(`${data.business_date}T00:00:00`).toLocaleDateString(locale(), { weekday: 'long', day: 'numeric', month: 'long' })}
        actions={
          <>
            <Link to="/map" className="btn"><Icons.pin /> Map</Link>
            {can('cash.handover') ? (
              <Link to="/cash" className="btn">
                Day-end handover
              </Link>
            ) : null}
          </>
        }
      />

      {!live && cached ? (
        <div className="banner banner--warning">Offline — showing the list saved at {new Date(cached.savedAt).toLocaleTimeString(locale())}. Amounts may have changed; payments you record now sync later.</div>
      ) : null}
      {IS_APP ? (
        <>
          <AgentHero data={data} pendingVisits={pending.filter((l) => !visitedToday.has(l.customer_id)).length} />
          <nav className={user?.settings.deposits_enabled && can('applications.review') ? 'quick-actions quick-actions--4' : 'quick-actions'} aria-label="Shortcuts">
            {can('customers.create') ? <Link to="/customers/new" className="quick-action"><Icons.users />New customer</Link> : null}
            {can('loans.apply') ? <Link to="/loans/new" className="quick-action"><Icons.loan />Loan request</Link> : null}
            {can('deposits.open') && user?.settings.deposits_enabled ? <Link to="/deposits/new" className="quick-action"><Icons.piggy />Open savings</Link> : null}
            {can('applications.review') ? <Link to="/applications" className="quick-action"><Icons.file />Applications</Link> : null}
          </nav>
        </>
      ) : null}
      {toPayOut && toPayOut.length > 0 ? (
        <section className="card payout-ready">
          <h2 className="card__title">Ready to pay out ({toPayOut.length})</h2>
          {toPayOut.map((l) => (
            <Link key={l.id} to={`/loans/${l.id}`} className="row row--between payout-ready__row">
              <span>{l.customer_name} <span className="mono small muted">{l.loan_number}</span></span>
              <strong>{money(l.net_disbursement)}</strong>
            </Link>
          ))}
        </section>
      ) : null}
      <div className="stats" style={IS_APP ? { display: 'none' } : undefined}>
        <div className="stat stat--accent">
          <div className="stat__label">Due on your list</div>
          <div className="stat__value">{money(data.totals.due)}</div>
          <div className="stat__hint">{data.loans.length} loans</div>
        </div>
        <div className="stat">
          <div className="stat__label">Collected today</div>
          <div className="stat__value">{money(data.totals.collected)}</div>
          <div className="stat__hint">{data.collected_today.map((c) => `${titleCase(c.method)} ${money(c.total)}`).join(' · ') || 'Nothing yet'}</div>
        </div>
        <div className="stat">
          <div className="stat__label">Still to visit</div>
          <div className="stat__value">{pending.filter((l) => !visitedToday.has(l.customer_id)).length}</div>
          <div className="stat__hint">{data.visits.length} visits logged</div>
        </div>
      </div>

      <Tabs
        tabs={[
          { id: 'loans', label: `Loan dues (${data.loans.length})` },
          { id: 'deposits', label: `Savings due (${data.deposits.filter((d) => !d.collected_on).length})` },
          { id: 'visits', label: `Visits (${data.visits.length})` },
        ]}
        value={tab}
        onChange={setTab}
      />

      {tab === 'loans' ? (
        data.loans.length === 0 ? (
          <div className="card">
            <Empty title="Nothing due today">No installments are due or overdue for your customers.</Empty>
          </div>
        ) : (
          <div className="collect-list">
            {data.loans.map((l) => (
              <div key={l.loan_id} className="collect-card">
                <div className="collect-card__who">
                  <span className="collect-card__avatar">{l.full_name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase()}</span>
                  <div style={{ minWidth: 0 }}>
                  <div className="collect-card__name">{l.full_name}</div>
                  <div className="small muted">
                    <a href={`tel:${l.phone}`}>{l.phone}</a> · {l.address_line}, {l.city}
                  </div>
                  <div className="row row--wrap small" style={{ marginTop: 6 }}>
                    <Link to={`/loans/${l.loan_id}`} className="mono">
                      {l.loan_number}
                    </Link>
                    {l.days_past_due > 0 ? <span className="badge badge--danger">{l.days_past_due} days overdue</span> : <span className="badge badge--warning">Due today</span>}
                    {Number(l.paid_today) > 0 ? <span className="badge badge--success">Paid {money(l.paid_today)} today</span> : null}
                    {visitedToday.has(l.customer_id) ? <span className="badge">Visited</span> : null}
                    <span className="muted">Last paid {date(l.last_payment_date)}</span>
                  </div>
                  </div>
                </div>
                <div>
                  <div className="small muted" style={{ textAlign: 'right' }}>
                    Due
                  </div>
                  <div className="collect-card__amount">{money(l.amount_due)}</div>
                </div>
                <div className="collect-card__actions">
                  {can('payments.create') ? (
                    <button
                      type="button"
                      className="btn btn--primary btn--small"
                      onClick={() =>
                        setPaying({
                          accountType: 'loan',
                          accountId: l.loan_id,
                          accountNumber: l.loan_number,
                          customerName: l.full_name,
                          suggestedAmount: l.amount_due,
                        })
                      }
                    >
                      Collect
                    </button>
                  ) : null}
                  {can('visits.create') ? (
                    <button type="button" className="btn btn--small" onClick={() => setVisiting(l)}>
                      Log visit
                    </button>
                  ) : null}
                  <a className="btn btn--small call-btn" href={`tel:${l.phone}`} aria-label={`Call ${l.full_name}`}>
                    Call
                  </a>
                </div>
              </div>
            ))}
          </div>
        )
      ) : null}

      {tab === 'deposits' ? (
        data.deposits.length === 0 ? (
          <div className="card">
            <Empty title="No recurring deposits">Daily savings accounts for your customers appear here.</Empty>
          </div>
        ) : (
          <div className="collect-list">
            {data.deposits.map((d) => (
              <div key={d.deposit_account_id} className="collect-card">
                <div style={{ minWidth: 0 }}>
                  <div className="collect-card__name">{d.full_name}</div>
                  <div className="row row--wrap small" style={{ marginTop: 4 }}>
                    <Link to={`/deposits/${d.deposit_account_id}`} className="mono">
                      {d.deposit_number}
                    </Link>
                    <span className="badge badge--info">{titleCase(d.frequency)}</span>
                    {d.collected_on ? (
                      <span className="badge badge--success">Collected {money(d.paid_this_period)} on {date(d.collected_on)}</span>
                    ) : (
                      <span className="badge badge--warning">Due {PERIOD_LABEL[d.frequency]}</span>
                    )}
                    {d.missed_installments > 0 ? <span className="badge badge--danger">Not paid for {periodCount(d.missed_installments, d.frequency)}</span> : null}
                  </div>
                  {d.collected_on ? <div className="small muted" style={{ marginTop: 4 }}>Next collection from {date(d.next_due_date)}</div> : null}
                  {!d.collected_on && d.missed_installments > 0 ? (
                    <div className="small muted" style={{ marginTop: 4 }}>
                      Unpaid since {date(d.unpaid_since)} · {d.installments_due} × {money(d.installment_amount)}{d.last_paid_on ? ` · last paid ${date(d.last_paid_on)}` : ' · nothing paid yet'}
                    </div>
                  ) : null}
                  {!d.collected_on && Number(d.paid_this_period) > 0 ? <div className="small muted">{money(d.paid_this_period)} already collected {PERIOD_LABEL[d.frequency]}</div> : null}
                </div>
                <div className="collect-card__amount">{money(d.collected_on ? d.installment_amount : d.amount_due)}</div>
                <div className="collect-card__actions">
                  {can('payments.create') && !d.collected_on ? (
                    <button
                      type="button"
                      className="btn btn--primary btn--small"
                      onClick={() =>
                        setPaying({
                          accountType: 'deposit',
                          accountId: d.deposit_account_id,
                          accountNumber: d.deposit_number,
                          customerName: d.full_name,
                          suggestedAmount: d.amount_due,
                        })
                      }
                    >
                      Collect
                    </button>
                  ) : null}
                </div>
              </div>
            ))}
          </div>
        )
      ) : null}

      {tab === 'visits' ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Customer</th>
                <th>Outcome</th>
                <th>Promise date</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              {data.visits.length === 0 ? (
                <tr>
                  <td colSpan={4} className="table__empty">
                    No visits logged today.
                  </td>
                </tr>
              ) : (
                data.visits.map((v) => (
                  <tr key={v.id}>
                    <td>{v.full_name}</td>
                    <td>{titleCase(v.outcome)}</td>
                    <td>{date(v.promise_date)}</td>
                    <td style={{ whiteSpace: 'normal' }}>{v.notes ?? '—'}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      ) : null}

      {paying ? <RecordPaymentModal target={paying} onClose={() => setPaying(null)} onRecorded={reload} /> : null}
      {visiting ? (
        <VisitModal
          loan={visiting}
          businessDate={data.business_date}
          onClose={() => setVisiting(null)}
          onDone={() => {
            setVisiting(null)
            reload()
          }}
        />
      ) : null}
    </>
  )
}

function VisitModal({ loan, businessDate, onClose, onDone }: { loan: LoanDue; businessDate: string; onClose: () => void; onDone: () => void }) {
  const [outcome, setOutcome] = useState('promised')
  const [promiseDate, setPromiseDate] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const errors = fieldErrors(error)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      // Agent app: record where the visit happened (skipped quietly if GPS is off).
      const location = IS_APP ? await getLocation(6000) : null
      await api.post('/visits', {
        ...(location ? { location } : {}),
        customer_id: Number(loan.customer_id),
        loan_id: Number(loan.loan_id),
        outcome,
        promise_date: outcome === 'promised' ? promiseDate : null,
        notes: notes || null,
      })
      onDone()
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title="Log visit" onClose={onClose}>
      <form onSubmit={submit} className="stack">
        <p>
          {loan.full_name} · <span className="mono">{loan.loan_number}</span>
        </p>
        <ErrorBanner error={bannerError(error)} />
        <Field label="Outcome" error={errors.outcome}>
          <select value={outcome} onChange={(e) => setOutcome(e.target.value)}>
            <option value="promised">Promised to pay</option>
            <option value="not_available">Customer not available</option>
            <option value="refused">Refused to pay</option>
            <option value="partial">Paid partially</option>
            <option value="paid">Paid in full</option>
            <option value="other">Other</option>
          </select>
        </Field>
        {outcome === 'promised' ? (
          <Field label="Promised payment date" error={errors.promise_date}>
            <input type="date" min={businessDate} value={promiseDate} onChange={(e) => setPromiseDate(e.target.value)} />
          </Field>
        ) : null}
        <Field label="Notes" error={errors.notes}>
          <textarea value={notes} onChange={(e) => setNotes(e.target.value)} />
        </Field>
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" className="btn btn--primary" disabled={saving}>
            Save visit
          </button>
        </div>
      </form>
    </Modal>
  )
}

/** Phone home: one glanceable card — collected vs due, with the day's progress. */
function AgentHero({ data, pendingVisits }: { data: Worklist; pendingVisits: number }) {
  const collected = Number(data.totals.collected)
  const due = Number(data.totals.due)
  const share = collected + due > 0 ? Math.min(100, Math.round((collected / (collected + due)) * 100)) : 0
  const cash = data.collected_today.find((c) => c.method === 'cash')
  return (
    <section className="hero" aria-label="Today's progress">
      <div className="hero__label">Collected today</div>
      <div className="hero__value">{money(data.totals.collected)}</div>
      <div className="hero__sub">{data.collected_today.reduce((n, c) => n + Number(c.count), 0)} receipts · cash in hand {money(cash?.total ?? '0')}</div>
      <div className="hero__bar" role="progressbar" aria-valuenow={share} aria-valuemin={0} aria-valuemax={100}><div style={{ width: `${share}%` }} /></div>
      <div className="hero__row">
        <div><span style={{ opacity: 0.85 }}>Still due</span><strong>{money(data.totals.due)}</strong></div>
        <div><span style={{ opacity: 0.85 }}>Loans</span><strong>{data.loans.length}</strong></div>
        <div><span style={{ opacity: 0.85 }}>To visit</span><strong>{pendingVisits}</strong></div>
      </div>
    </section>
  )
}

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { LoanCard, SavingsCard } from '../components/AccountCards'
import { Documents } from '../components/Documents'
import { RecordPaymentModal, type PaymentTarget } from '../components/RecordPaymentModal'
import { useAuth } from '../auth'
import { Details, ErrorBanner, Field, Loading, Modal, Notice, PageHeader, StatusBadge, bannerError, fieldErrors } from '../components/ui'
import { date, dateTime, money, titleCase } from '../format'
import { IS_APP } from '../config'
import { getLocation, mapsLink } from '../field'
import type { Agent, Customer } from '../types'

export function CustomerDetail() {
  const { id } = useParams()
  const [params] = useSearchParams()
  // Just added: KYC capture comes first so the agent takes the photo and IDs before leaving.
  const justAdded = params.get('kyc') === '1'
  const { user, can } = useAuth()
  const [customer, setCustomer] = useState<Customer | null>(null)
  const [agents, setAgents] = useState<Agent[]>([])
  const [agentId, setAgentId] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [assigning, setAssigning] = useState(false)
  const [portalOpen, setPortalOpen] = useState(false)
  const [notice, setNotice] = useState('')
  const [paying, setPaying] = useState<PaymentTarget | null>(null)
  const [locating, setLocating] = useState(false)

  const reload = useCallback(() => {
    api.get<Customer>(`/customers/${id}`).then(setCustomer).catch(setError)
  }, [id])

  useEffect(() => {
    reload()
    if (can('customers.assign')) api.get<Agent[]>('/agents').then(setAgents).catch(setError)
  }, [reload, can])

  async function assign() {
    setAssigning(true)
    setError(null)
    try {
      setCustomer(await api.post<Customer>(`/customers/${id}/assign-agent`, { agent_user_id: Number(agentId) }))
      setAgentId('')
      setNotice('Agent assigned.')
    } catch (err) {
      setError(err)
    } finally {
      setAssigning(false)
    }
  }

  /** Pin the customer's home to where the phone is now (the agent is standing at their door). */
  async function setLocationHere() {
    setLocating(true)
    setError(null)
    try {
      const here = await getLocation(15000)
      if (!here) throw new Error('Could not read your location. Turn on GPS (location) and allow it for this app, then try again.')
      setCustomer(await api.post<Customer>(`/customers/${id}/location`, { location: here }))
      setNotice(`Location saved (±${here.accuracy} m).`)
    } catch (err) {
      setError(err)
    } finally {
      setLocating(false)
    }
  }

  if (!customer) return error ? <ErrorBanner error={error} /> : <Loading />

  const current = customer.assignments.find((a) => a.valid_to === null)
  const branchAgents = agents.filter((a) => a.branch_id === customer.branch_id && a.id !== current?.agent_user_id)
  const depositsOn = user?.settings.deposits_enabled ?? false
  const canCollect = can('payments.create') && customer.status === 'active'
  const today = user?.settings.business_date ?? new Date().toISOString().slice(0, 10)
  const loanTotal = customer.loans.filter((l) => l.status === 'active').reduce((sum, l) => sum + Number(l.outstanding), 0)
  const overdueTotal = customer.loans.reduce((sum, l) => sum + Number(l.overdue), 0)
  const savingsTotal = customer.deposits.filter((d) => d.status === 'active').reduce((sum, d) => sum + Number(d.principal_balance), 0)

  return (
    <>
      <PageHeader
        back={{ to: '/customers', label: 'Customers' }}
        title={customer.full_name}
        subtitle={
          <>
            <span className="mono">{customer.customer_number}</span>
            <StatusBadge status={customer.status} />
            <span>· {customer.branch_name}</span>
          </>
        }
        actions={
          <>
            {can('customers.update') ? (
              <Link to={`/customers/${customer.id}/edit`} className="btn">
                Edit
              </Link>
            ) : null}
          </>
        }
      />
      {notice ? <Notice>{notice}</Notice> : null}
      <ErrorBanner error={error} />
      {justAdded ? (
        <>
          <Notice>Customer added. Take their photo, Aadhaar and PAN now.</Notice>
          <div style={{ marginBottom: 16 }}><Documents customerId={customer.id} quickCapture /></div>
        </>
      ) : null}
      {customer.status === 'pending' ? (
        <Notice tone="warning">
          Registered in the customer app — waiting for approval. Check their KYC below, then{' '}
          {can('customers.approve') ? <Link to="/applications?tab=registrations">approve or reject the registration</Link> : 'ask a manager to approve the registration'}.
        </Notice>
      ) : null}
      {customer.status === 'blocked' && customer.registration_note ? <Notice tone="warning">Registration rejected: {customer.registration_note}</Notice> : null}

      <div className="summary-strip">
        <div><span className="small muted">Loan outstanding</span><strong>{money(String(loanTotal))}</strong></div>
        <div className={overdueTotal > 0 ? 'is-late' : ''}><span className="small muted">Overdue</span><strong>{money(String(overdueTotal))}</strong></div>
        <div className="is-savings"><span className="small muted">Savings balance</span><strong>{money(String(savingsTotal))}</strong></div>
        <div><span className="small muted">Mobile</span><strong><a href={`tel:${customer.phone}`}>{customer.phone}</a></strong></div>
      </div>

      <div className="grid grid--2-1">
        <div className="stack">
          <section>
            <div className="row row--between" style={{ marginBottom: 10 }}>
              <h2 className="card__title">Loans</h2>
              {can('loans.apply') && customer.status === 'active' ? <Link to={`/loans/new?customer_id=${customer.id}`} className="btn btn--small">New loan</Link> : null}
            </div>
            {customer.loans.length === 0 ? (
              <p className="card muted">No loans.</p>
            ) : (
              <div className="acct-grid">
                {customer.loans.map((l) => (
                  <LoanCard
                    key={l.id}
                    loan={l}
                    onCollect={canCollect ? () => setPaying({ accountType: 'loan', accountId: l.id, accountNumber: l.loan_number, customerName: customer.full_name, suggestedAmount: Number(l.overdue) > 0 ? l.overdue : l.installment_amount ?? undefined }) : undefined}
                  />
                ))}
              </div>
            )}
          </section>

          <section>
            <div className="row row--between" style={{ marginBottom: 10 }}>
              <h2 className="card__title">Savings &amp; deposits</h2>
              {can('deposits.open') && depositsOn && customer.status === 'active' ? <Link to={`/deposits/new?customer_id=${customer.id}`} className="btn btn--small">Open savings</Link> : null}
            </div>
            {customer.deposits.length === 0 ? (
              <p className="card muted">{depositsOn ? 'No deposit accounts.' : 'Deposit-taking is switched off until compliance approval.'}</p>
            ) : (
              <div className="acct-grid">
                {customer.deposits.map((d) => (
                  <SavingsCard
                    key={d.id}
                    deposit={d}
                    today={today}
                    onCollect={canCollect && depositsOn ? () => setPaying({ accountType: 'deposit', accountId: d.id, accountNumber: d.deposit_number, customerName: customer.full_name, suggestedAmount: d.amount_due ?? d.installment_amount ?? undefined }) : undefined}
                  />
                ))}
              </div>
            )}
          </section>

          {justAdded ? null : <Documents customerId={customer.id} quickCapture={IS_APP} />}

          <section className="card">
            <div className="row row--between" style={{ marginBottom: 8 }}>
              <h2 className="card__title">Profile</h2>
              {can('customers.locate') ? (
                <button type="button" className="btn btn--small" disabled={locating} onClick={setLocationHere}>
                  {locating ? 'Reading GPS…' : customer.latitude ? 'Update location' : 'Set location here'}
                </button>
              ) : null}
            </div>
            <Details
              rows={[
                ['Mobile', <a key="p" href={`tel:${customer.phone}`}>{customer.phone}</a>],
                ['Alternate', customer.alt_phone ?? '—'],
                ['Email', customer.email ?? '—'],
                ['Date of birth', date(customer.date_of_birth)],
                ['Gender', customer.gender ? titleCase(customer.gender) : '—'],
                ['Occupation', customer.occupation ?? '—'],
                ['Monthly income', money(customer.monthly_income)],
                ['Address', `${customer.address_line}, ${customer.city}, ${customer.state} ${customer.pincode}`],
                ['Location', customer.latitude && customer.longitude ? (
                  <a key="loc" href={mapsLink(customer.latitude, customer.longitude)} target="_blank" rel="noreferrer">
                    View on map{customer.location_set_at ? ` · set ${date(customer.location_set_at.slice(0, 10))}` : ''}
                  </a>
                ) : <span key="loc" className="muted">Not set</span>],
                ['Created', `${dateTime(customer.created_at)} by ${customer.created_by_name}`],
              ]}
            />
          </section>
        </div>

        <div className="stack">
          {user?.customer_scope !== 'assigned' ? <section className="card">
            <h2 className="card__title" style={{ marginBottom: 8 }}>Collection agent</h2>
            <p>{current ? <strong>{current.agent_name}</strong> : <span className="muted">Not assigned</span>}</p>
            {current ? <p className="muted small">Since {date(current.valid_from)}</p> : null}
            {can('customers.assign') ? (
              <div className="row" style={{ marginTop: 12 }}>
                <select value={agentId} onChange={(e) => setAgentId(e.target.value)}>
                  <option value="">{current ? 'Reassign to…' : 'Assign to…'}</option>
                  {branchAgents.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                </select>
                <button type="button" className="btn btn--primary" disabled={!agentId || assigning} onClick={assign}>Assign</button>
              </div>
            ) : null}
            {customer.assignments.length > 0 ? (
              <>
                <h3 className="card__subtitle">History</h3>
                <ul className="history">
                  {customer.assignments.map((a) => (
                    <li key={a.id}>
                      {a.agent_name}
                      <div className="muted small">{date(a.valid_from)} – {a.valid_to ? date(a.valid_to) : 'present'} · by {a.assigned_by_name}</div>
                    </li>
                  ))}
                </ul>
              </>
            ) : null}
          </section> : null}

          {can('customers.update') ? <section className="card">
            <h2 className="card__title" style={{ marginBottom: 8 }}>Customer portal</h2>
            {customer.portal_status ? (
              <p>
                Login <span className="mono">{customer.portal_email ?? `${customer.phone} (mobile number)`}</span> <StatusBadge status={customer.portal_status} />
              </p>
            ) : (
              <p className="muted">No portal login yet. Customers can view balances, schedules and receipts online.</p>
            )}
            {can('customers.update') ? (
              <button type="button" className="btn" style={{ marginTop: 12 }} onClick={() => setPortalOpen(true)}>
                {customer.portal_status ? 'Reset portal login' : 'Enable portal login'}
              </button>
            ) : null}
          </section> : null}
        </div>
      </div>

      {paying ? <RecordPaymentModal target={paying} onClose={() => setPaying(null)} onRecorded={reload} /> : null}
      {portalOpen ? (
        <PortalModal
          customer={customer}
          onClose={() => setPortalOpen(false)}
          onDone={(c) => {
            setCustomer(c)
            setPortalOpen(false)
            setNotice('Portal login saved. Share the password with the customer securely.')
          }}
        />
      ) : null}
    </>
  )
}

function PortalModal({ customer, onClose, onDone }: { customer: Customer; onClose: () => void; onDone: (c: Customer) => void }) {
  const [email, setEmail] = useState(customer.portal_email ?? customer.email ?? '')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<unknown>(null)
  const errors = fieldErrors(error)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      onDone(await api.post<Customer>(`/customers/${customer.id}/portal-access`, { email, password }))
    } catch (err) {
      setError(err)
    }
  }

  return (
    <Modal title="Customer portal login" onClose={onClose}>
      <form onSubmit={submit} className="stack">
        <ErrorBanner error={bannerError(error)} />
        <Field label="Login email" error={errors.email}>
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        </Field>
        <Field label="Temporary password" error={errors.password} hint="At least 8 characters. Ask the customer to change it after first sign-in.">
          <input type="text" autoComplete="off" value={password} onChange={(e) => setPassword(e.target.value)} />
        </Field>
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary">Save login</button>
        </div>
      </form>
    </Modal>
  )
}

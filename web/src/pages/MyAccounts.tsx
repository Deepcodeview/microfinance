import { useState } from 'react'
import { Link } from 'react-router-dom'
import { CustomerPayModal, type PayTarget } from '../components/CustomerPayModal'
import { Icons } from '../components/icons'
import { useAuth } from '../auth'
import { LoanCard, SavingsCard } from '../components/AccountCards'
import { Documents } from '../components/Documents'
import { Empty, ErrorBanner, Loading, Notice, StatusBadge } from '../components/ui'
import { APP_MODE } from '../config'
import { ApplicationCard } from '../mobile/CustomerApp'
import { date, money, titleCase } from '../format'
import { useFetch } from '../hooks'
import { t } from '../i18n'
import type { Customer, CustomerApplication, PageMeta } from '../types'

interface PaymentRow {
  id: string
  payment_number: string
  amount: string
  method: string
  status: string
  business_date: string
  account_number: string
  receipt_number: string | null
}

/** Customer self-service home: separate loan and deposit cards, recent receipts. */
export function MyAccounts() {
  const { user } = useAuth()
  const { data: customer, error } = useFetch<Customer>(user?.customer_id ? `/customers/${user.customer_id}` : null)
  const { data: payments } = useFetch<PaymentRow[] & { meta?: PageMeta }>('/payments?per_page=10')
  // The customer app lets people apply themselves; the web portal keeps the support-request shortcuts.
  const inApp = APP_MODE === 'customer'
  const { data: applications, reload: reloadApplications } = useFetch<CustomerApplication[]>(inApp ? '/my/applications' : null)
  // Customers pay their own installments by UPI from the app (once the business has a UPI ID).
  const [paying, setPaying] = useState<PayTarget | null>(null)
  const canPay = inApp && Boolean(user?.settings.upi_enabled) && user?.permissions.includes('payments.self')

  if (!user?.customer_id) return <ErrorBanner error={new Error('Your login is not linked to a customer profile. Please contact the branch.')} />
  if (!customer) return error ? <ErrorBanner error={error} /> : <Loading />

  return (
    <>
      <div className="greeting">
        <div className="greeting__title">
          <span className="greeting__spark">✦</span>
          Hello, {customer.full_name.split(' ')[0]}
        </div>
        <div className="greeting__sub">
          Customer number <span className="mono">{customer.customer_number}</span> · {customer.branch_name}
        </div>
      </div>

      {customer.status === 'pending' ? (
        <Notice tone="info">
          <div>
            <strong>Registration under review.</strong> Our team is verifying your details. You can already choose a plan and apply — we will process it as soon as you are approved.
          </div>
        </Notice>
      ) : null}
      {customer.status === 'blocked' && customer.registration_note ? (
        <Notice tone="warning">Your registration was not approved: {customer.registration_note}. Please contact the branch.</Notice>
      ) : null}

      <CustomerHero customer={customer} depositsOn={user.settings.deposits_enabled} inApp={inApp} />

      {inApp && applications && applications.length > 0 ? (
        <>
          <div className="row row--between" style={{ alignItems: 'baseline' }}>
            <h2 className="form-section">My applications</h2>
            <Link to="/applications" className="small">See all</Link>
          </div>
          <div className="stack">
            {applications.slice(0, 2).map((a) => <ApplicationCard key={a.id} app={a} onChanged={reloadApplications} />)}
          </div>
        </>
      ) : null}

      <h2 className="form-section">Loans</h2>
      {customer.loans.length === 0 ? (
        <div className="card">
          <Empty title="No loans">Loans you take with us will appear here.</Empty>
        </div>
      ) : (
        <div className="acct-grid">
          {customer.loans.map((l) => (
            <LoanCard
              key={l.id}
              loan={l}
              actionLabel="Pay"
              onCollect={canPay ? () => setPaying({ accountType: 'loan', accountId: String(l.id), accountNumber: l.loan_number, suggestedAmount: Number(l.overdue) > 0 ? l.overdue : l.installment_amount }) : undefined}
            />
          ))}
        </div>
      )}

      {user.settings.deposits_enabled || customer.deposits.length > 0 ? (
        <>
          <h2 className="form-section">Savings &amp; deposits</h2>
          {customer.deposits.length === 0 ? (
            <div className="card">
              <Empty title="No deposits">Savings and fixed deposits will appear here.</Empty>
            </div>
          ) : (
            <div className="acct-grid">
              {customer.deposits.map((d) => (
                <SavingsCard
                  key={d.id}
                  deposit={d}
                  today={user.settings.business_date}
                  actionLabel="Pay"
                  onCollect={canPay ? () => setPaying({ accountType: 'deposit', accountId: String(d.id), accountNumber: d.deposit_number, suggestedAmount: d.amount_due ?? d.installment_amount }) : undefined}
                />
              ))}
            </div>
          )}
        </>
      ) : null}

      <div style={{ marginTop: 24 }}>
        <Documents customerId={String(customer.id)} />
      </div>
      <div className="card row row--between row--wrap" style={{ marginTop: 16 }}>
        <div>
          <strong className="serif" style={{ fontSize: 17 }}>Need help?</strong>
          <div className="small muted">Questions about a payment, statement or your account — we reply in the app.</div>
        </div>
        <Link to="/support" className="btn">Contact support</Link>
      </div>

      {paying ? <CustomerPayModal target={paying} onClose={() => setPaying(null)} onDone={() => undefined} /> : null}

      <h2 className="form-section">Recent payments</h2>
      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Account</th>
              <th>Method</th>
              <th>Status</th>
              <th className="num">Amount</th>
              <th>Receipt</th>
            </tr>
          </thead>
          <tbody>
            {(payments ?? []).length === 0 ? (
              <tr>
                <td colSpan={6} className="table__empty">
                  No payments yet.
                </td>
              </tr>
            ) : (
              (payments ?? []).map((p) => (
                <tr key={p.id}>
                  <td>{date(p.business_date)}</td>
                  <td className="mono">{p.account_number}</td>
                  <td>{titleCase(p.method)}</td>
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
    </>
  )
}

/** Phone home for customers: what you owe, what's due next, what you've saved, and the shortcuts people actually use. */
function CustomerHero({ customer, depositsOn, inApp }: { customer: Customer; depositsOn: boolean; inApp: boolean }) {
  const active = customer.loans.filter((l) => l.status === 'active')
  const total = active.reduce((s, l) => s + Number(l.outstanding), 0)
  const overdue = active.reduce((s, l) => s + Number(l.overdue), 0)
  const next = active.filter((l) => l.next_due_date).sort((a, b) => a.next_due_date!.localeCompare(b.next_due_date!))[0]
  const saved = customer.deposits.filter((d) => d.status === 'active').reduce((s, d) => s + Number(d.principal_balance), 0)
  const firstActive = active[0]
  return (
    <>
      <section className="hero" aria-label="Your summary">
        <div className="hero__label">Total outstanding</div>
        <div className="hero__value">{money(String(total))}</div>
        <div className="hero__sub">
          {`${active.length} active loan${active.length === 1 ? '' : 's'}`}
          {customer.deposits.length > 0 ? <> · Savings {money(String(saved))}</> : null}
        </div>
        <div className="hero__row">
          <div><span style={{ opacity: 0.85 }}>Next due</span><strong>{next?.installment_amount ? money(next.installment_amount) : '—'}</strong><span style={{ opacity: 0.85 }}>{next?.next_due_date ? date(next.next_due_date) : ''}</span></div>
          <div style={{ textAlign: 'right' }}><span style={{ opacity: 0.85 }}>Overdue</span><strong>{money(String(overdue))}</strong>{overdue > 0 ? <span style={{ opacity: 0.85 }}>please pay soon</span> : <span style={{ opacity: 0.85 }}>all clear</span>}</div>
        </div>
      </section>
      <nav className={depositsOn ? 'quick-actions quick-actions--4' : 'quick-actions'} aria-label="Shortcuts">
        {firstActive ? <Link to={`/loans/${firstActive.id}/statement`} className="quick-action"><Icons.file />Statement</Link> : <span className="quick-action muted"><Icons.file />Statement</span>}
        {inApp ? (
          <>
            <Link to="/apply/loan" className="quick-action"><Icons.loan />Apply for loan</Link>
            {depositsOn ? <Link to="/apply/savings" className="quick-action"><Icons.piggy />Open savings</Link> : null}
          </>
        ) : (
          <>
            <Link to={`/support?new=loan_query&subject=${encodeURIComponent(t('I want a new loan'))}`} className="quick-action"><Icons.loan />Ask for loan</Link>
            {depositsOn ? <Link to={`/support?new=deposit_query&subject=${encodeURIComponent(t('I want to start savings'))}`} className="quick-action"><Icons.piggy />Start savings</Link> : null}
          </>
        )}
        <Link to="/support" className="quick-action"><Icons.chat />Get help</Link>
      </nav>
    </>
  )
}

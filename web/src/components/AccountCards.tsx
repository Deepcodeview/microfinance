import { Link } from 'react-router-dom'
import { date, money } from '../format'
import { periodCount } from '../plans'
import type { CustomerDeposit, CustomerLoan } from '../types'
import { StatusBadge } from './ui'

const PER: Record<string, string> = {
  daily: 'per day',
  weekly: 'per week',
  fortnightly: 'per 2 weeks',
  monthly: 'per month',
  quarterly: 'per quarter',
  yearly: 'per year',
}

const LOAN_KIND: Record<string, string> = {
  daily: 'Daily loan',
  weekly: 'Weekly loan',
  fortnightly: 'Fortnightly loan',
  monthly: 'Monthly EMI loan',
  quarterly: 'Quarterly loan',
  yearly: 'Yearly loan',
}

const SAVINGS_KIND: Record<string, string> = {
  daily: 'Daily savings',
  weekly: 'Weekly savings',
  monthly: 'Monthly savings',
  fixed: 'Fixed deposit',
}

const pct = (part: number, whole: number) => (whole > 0 ? Math.max(0, Math.min(100, Math.round((part / whole) * 100))) : 0)

/** One loan at a glance: how often it is paid, how far along it is, and what is due or late. */
export function LoanCard({ loan, onCollect, actionLabel = 'Collect' }: { loan: CustomerLoan; onCollect?: () => void; actionLabel?: string }) {
  const active = loan.status === 'active'
  const paid = Number(loan.installments_paid)
  const total = Number(loan.installments_total)
  const late = Number(loan.overdue) > 0
  return (
    <article className={`card acct-card${late ? ' acct-card--late' : ''}`}>
      <Link to={`/loans/${loan.id}`} className="acct-card__link">
        <div className="row row--between">
          <span className="badge">{LOAN_KIND[loan.frequency] ?? 'Loan'}</span>
          <StatusBadge status={loan.status} />
        </div>
        <div className="acct-card__name">{loan.product_name}</div>
        <div className="mono small muted">{loan.loan_number}</div>
        <div className="acct-card__figures">
          <div>
            <div className="small muted">{active || loan.status === 'closed' ? 'Outstanding' : 'Amount applied'}</div>
            <div className="acct-card__value">{money(active || loan.status === 'closed' ? loan.outstanding : loan.principal)}</div>
          </div>
          {loan.installment_amount ? (
            <div style={{ textAlign: 'right' }}>
              <div className="small muted">Installment</div>
              <div className="tabular"><strong>{money(loan.installment_amount)}</strong> <span className="small muted">{PER[loan.frequency]}</span></div>
            </div>
          ) : null}
        </div>
        {total > 0 ? (
          <div className="loan-card__progress">
            <div className="progress"><div className="progress__bar" style={{ width: `${pct(paid, total)}%` }} /></div>
            <div className="loan-card__meta">
              <span>{`${paid} of ${total} installments paid`}</span>
              {loan.next_due_date ? <span>Next {date(loan.next_due_date)}</span> : null}
            </div>
          </div>
        ) : null}
        {late ? <div className="acct-card__alert">Overdue {money(loan.overdue)}</div> : null}
      </Link>
      {onCollect && active ? (
        <button type="button" className="btn btn--primary btn--block" onClick={onCollect}>
          {actionLabel} {money(late ? loan.overdue : loan.installment_amount ?? '0')}
        </button>
      ) : null}
    </article>
  )
}

/** One savings account: type, how much is saved, and how close it is to maturity. */
export function SavingsCard({ deposit, today, onCollect, actionLabel = 'Collect' }: { deposit: CustomerDeposit; today: string; onCollect?: () => void; actionLabel?: string }) {
  const kind = deposit.scheme === 'fixed' ? 'fixed' : deposit.frequency ?? 'daily'
  const span = Date.parse(deposit.maturity_date) - Date.parse(deposit.opened_on)
  const elapsed = Date.parse(today) - Date.parse(deposit.opened_on)
  const progress = pct(elapsed, span)
  return (
    <article className="card acct-card acct-card--savings">
      <Link to={`/deposits/${deposit.id}`} className="acct-card__link">
        <div className="row row--between">
          <span className="badge badge--success">{SAVINGS_KIND[kind]}</span>
          <StatusBadge status={deposit.status} />
        </div>
        <div className="acct-card__name">{deposit.product_name}</div>
        <div className="mono small muted">{deposit.deposit_number}</div>
        <div className="acct-card__figures">
          <div>
            <div className="small muted">Saved so far</div>
            <div className="acct-card__value">{money(deposit.principal_balance)}</div>
          </div>
          <div style={{ textAlign: 'right' }}>
            <div className="small muted">{deposit.scheme === 'fixed' ? 'Deposit' : 'Installment'}</div>
            <div className="tabular">
              <strong>{money(deposit.scheme === 'fixed' ? deposit.deposit_amount ?? deposit.principal_balance : deposit.installment_amount ?? '0')}</strong>{' '}
              {deposit.frequency ? <span className="small muted">{PER[deposit.frequency]}</span> : null}
            </div>
          </div>
        </div>
        <div className="loan-card__progress">
          <div className="progress progress--savings"><div className="progress__bar" style={{ width: `${progress}%` }} /></div>
          <div className="loan-card__meta">
            <span>Interest earned {money(deposit.accrued_interest)}</span>
            <span>Matures {date(deposit.maturity_date)}</span>
          </div>
        </div>
        {deposit.installments_due !== undefined ? (
          <div className={`savings-due${(deposit.missed_installments ?? 0) > 0 ? ' savings-due--late' : ''}`}>
            {deposit.installments_due === 0
              ? `Paid up to date · next from ${date(deposit.next_due_date)}`
              : (deposit.missed_installments ?? 0) > 0
                ? `Not paid for ${periodCount(deposit.missed_installments ?? 0, deposit.frequency)} (since ${date(deposit.unpaid_since)}) · ${money(deposit.amount_due)} due`
                : `${money(deposit.amount_due)} due now`}
          </div>
        ) : null}
      </Link>
      {onCollect && deposit.status === 'active' && deposit.scheme === 'recurring' && deposit.installments_due !== 0 ? (
        <button type="button" className="btn btn--primary btn--block" onClick={onCollect}>
          {actionLabel} {money(deposit.amount_due ?? deposit.installment_amount ?? '0')}
        </button>
      ) : null}
    </article>
  )
}

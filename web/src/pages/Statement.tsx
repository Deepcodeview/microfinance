import { useParams } from 'react-router-dom'
import { printPage } from '../print'
import { useAuth } from '../auth'
import { publicOrigin } from '../config'
import { Icons } from '../components/icons'
import { ErrorBanner, Loading, PageHeader } from '../components/ui'
import { date, dateTime, money } from '../format'
import { useFetch } from '../hooks'

interface LoanStatement {
  loan: { id: string; loan_number: string; customer_name: string; customer_number: string; branch_name: string; product_name: string; principal: string; installment_amount: string; disbursement_date: string; status: string }
  lines: { date: string; description: string; reference: string | null; debit: string; credit: string; principal: string; interest: string; charges: string; principal_balance: string }[]
  position: { total_outstanding: string; principal_outstanding: string; overdue_amount: string }
  generated_at: string
}

interface DepositStatement {
  account: { id: string; deposit_number: string; customer_name: string; customer_number: string; branch_name: string; product_name: string; opened_on: string; maturity_date: string; status: string; principal_balance: string; accrued_interest: string }
  lines: { date: string; description: string; reference: string | null; debit: string; credit: string; balance: string }[]
  generated_at: string
}

/** Printable account statement for loans or deposits. */
export function Statement({ kind }: { kind: 'loan' | 'deposit' }) {
  const { id } = useParams()
  const { user } = useAuth()
  const path = kind === 'loan' ? `/loans/${id}/statement` : `/deposit-accounts/${id}/statement`
  const { data, error } = useFetch<LoanStatement | DepositStatement>(path)

  if (!data) return error ? <ErrorBanner error={error} /> : <Loading />
  const head = 'loan' in data ? data.loan : data.account
  const number = 'loan' in data ? data.loan.loan_number : data.account.deposit_number

  return (
    <div className="statement-page">
      <PageHeader
        back={{ to: kind === 'loan' ? `/loans/${id}` : `/deposits/${id}`, label: 'Account' }}
        title="Account statement"
        subtitle={<><span className="mono">{number}</span><span>· generated {dateTime(data.generated_at)}</span></>}
        actions={<button type="button" className="btn no-print" onClick={() => printPage(`Statement ${number}`)}><Icons.print /> Print / save PDF</button>}
      />
      <section className="card">
        <div className="grid grid--2">
          <div>
            <div className="brand"><span className="brand__mark">K</span>{user?.settings.company_name || 'KBSAVE'}</div>
            <div className="small muted" style={{ marginTop: 6 }}>{head.branch_name}</div>
          </div>
          <div style={{ textAlign: 'right' }}>
            <strong>{head.customer_name}</strong>
            <div className="small muted">Customer {head.customer_number}</div>
            <div className="small muted">{head.product_name}</div>
          </div>
        </div>

        {'loan' in data ? (
          <>
            <div className="stats" style={{ marginTop: 18 }}>
              <div className="stat"><div className="stat__label">Principal</div><div className="stat__value" style={{ fontSize: 22 }}>{money(data.loan.principal)}</div><div className="stat__hint">Disbursed {date(data.loan.disbursement_date)}</div></div>
              <div className="stat"><div className="stat__label">Principal outstanding</div><div className="stat__value" style={{ fontSize: 22 }}>{money(data.position.principal_outstanding)}</div></div>
              <div className="stat"><div className="stat__label">Total due incl. interest</div><div className="stat__value" style={{ fontSize: 22 }}>{money(data.position.total_outstanding)}</div><div className="stat__hint">Overdue {money(data.position.overdue_amount)}</div></div>
            </div>
            <div className="table-wrap table-wrap--plain">
              <table className="table table--compact">
                <thead><tr><th>Date</th><th>Description</th><th>Reference</th><th className="num">Debit</th><th className="num">Credit</th><th className="num">Principal</th><th className="num">Interest</th><th className="num">Charges</th><th className="num">Principal balance</th></tr></thead>
                <tbody>
                  {data.lines.map((l, i) => (
                    <tr key={i}>
                      <td>{date(l.date)}</td><td>{l.description}</td><td className="mono small">{l.reference ?? '—'}</td>
                      <td className="num">{Number(l.debit) ? money(l.debit) : ''}</td><td className="num">{Number(l.credit) ? money(l.credit) : ''}</td>
                      <td className="num">{Number(l.principal) ? money(l.principal) : ''}</td><td className="num">{Number(l.interest) ? money(l.interest) : ''}</td>
                      <td className="num">{Number(l.charges) ? money(l.charges) : ''}</td><td className="num"><strong>{money(l.principal_balance)}</strong></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        ) : (
          <>
            <div className="stats" style={{ marginTop: 18 }}>
              <div className="stat"><div className="stat__label">Balance</div><div className="stat__value" style={{ fontSize: 22 }}>{money(data.account.principal_balance)}</div></div>
              <div className="stat"><div className="stat__label">Interest accrued</div><div className="stat__value" style={{ fontSize: 22 }}>{money(data.account.accrued_interest)}</div></div>
              <div className="stat"><div className="stat__label">Term</div><div className="stat__value" style={{ fontSize: 18 }}>{date(data.account.opened_on)} – {date(data.account.maturity_date)}</div></div>
            </div>
            <div className="table-wrap table-wrap--plain">
              <table className="table table--compact">
                <thead><tr><th>Date</th><th>Description</th><th>Reference</th><th className="num">Withdrawal</th><th className="num">Deposit</th><th className="num">Balance</th></tr></thead>
                <tbody>
                  {data.lines.length === 0 ? <tr><td colSpan={6} className="table__empty">No transactions yet.</td></tr> : data.lines.map((l, i) => (
                    <tr key={i}>
                      <td>{date(l.date)}</td><td>{l.description}</td><td className="mono small">{l.reference ?? '—'}</td>
                      <td className="num">{Number(l.debit) ? money(l.debit) : ''}</td><td className="num">{Number(l.credit) ? money(l.credit) : ''}</td>
                      <td className="num"><strong>{money(l.balance)}</strong></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
        <p className="small muted" style={{ marginTop: 16 }}>This is a system-generated statement. Verify any receipt at {publicOrigin()}/verify/&lt;code&gt;.</p>
      </section>
    </div>
  )
}

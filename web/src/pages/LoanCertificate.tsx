import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { Icons } from '../components/icons'
import { ErrorBanner, Loading } from '../components/ui'
import { date, money } from '../format'
import { useFetch } from '../hooks'
import { printPage } from '../print'
import type { Loan } from '../types'

/** No Dues / loan closure certificate for a fully repaid loan, to print or save as PDF. */
export function LoanCertificate() {
  const { id } = useParams()
  const { user } = useAuth()
  const { data: loan, error } = useFetch<Loan>(`/loans/${id}`)

  if (!loan) return error ? <ErrorBanner error={error} /> : <Loading />
  if (loan.status !== 'closed' || !loan.closed_at) {
    return <ErrorBanner error={new Error('A closure certificate is issued once the loan is fully repaid and closed.')} />
  }

  const company = user?.settings.company_name || 'KBSAVE'
  const repaid = loan.payments
    .filter((p) => p.status === 'confirmed')
    .reduce((sum, p) => sum + Number(p.amount), Number(loan.opening_collected ?? 0))
  const closedOn = loan.closed_at.slice(0, 10)

  return (
    <>
      <div className="row row--between no-print" style={{ marginBottom: 16 }}>
        <Link to={`/loans/${loan.id}`} className="back-link"><Icons.arrowLeft /> Back</Link>
        <button type="button" className="btn" onClick={() => printPage(`No Dues ${loan.loan_number}`)}><Icons.print /> Print / save PDF</button>
      </div>
      <article className="certificate">
        <header className="certificate__head">
          <div className="brand" style={{ justifyContent: 'center' }}><span className="brand__mark">K</span>{company}</div>
          <div className="small muted">{loan.branch_name}</div>
          <h1 className="certificate__title">No Dues Certificate</h1>
          <div className="small muted mono">Certificate NOC-{loan.loan_number} · issued {date(closedOn)}</div>
        </header>
        <p>
          This is to certify that <b>{loan.customer_name}</b> (customer no. <span className="mono">{loan.customer_number}</span>) has
          {loan.closure_type === 'settled' ? ' settled' : ' fully repaid'} loan <b className="mono">{loan.loan_number}</b> ({loan.product_name}).
        </p>
        <dl className="certificate__facts">
          <div><dt>Loan amount</dt><dd>{money(loan.principal)}</dd></div>
          <div><dt>Loan given on</dt><dd>{date(loan.disbursement_date)}</dd></div>
          <div><dt>Closed on</dt><dd>{date(closedOn)}</dd></div>
          <div><dt>Total repaid</dt><dd>{money(String(repaid))}</dd></div>
          <div><dt>Amount outstanding</dt><dd><b>{money('0')}</b></dd></div>
        </dl>
        <p>
          No amount is due from the customer on this loan, and {company} has no further claim on it. This certificate does not cover any
          other loan or account of the customer.
        </p>
        <footer className="certificate__sign">
          <div>
            <div className="certificate__line" />
            <div className="small">Authorised signatory, {company}</div>
          </div>
          <div className="small muted">Generated from {company}'s records on {date(new Date().toISOString().slice(0, 10))}.</div>
        </footer>
      </article>
    </>
  )
}

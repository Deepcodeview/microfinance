import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { publicOrigin } from '../config'
import { Icons } from '../components/icons'
import { ErrorBanner, Loading } from '../components/ui'
import { date, dateTime, money, titleCase } from '../format'
import { useFetch } from '../hooks'
import { whatsappReceiptLink } from '../field'
import { printPage } from '../print'
import { coversLabel } from '../plans'
import type { Payment } from '../types'

export function Receipt() {
  const { id } = useParams()
  const { user } = useAuth()
  const { data: p, error } = useFetch<Payment>(`/payments/${id}`)

  if (!p) return error ? <ErrorBanner error={error} /> : <Loading />
  if (!p.receipt_number) return <ErrorBanner error={new Error('No receipt has been issued for this payment yet.')} />

  return (
    <>
      <div className="row row--between no-print" style={{ marginBottom: 16 }}>
        <Link to={user?.customer_scope === 'own' ? '/' : `/payments/${p.id}`} className="back-link">
          <Icons.arrowLeft /> Back
        </Link>
        <div className="row" style={{ gap: 8 }}>
          <a className="btn whatsapp-btn" style={{ marginTop: 0 }} href={whatsappReceiptLink(p, user?.settings.company_name || 'KBSAVE')} target="_blank" rel="noreferrer">
            Share on WhatsApp
          </a>
          <button type="button" className="btn" onClick={() => printPage(`Receipt ${p.receipt_number}`)}>
            <Icons.print /> Print
          </button>
        </div>
      </div>
      <div className="receipt">
        <div className="receipt__head">
          <div className="brand" style={{ justifyContent: 'center' }}>
            <span className="brand__mark">K</span>
            {user?.settings.company_name || 'KBSAVE'}
          </div>
          <div className="muted small" style={{ marginTop: 6 }}>
            Payment receipt
          </div>
        </div>
        {p.status === 'reversed' ? <div className="banner banner--error">This payment was reversed on {dateTime(p.reversed_at)}.</div> : null}
        <div className="receipt__amount">{money(p.amount)}</div>
        <dl className="details details--compact">
          <div className="details__row">
            <dt>Receipt no.</dt>
            <dd className="mono">{p.receipt_number}</dd>
          </div>
          <div className="details__row">
            <dt>Date</dt>
            <dd>{date(p.business_date)}</dd>
          </div>
          <div className="details__row">
            <dt>Received from</dt>
            <dd>
              {p.customer_name} ({p.customer_number})
            </dd>
          </div>
          <div className="details__row">
            <dt>Account</dt>
            <dd className="mono">{p.account_number}</dd>
          </div>
          <div className="details__row">
            <dt>Towards</dt>
            <dd>{p.account_type === 'loan' ? (p.purpose === 'settlement' ? 'Loan settlement' : 'Loan repayment') : 'Deposit contribution'}</dd>
          </div>
          {coversLabel(p, date) ? (
            <div className="details__row">
              <dt>For installments of</dt>
              <dd>{coversLabel(p, date)}</dd>
            </div>
          ) : null}
          <div className="details__row">
            <dt>Mode</dt>
            <dd>
              {titleCase(p.method)}
              {p.reference ? ` · ${p.reference}` : ''}
            </dd>
          </div>
          {p.allocations
            .filter((a) => a.component !== 'interest_waived')
            .reduce<{ component: string; amount: number }[]>((acc, a) => {
              const found = acc.find((x) => x.component === a.component)
              if (found) found.amount += Number(a.amount)
              else acc.push({ component: a.component, amount: Number(a.amount) })
              return acc
            }, [])
            .map((a) => (
              <div key={a.component} className="details__row">
                <dt className="small">  {titleCase(a.component)}</dt>
                <dd className="small">{money(String(a.amount))}</dd>
              </div>
            ))}
          <div className="details__row">
            <dt>Collected by</dt>
            <dd>{p.collected_by_name}</dd>
          </div>
          <div className="details__row">
            <dt>Branch</dt>
            <dd>{p.branch_name}</dd>
          </div>
        </dl>
        <div className="receipt__head" style={{ borderTop: '1px dashed var(--border-strong)', borderBottom: 0, marginTop: 16, paddingTop: 16, marginBottom: 0 }}>
          <div className="small muted">Verification code</div>
          <div className="mono" style={{ fontSize: 18, letterSpacing: 2 }}>
            {p.verification_code}
          </div>
          <div className="small muted" style={{ marginTop: 4 }}>
            Verify at {publicOrigin()}/verify/{p.verification_code}
          </div>
        </div>
      </div>
    </>
  )
}

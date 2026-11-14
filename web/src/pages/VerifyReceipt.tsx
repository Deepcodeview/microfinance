import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api'
import { ErrorBanner } from '../components/ui'
import { date, money, titleCase } from '../format'

interface Verified {
  receipt_number: string
  issued_at: string
  amount: string
  status: string
  business_date: string
  customer: string
}

/** Public page: anyone holding a receipt can check it is genuine. Shows no personal details beyond initials. */
export function VerifyReceipt() {
  const { code } = useParams()
  const [data, setData] = useState<Verified | null>(null)
  const [error, setError] = useState<unknown>(null)

  useEffect(() => {
    api.get<Verified>(`/receipts/verify/${encodeURIComponent(code ?? '')}`).then(setData).catch(setError)
  }, [code])

  return (
    <div className="login">
      <div className="login__card">
        <div className="login__brand">
          <span className="brand__mark" style={{ width: 44, height: 44, fontSize: 24, borderRadius: 12 }}>
            K
          </span>
        </div>
        <h1 className="login__title">Receipt check</h1>
        <div className="login__panel">
          <ErrorBanner error={error} />
          {data ? (
            <>
              <div className={`banner ${data.status === 'confirmed' ? 'banner--success' : 'banner--error'}`}>
                {data.status === 'confirmed' ? 'This receipt is genuine.' : `This payment is ${titleCase(data.status)}.`}
              </div>
              <div className="receipt__amount">{money(data.amount)}</div>
              <dl className="details details--compact">
                <div className="details__row">
                  <dt>Receipt</dt>
                  <dd className="mono">{data.receipt_number}</dd>
                </div>
                <div className="details__row">
                  <dt>Date</dt>
                  <dd>{date(data.business_date)}</dd>
                </div>
                <div className="details__row">
                  <dt>Customer</dt>
                  <dd>{data.customer}</dd>
                </div>
              </dl>
            </>
          ) : !error ? (
            <p className="muted">Checking…</p>
          ) : null}
        </div>
      </div>
    </div>
  )
}

import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ErrorBanner, Loading, PageHeader, Pager, Segmented, StatusBadge } from '../components/ui'
import { dateTime, money, titleCase } from '../format'
import { usePagedList } from '../hooks'

interface Row {
  id: string
  payment_number: string
  amount: string
  method: string
  purpose: string
  status: string
  business_date: string
  collected_at: string
  account_type: string
  reference: string | null
  customer_name: string
  account_number: string
  collected_by_name: string
  receipt_number: string | null
}

export function Payments() {
  const { rows, meta, error, params, update } = usePagedList<Row>('/payments')
  const [search, setSearch] = useState(params.get('q') ?? '')

  return (
    <>
      <PageHeader title="Payments" subtitle={meta ? `${meta.total.toLocaleString('en-IN')} payments` : undefined} />
      <div className="toolbar">
        <Segmented
          options={[
            { id: '', label: 'All' },
            { id: 'pending', label: 'Pending' },
            { id: 'confirmed', label: 'Confirmed' },
            { id: 'failed', label: 'Failed' },
            { id: 'reversed', label: 'Reversed' },
          ]}
          value={params.get('status') ?? ''}
          onChange={(status) => update({ status })}
        />
      </div>
      <form
        className="toolbar"
        onSubmit={(e) => {
          e.preventDefault()
          update({ q: search.trim() })
        }}
      >
        <input type="search" placeholder="Payment no., customer, reference, account" value={search} onChange={(e) => setSearch(e.target.value)} />
        <select value={params.get('method') ?? ''} onChange={(e) => update({ method: e.target.value })}>
          <option value="">All methods</option>
          <option value="cash">Cash</option>
          <option value="upi">UPI</option>
        </select>
        <input type="date" value={params.get('date_from') ?? ''} onChange={(e) => update({ date_from: e.target.value })} aria-label="From date" />
        <input type="date" value={params.get('date_to') ?? ''} onChange={(e) => update({ date_to: e.target.value })} aria-label="To date" />
      </form>

      <ErrorBanner error={error} />
      {rows === null && !error ? <Loading /> : null}
      {rows ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Payment</th>
                <th>Customer</th>
                <th>Account</th>
                <th>Method</th>
                <th>Collected</th>
                <th>Status</th>
                <th className="num">Amount</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={7} className="table__empty">
                    No payments match these filters.
                  </td>
                </tr>
              ) : (
                rows.map((p) => (
                  <tr key={p.id} className={p.status === 'reversed' || p.status === 'failed' ? 'is-muted' : undefined}>
                    <td>
                      <Link to={`/payments/${p.id}`} className="mono">
                        {p.payment_number}
                      </Link>
                    </td>
                    <td>{p.customer_name}</td>
                    <td className="mono small">{p.account_number}</td>
                    <td>
                      {titleCase(p.method)}
                      {p.reference ? <div className="small muted mono">{p.reference}</div> : null}
                    </td>
                    <td>
                      {p.collected_by_name}
                      <div className="small muted">{dateTime(p.collected_at)}</div>
                    </td>
                    <td>
                      <StatusBadge status={p.status} />
                    </td>
                    <td className="num">{money(p.amount)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      ) : null}
      {meta ? <Pager page={meta.page} totalPages={meta.total_pages} onPage={(p) => update({ page: String(p) })} /> : null}
    </>
  )
}

import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import { ErrorBanner, Loading, PageHeader, Pager, Segmented, StatusBadge } from '../components/ui'
import { date, money } from '../format'
import { usePagedList } from '../hooks'
import type { LoanListItem } from '../types'
import { AssetClassBadge } from './Accounting'

const STATUS_FILTERS = [
  { id: '', label: 'All' },
  { id: 'pending_approval', label: 'Pending approval' },
  { id: 'approved', label: 'Approved' },
  { id: 'active', label: 'Active' },
  { id: 'closed', label: 'Closed' },
  { id: 'rejected', label: 'Rejected' },
  { id: 'written_off', label: 'Written off' },
]

export function Loans() {
  const { can } = useAuth()
  const { rows, meta, error, params, update } = usePagedList<LoanListItem>('/loans')
  const [search, setSearch] = useState(params.get('q') ?? '')

  return (
    <>
      <PageHeader
        title="Loans"
        subtitle={meta ? `${meta.total.toLocaleString('en-IN')} loans` : undefined}
        actions={
          can('loans.apply') ? (
            <Link to="/loans/new" className="btn btn--primary">
              New application
            </Link>
          ) : null
        }
      />
      <div className="toolbar">
        <Segmented options={STATUS_FILTERS} value={params.get('status') ?? ''} onChange={(status) => update({ status })} />
      </div>
      <form
        className="toolbar"
        onSubmit={(e) => {
          e.preventDefault()
          update({ q: search.trim() })
        }}
      >
        <input type="search" placeholder="Search loan number or customer" value={search} onChange={(e) => setSearch(e.target.value)} />
        <label className="checkbox">
          <input type="checkbox" checked={params.get('overdue') === '1'} onChange={(e) => update({ overdue: e.target.checked ? '1' : '' })} />
          Overdue only
        </label>
        <label className="checkbox">
          <input type="checkbox" checked={params.get('npa') === '1'} onChange={(e) => update({ npa: e.target.checked ? '1' : '' })} />
          NPA only
        </label>
      </form>

      <ErrorBanner error={error} />
      {rows === null && !error ? <Loading /> : null}
      {rows ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Loan</th>
                <th>Customer</th>
                <th>Product</th>
                <th className="num">Principal</th>
                <th className="num">Outstanding</th>
                <th className="num">Overdue</th>
                <th>Status</th>
                <th>Risk</th>
                <th>Applied</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={9} className="table__empty">
                    No loans match these filters.
                  </td>
                </tr>
              ) : (
                rows.map((l) => (
                  <tr key={l.id} className={Number(l.overdue) > 0 ? 'is-highlight' : undefined}>
                    <td>
                      <Link to={`/loans/${l.id}`} className="mono">
                        {l.loan_number}
                      </Link>
                    </td>
                    <td style={{ whiteSpace: 'normal', minWidth: 120 }}>
                      {l.customer_name}
                      <div className="small muted mono">{l.customer_number}</div>
                    </td>
                    <td style={{ whiteSpace: 'normal', minWidth: 140 }}>{l.product_name}</td>
                    <td className="num">{money(l.principal)}</td>
                    <td className="num">{['active', 'closed', 'written_off'].includes(l.status) ? money(l.outstanding) : '—'}</td>
                    <td className="num">{Number(l.overdue) > 0 ? money(l.overdue) : '—'}</td>
                    <td>
                      <StatusBadge status={l.status} />
                    </td>
                    <td>{l.status === 'active' || l.status === 'written_off' ? <AssetClassBadge value={l.asset_class} /> : '—'}{Number(l.dpd) > 0 ? <div className="small muted">{l.dpd} DPD</div> : null}</td>
                    <td>{date(l.applied_at)}</td>
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

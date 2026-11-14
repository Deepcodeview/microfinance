import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { ErrorBanner, Loading, Modal, PageHeader, StatusBadge, Tabs } from '../components/ui'
import { date, moneyShort, titleCase } from '../format'
import { loanTenureSummary, rateSummary } from '../plans'
import type { LoanProduct } from '../types'

/**
 * Super Admin only. A plan nobody has used can be deleted; once any account was opened on it, its terms are
 * part of those accounts' history, so it can only be deactivated.
 */
export function DeletePlanButton({ kind, product }: { kind: 'loan' | 'deposit'; product: { id: string; name: string; accounts_count: string } }) {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [confirming, setConfirming] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const [deleting, setDeleting] = useState(false)
  if (!can('products.delete')) return null
  const used = Number(product.accounts_count)
  const list = kind === 'loan' ? '/loan-products' : '/deposit-products'

  async function remove() {
    setDeleting(true)
    setError(null)
    try {
      await api.delete(`${list}/${product.id}`)
      navigate(list, { replace: true })
    } catch (err) {
      setError(err)
      setDeleting(false)
    }
  }

  return (
    <>
      <button
        type="button"
        className="btn btn--danger"
        disabled={used > 0}
        title={used > 0 ? `${used} account(s) use this plan. Deactivate it instead.` : undefined}
        onClick={() => setConfirming(true)}
      >
        Delete
      </button>
      {confirming ? (
        <Modal title="Delete plan" onClose={() => setConfirming(false)}>
          <div className="stack">
            <ErrorBanner error={error} />
            <p>{`Delete "${product.name}" permanently? No account uses it, so nothing else changes. This cannot be undone.`}</p>
            <div className="modal__footer">
              <button type="button" className="btn btn--ghost" onClick={() => setConfirming(false)}>Cancel</button>
              <button type="button" className="btn btn--danger-solid" disabled={deleting} onClick={remove}>{deleting ? 'Deleting…' : 'Delete plan'}</button>
            </div>
          </div>
        </Modal>
      ) : null}
    </>
  )
}

/** Switch between loan and deposit product catalogues. */
export function ProductTabs({ active }: { active: 'loan' | 'deposit' }) {
  const { user } = useAuth()
  const navigate = useNavigate()
  if (!user?.permissions.includes('deposits.view')) return null
  return (
    <Tabs
      tabs={[
        { id: 'loan', label: 'Loan plans' },
        { id: 'deposit', label: 'Savings plans' },
      ]}
      value={active}
      onChange={(id) => navigate(id === 'loan' ? '/loan-products' : '/deposit-products')}
    />
  )
}

export function LoanProducts() {
  const { can } = useAuth()
  const [products, setProducts] = useState<LoanProduct[] | null>(null)
  const [error, setError] = useState<unknown>(null)

  useEffect(() => {
    api.get<LoanProduct[]>('/loan-products').then(setProducts).catch(setError)
  }, [])

  return (
    <>
      <PageHeader
        title="Loan &amp; savings plans"
        subtitle="Interest, fees and repayment rules. Changing terms creates a new version; existing loans keep theirs."
        actions={
          can('loan_products.manage') ? (
            <Link to="/loan-products/new" className="btn btn--primary">
              New loan plan
            </Link>
          ) : null
        }
      />
      <ProductTabs active="loan" />
      <ErrorBanner error={error} />
      {products === null && !error ? <Loading /> : null}
      {products ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th className="num">Rate p.a.</th>
                <th>Frequency</th>
                <th className="num">Amount range</th>
                <th className="num">Tenure</th>
                <th className="num">Accounts</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {products.length === 0 ? (
                <tr>
                  <td colSpan={8} className="table__empty">
                    No loan products yet.
                  </td>
                </tr>
              ) : (
                products.map((p) => (
                  <tr key={p.id}>
                    <td>
                      <Link to={`/loan-products/${p.id}`} className="mono">
                        {p.code}
                      </Link>
                    </td>
                    <td>
                      {p.name}
                      <div className="muted small">v{p.version} since {date(p.effective_from)}</div>
                    </td>
                    <td className="num">
                      {rateSummary(p.terms.annual_interest_rate, p.terms.tenure_options).replace(' a year', '').replace(', by tenure', '')}
                      <div className="muted small">{titleCase(p.terms.interest_method)}</div>
                    </td>
                    <td>{titleCase(p.terms.repayment_frequency)}</td>
                    <td className="num">
                      {moneyShort(p.terms.min_amount)} – {moneyShort(p.terms.max_amount)}
                    </td>
                    <td className="num">{loanTenureSummary(p.terms)}</td>
                    <td className="num">{p.accounts_count}</td>
                    <td>
                      <StatusBadge status={p.status} />
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      ) : null}
    </>
  )
}

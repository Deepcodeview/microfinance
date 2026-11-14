import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { ErrorBanner, Loading, PageHeader, StatusBadge } from '../components/ui'
import { date } from '../format'
import type { CustomerListItem, PageMeta } from '../types'

export function Customers() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const [rows, setRows] = useState<CustomerListItem[] | null>(null)
  const [meta, setMeta] = useState<PageMeta | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [search, setSearch] = useState(params.get('q') ?? '')

  const query = params.toString()
  useEffect(() => {
    let cancelled = false
    setError(null)
    api
      .getPage<CustomerListItem[], PageMeta>(`/customers?${query}`)
      .then((result) => {
        if (cancelled) return
        setRows(result.data)
        setMeta(result.meta)
      })
      .catch((err) => !cancelled && setError(err))
    return () => {
      cancelled = true
    }
  }, [query])

  function update(changes: Record<string, string>) {
    const next = new URLSearchParams(params)
    for (const [key, value] of Object.entries(changes)) {
      if (value) next.set(key, value)
      else next.delete(key)
    }
    if (!('page' in changes)) next.delete('page')
    setParams(next)
  }

  const page = meta?.page ?? 1

  return (
    <>
      <PageHeader
        title="Customers"
        subtitle={meta ? `${meta.total.toLocaleString('en-IN')} customers` : undefined}
        actions={
          can('customers.create') ? (
            <Link to="/customers/new" className="btn btn--primary">
              New customer
            </Link>
          ) : null
        }
      />

      <form
        className="toolbar"
        onSubmit={(e) => {
          e.preventDefault()
          update({ q: search.trim() })
        }}
      >
        <input
          type="search"
          placeholder="Search name, phone or customer number"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <select value={params.get('status') ?? ''} onChange={(e) => update({ status: e.target.value })}>
          <option value="">All statuses</option>
          <option value="pending">Pending approval</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="blocked">Blocked</option>
        </select>
        <button type="submit" className="btn">
          Search
        </button>
      </form>

      <ErrorBanner error={error} />
      {rows === null && !error ? <Loading /> : null}

      {rows ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Customer no.</th>
                <th>Name</th>
                <th>Phone</th>
                <th>City</th>
                <th>Branch</th>
                <th>Agent</th>
                <th>Status</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={8} className="table__empty">
                    No customers found.
                  </td>
                </tr>
              ) : (
                rows.map((c) => (
                  <tr key={c.id}>
                    <td>
                      <Link to={`/customers/${c.id}`} className="mono">
                        {c.customer_number}
                      </Link>
                    </td>
                    <td>{c.full_name}</td>
                    <td className="mono">{c.phone}</td>
                    <td>{c.city}</td>
                    <td>{c.branch_name}</td>
                    <td>{c.agent_name ?? <span className="muted">Unassigned</span>}</td>
                    <td>
                      <StatusBadge status={c.status} />
                    </td>
                    <td>{date(c.created_at)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      ) : null}

      {meta && meta.total_pages > 1 ? (
        <div className="pager">
          <button type="button" className="btn btn--small" disabled={page <= 1} onClick={() => update({ page: String(page - 1) })}>
            Previous
          </button>
          <span>
            Page {page} of {meta.total_pages}
          </span>
          <button
            type="button"
            className="btn btn--small"
            disabled={page >= meta.total_pages}
            onClick={() => update({ page: String(page + 1) })}
          >
            Next
          </button>
        </div>
      ) : null}
    </>
  )
}

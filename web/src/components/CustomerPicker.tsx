import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import type { CustomerListItem } from '../types'

/**
 * Searchable customer chooser limited to the user's scope. Results are tappable rows rather than a
 * <select>: Android WebViews render a listbox as a dropdown whose first entry cannot be chosen.
 */
export function CustomerPicker({ value, onChange }: { value: string; onChange: (id: string) => void }) {
  const { user, can } = useAuth()
  const [query, setQuery] = useState('')
  const [options, setOptions] = useState<CustomerListItem[] | null>(null)
  const [selected, setSelected] = useState<CustomerListItem | null>(null)

  useEffect(() => {
    if (value && !selected) {
      api
        .get<{ id: string; full_name: string; customer_number: string; phone: string }>(`/customers/${value}`)
        .then((c) => setSelected(c as unknown as CustomerListItem))
        .catch(() => {})
    }
  }, [value, selected])

  useEffect(() => {
    const timer = setTimeout(() => {
      api
        .getPage<CustomerListItem[], unknown>(`/customers?status=active&per_page=20&q=${encodeURIComponent(query)}`)
        .then((r) => setOptions(r.data))
        .catch(() => setOptions([]))
    }, 250)
    return () => clearTimeout(timer)
  }, [query])

  if (selected && selected.id === value) {
    return (
      <div className="row row--between" style={{ border: '1px solid var(--border)', borderRadius: 8, padding: '8px 12px' }}>
        <span>
          {selected.full_name} <span className="muted mono small">{selected.customer_number}</span>
        </span>
        <button
          type="button"
          className="btn btn--ghost btn--small"
          onClick={() => {
            setSelected(null)
            onChange('')
          }}
        >
          Change
        </button>
      </div>
    )
  }

  return (
    <div className="stack" style={{ gap: 6 }}>
      <input type="search" placeholder="Search by name, phone or customer number" value={query} onChange={(e) => setQuery(e.target.value)} />
      {options === null ? (
        <p className="small muted">Loading customers…</p>
      ) : options.length === 0 ? (
        <div className="picker-empty">
          <p className="small muted">
            {query ? 'No customer matches this search.' : 'No customers yet.'}
            {user?.customer_scope === 'assigned' ? ' Only customers assigned to you are shown — customers you add are assigned to you automatically.' : ''}
          </p>
          {can('customers.create') ? <Link to="/customers/new" className="btn btn--small">+ Add new customer</Link> : null}
        </div>
      ) : (
        <div className="picker-list" role="listbox" aria-label="Customers">
          {options.map((c) => (
            <button
              key={c.id}
              type="button"
              role="option"
              aria-selected={false}
              className="picker-option"
              onClick={() => {
                setSelected(c)
                onChange(c.id)
              }}
            >
              <span className="picker-option__name">{c.full_name}</span>
              <span className="small muted mono">{c.customer_number} · {c.phone}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

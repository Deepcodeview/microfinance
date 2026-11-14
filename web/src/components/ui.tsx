import { useEffect, useId, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { IS_APP } from '../config'
import { ApiError } from '../api'
import { titleCase } from '../format'
import { Icons } from './icons'

export function Field({ label, error, hint, children }: { label: string; error?: string; hint?: ReactNode; children: ReactNode }) {
  return (
    <label className={`field${error ? ' field--error' : ''}`}>
      <span className="field__label">{label}</span>
      {children}
      {error ? <span className="field__error">{error}</span> : hint ? <span className="field__hint">{hint}</span> : null}
    </label>
  )
}

const TONES: Record<string, string> = {
  active: 'success',
  confirmed: 'success',
  verified: 'success',
  paid: 'success',
  resolved: 'success',
  approved: 'info',
  matured: 'info',
  closed: '',
  pending: 'warning',
  pending_approval: 'warning',
  submitted: 'warning',
  requested: 'warning',
  partial: 'warning',
  pending_closure: 'warning',
  discrepancy: 'danger',
  overdue: 'danger',
  queued: 'warning',
  sent: 'success',
  open: 'warning',
  in_progress: 'info',
  rejected: 'danger',
  failed: 'danger',
  reversed: 'danger',
  blocked: 'danger',
  disabled: 'danger',
  inactive: '',
  cancelled: '',
}

export function StatusBadge({ status }: { status: string }) {
  const tone = TONES[status] ?? ''
  return <span className={`badge${tone ? ` badge--${tone}` : ''}`}>{titleCase(status)}</span>
}

export function ErrorBanner({ error }: { error: unknown }) {
  if (!error) return null
  const message = error instanceof Error ? error.message : 'Something went wrong.'
  const ref = error instanceof ApiError && error.requestId ? ` (ref ${error.requestId.slice(0, 8)})` : ''
  return (
    <div className="banner banner--error" role="alert">
      {message}
      {ref}
    </div>
  )
}

export function Notice({ tone = 'success', children }: { tone?: 'success' | 'info' | 'warning'; children: ReactNode }) {
  return (
    <div className={`banner banner--${tone}`} role="status">
      {children}
    </div>
  )
}

export function PageHeader({
  title,
  subtitle,
  actions,
  back,
}: {
  title: ReactNode
  subtitle?: ReactNode
  actions?: ReactNode
  back?: { to: string; label: string }
}) {
  const navigate = useNavigate()
  return (
    <header className="page-header">
      <div>
        {back && IS_APP ? (
          <button type="button" className="back-link back-link--app" onClick={() => (window.history.length > 1 ? navigate(-1) : navigate('/'))}>
            <Icons.arrowLeft /> Back
          </button>
        ) : back ? (
          <Link to={back.to} className="back-link">
            <Icons.arrowLeft /> {back.label}
          </Link>
        ) : null}
        <h1>{title}</h1>
        {subtitle ? <div className="page-header__subtitle">{subtitle}</div> : null}
      </div>
      {actions ? <div className="page-header__actions">{actions}</div> : null}
    </header>
  )
}

export function Loading() {
  return <div className="loading">Loading…</div>
}

export function Empty({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="empty">
      <div className="empty__title">{title}</div>
      {children}
    </div>
  )
}

export function Modal({
  title,
  onClose,
  children,
  wide,
}: {
  title: string
  onClose: () => void
  children: ReactNode
  wide?: boolean
}) {
  const id = useId()
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="modal-backdrop" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className={`modal${wide ? ' modal--wide' : ''}`} role="dialog" aria-modal="true" aria-labelledby={id}>
        <div className="modal__header">
          <h2 className="modal__title" id={id}>
            {title}
          </h2>
          <button type="button" className="icon-btn" onClick={onClose} aria-label="Close">
            <Icons.close />
          </button>
        </div>
        <div className="modal__body">{children}</div>
      </div>
    </div>
  )
}

export function Tabs<T extends string>({ tabs, value, onChange }: { tabs: { id: T; label: string }[]; value: T; onChange: (id: T) => void }) {
  return (
    <div className="tabs" role="tablist">
      {tabs.map((t) => (
        <button key={t.id} type="button" role="tab" aria-selected={value === t.id} onClick={() => onChange(t.id)}>
          {t.label}
        </button>
      ))}
    </div>
  )
}

export function Segmented<T extends string>({ options, value, onChange }: { options: { id: T; label: string }[]; value: T; onChange: (id: T) => void }) {
  return (
    <div className="segmented">
      {options.map((o) => (
        <button key={o.id} type="button" aria-pressed={value === o.id} onClick={() => onChange(o.id)}>
          {o.label}
        </button>
      ))}
    </div>
  )
}

export function Pager({ page, totalPages, onPage }: { page: number; totalPages: number; onPage: (page: number) => void }) {
  if (totalPages <= 1) return null
  return (
    <div className="pager">
      <button type="button" className="btn btn--small" disabled={page <= 1} onClick={() => onPage(page - 1)}>
        Previous
      </button>
      <span>
        Page {page} of {totalPages}
      </span>
      <button type="button" className="btn btn--small" disabled={page >= totalPages} onClick={() => onPage(page + 1)}>
        Next
      </button>
    </div>
  )
}

export function Details({ rows, compact }: { rows: [ReactNode, ReactNode][]; compact?: boolean }) {
  return (
    <dl className={`details${compact ? ' details--compact' : ''}`}>
      {rows.map(([label, value], i) => (
        <div key={i} className="details__row">
          <dt>{label}</dt>
          <dd>{value}</dd>
        </div>
      ))}
    </dl>
  )
}

/** Field errors from a 422 response, or an empty object. */
export function fieldErrors(error: unknown): Record<string, string> {
  return error instanceof ApiError ? error.fields : {}
}

/** Banner text for an error that isn't fully explained by field messages. */
export function bannerError(error: unknown): unknown {
  if (error instanceof ApiError && error.status === 422 && Object.keys(error.fields).length > 0) {
    return new Error('Please correct the highlighted fields.')
  }
  return error
}

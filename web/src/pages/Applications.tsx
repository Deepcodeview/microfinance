import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { Details, Empty, ErrorBanner, Field, Loading, Modal, Notice, PageHeader, Pager, Segmented, StatusBadge, Tabs, bannerError, fieldErrors } from '../components/ui'
import { date, dateTime, money, titleCase } from '../format'
import { useLocalPagedList } from '../hooks'
import { tenureLabel } from '../plans'
import type { Agent, CustomerApplication, CustomerListItem } from '../types'

type Tab = 'registrations' | 'requests'
type StatusFilter = 'submitted' | 'accepted' | 'rejected' | ''

/**
 * Work that arrives from the customer app: people who registered themselves (approve before anything
 * is opened for them) and their loan / savings applications (accept into the normal loan or savings flow).
 */
export function Applications() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const canApprove = can('customers.approve')
  const [notice, setNotice] = useState<ReactNode>(null)
  const registrations = useLocalPagedList<CustomerListItem>(canApprove ? '/customers?status=pending&sort=created_at&direction=asc' : null)
  const pendingCount = registrations.meta?.total ?? 0
  // New registrations come first while any are waiting, since their applications cannot be accepted before.
  const requested = params.get('tab')
  const tab: Tab = canApprove && (requested === 'registrations' || (!requested && pendingCount > 0)) ? 'registrations' : 'requests'

  const tabs: { id: Tab; label: string }[] = [{ id: 'requests', label: 'Loan & savings requests' }]
  if (canApprove) tabs.push({ id: 'registrations', label: `New registrations${pendingCount ? ` (${pendingCount})` : ''}` })

  return (
    <>
      <PageHeader title="Applications" subtitle="Registrations and applications made by customers in the KBSAVE app." />
      {notice ? <Notice>{notice}</Notice> : null}
      {tabs.length > 1 ? <Tabs tabs={tabs} value={tab} onChange={(id) => { setNotice(null); setParams({ tab: id }) }} /> : null}
      {tab === 'registrations' ? (
        <>
          <Registrations rows={registrations.rows} error={registrations.error} reload={registrations.reload} onNotice={setNotice} />
          {registrations.meta ? <Pager page={registrations.page} totalPages={registrations.meta.total_pages} onPage={registrations.setPage} /> : null}
        </>
      ) : (
        <Requests onNotice={setNotice} reloadRegistrations={registrations.reload} />
      )}
    </>
  )
}

// ---- New registrations -----------------------------------------------------------------------

function Registrations({ rows, error, reload, onNotice }: { rows: CustomerListItem[] | null; error: unknown; reload: () => void; onNotice: (n: ReactNode) => void }) {
  const [approving, setApproving] = useState<CustomerListItem | null>(null)
  const [rejecting, setRejecting] = useState<CustomerListItem | null>(null)
  if (!rows) return error ? <ErrorBanner error={error} /> : <Loading />
  if (rows.length === 0) return <div className="card"><Empty title="No new registrations">People who sign up in the customer app appear here for approval.</Empty></div>
  return (
    <>
      <div className="review-list">
        {rows.map((c) => (
          <div key={c.id} className="card review-card">
            <div className="row row--between row--wrap">
              <div>
                <Link to={`/customers/${c.id}`} className="review-card__title">{c.full_name}</Link>
                <div className="small muted mono">{c.customer_number} · registered {dateTime(c.created_at)}</div>
              </div>
              <StatusBadge status={c.status} />
            </div>
            <Details compact rows={[
              ['Mobile', <a href={`tel:${c.phone}`} className="mono">{c.phone}</a>],
              ['Email', c.email || '—'],
              ['Address', [c.address_line, c.city, c.state, c.pincode].filter(Boolean).join(', ')],
              ['Work', c.occupation || '—'],
              ['Branch', c.branch_name],
            ]} />
            <p className="small muted">Check their KYC (Aadhaar / PAN) on the <Link to={`/customers/${c.id}`}>customer page</Link> before approving.</p>
            <div className="row row--wrap" style={{ gap: 8 }}>
              <button type="button" className="btn btn--primary" onClick={() => setApproving(c)}>Approve</button>
              <button type="button" className="btn btn--danger" onClick={() => setRejecting(c)}>Reject</button>
            </div>
          </div>
        ))}
      </div>
      {approving ? (
        <ApproveRegistrationModal
          customer={approving}
          onClose={() => setApproving(null)}
          onDone={() => {
            onNotice(<>{approving.full_name} is approved and can now get loans and savings. Their waiting applications are under <b>Loan &amp; savings requests</b>.</>)
            setApproving(null)
            reload()
          }}
        />
      ) : null}
      {rejecting ? (
        <ReasonModal
          title={`Reject ${rejecting.full_name}`}
          hint="The customer sees this reason in the app."
          action="Reject registration"
          path={`/customers/${rejecting.id}/reject-registration`}
          onClose={() => setRejecting(null)}
          onDone={() => {
            onNotice(`${rejecting.full_name}'s registration was rejected.`)
            setRejecting(null)
            reload()
          }}
        />
      ) : null}
    </>
  )
}

function ApproveRegistrationModal({ customer, onClose, onDone }: { customer: CustomerListItem; onClose: () => void; onDone: () => void }) {
  const { can } = useAuth()
  const [agents, setAgents] = useState<Agent[]>([])
  const [agentId, setAgentId] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  useEffect(() => {
    if (can('customers.assign')) api.get<Agent[]>('/agents').then(setAgents).catch(() => setAgents([]))
  }, [can])
  const branchAgents = agents.filter((a) => a.branch_name === customer.branch_name)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      await api.post(`/customers/${customer.id}/approve-registration`, agentId ? { agent_user_id: Number(agentId) } : {})
      onDone()
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={`Approve ${customer.full_name}`} onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <ErrorBanner error={bannerError(error)} />
        <p>The customer becomes <b>Active</b>: their applications can be accepted and staff can open loans and savings for them.</p>
        {can('customers.assign') ? (
          <Field label="Collection agent" error={fieldErrors(error).agent_user_id} hint="The agent sees this customer in the Agent app and collects from them.">
            <select value={agentId} onChange={(e) => setAgentId(e.target.value)}>
              <option value="">Assign later</option>
              {branchAgents.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </select>
          </Field>
        ) : null}
        <div className="form__actions">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={saving}>{saving ? 'Approving…' : 'Approve registration'}</button>
        </div>
      </form>
    </Modal>
  )
}

// ---- Loan & savings requests -----------------------------------------------------------------

function Requests({ onNotice, reloadRegistrations }: { onNotice: (n: ReactNode) => void; reloadRegistrations: () => void }) {
  const { can } = useAuth()
  const [status, setStatus] = useState<StatusFilter>('submitted')
  const { rows: data, meta, page, setPage, error, reload } = useLocalPagedList<CustomerApplication>(`/applications${status ? `?status=${status}` : ''}`)
  const [accepting, setAccepting] = useState<CustomerApplication | null>(null)
  const [rejecting, setRejecting] = useState<CustomerApplication | null>(null)

  return (
    <>
      <div className="toolbar">
        <Segmented<StatusFilter>
          options={[{ id: 'submitted', label: 'Waiting' }, { id: 'accepted', label: 'Accepted' }, { id: 'rejected', label: 'Rejected' }, { id: '', label: 'All' }]}
          value={status}
          onChange={setStatus}
        />
      </div>
      <ErrorBanner error={error} />
      {!data ? (error ? null : <Loading />) : data.length === 0 ? (
        <div className="card"><Empty title={status === 'submitted' ? 'Nothing waiting' : 'No applications'}>Applications customers send from the app appear here.</Empty></div>
      ) : (
        <div className="review-list">
          {data.map((a) => (
            <div key={a.id} className="card review-card">
              <div className="row row--between row--wrap">
                <div>
                  <span className="review-card__title">{a.type === 'loan' ? 'Loan' : 'Savings'} · {money(a.amount)}</span>
                  <div className="small muted mono">{a.application_number} · {dateTime(a.created_at)}</div>
                </div>
                <StatusBadge status={a.status} />
              </div>
              <Details compact rows={[
                ['Customer', <><Link to={`/customers/${a.customer_id}`}>{a.customer_name}</Link> <span className="muted mono small">{a.customer_number}</span></>],
                ['Plan', a.product_name ?? '—'],
                ['Amount / tenure', a.type === 'loan' ? `${money(a.amount)} × ${a.tenure} installments` : `${money(a.amount)}${savingsTenure(a)}`],
                ['Purpose', a.purpose || '—'],
                ['Branch', a.branch_name],
                ...(a.status !== 'submitted' ? [[titleCase(a.status), `${a.decided_by_name ?? ''} ${a.decided_at ? `· ${date(a.decided_at)}` : ''}${a.decision_note ? ` · ${a.decision_note}` : ''}`] as [string, string]] : []),
                ...(a.loan_id ? [['Loan', <Link to={`/loans/${a.loan_id}`}>{a.loan_number} ({titleCase(a.loan_status ?? '')})</Link>] as [string, ReactNode]] : []),
                ...(a.deposit_account_id ? [['Savings account', <Link to={`/deposits/${a.deposit_account_id}`}>{a.deposit_number}</Link>] as [string, ReactNode]] : []),
              ]} />
              {a.status === 'submitted' ? (
                <>
                  {a.customer_status === 'pending' ? (
                    <Notice tone="warning">
                      Registration not approved yet.{' '}
                      {can('customers.approve') ? <Link to="/applications?tab=registrations">Approve the registration first</Link> : 'Ask a manager to approve the registration first.'}
                    </Notice>
                  ) : null}
                  <div className="row row--wrap" style={{ gap: 8 }}>
                    <button type="button" className="btn btn--primary" disabled={a.customer_status !== 'active'} onClick={() => setAccepting(a)}>
                      {a.type === 'loan' ? 'Accept → loan application' : 'Accept → open account'}
                    </button>
                    <button type="button" className="btn btn--danger" onClick={() => setRejecting(a)}>Reject</button>
                  </div>
                </>
              ) : null}
            </div>
          ))}
        </div>
      )}
      {meta ? <Pager page={page} totalPages={meta.total_pages} onPage={setPage} /> : null}
      {accepting ? (
        <AcceptModal
          app={accepting}
          onClose={() => setAccepting(null)}
          onDone={(result) => {
            onNotice(result.loan_id
              ? <>Accepted. Loan application <Link to={`/loans/${result.loan_id}`}>{result.loan_number}</Link> now goes to approval, then disbursement.</>
              : <>Accepted. Savings account <Link to={`/deposits/${result.deposit_account_id}`}>{result.deposit_number}</Link> is open.</>)
            setAccepting(null)
            reload()
            reloadRegistrations()
          }}
        />
      ) : null}
      {rejecting ? (
        <ReasonModal
          title={`Reject ${rejecting.application_number}`}
          hint="The customer sees this reason in the app."
          action="Reject application"
          path={`/applications/${rejecting.id}/reject`}
          onClose={() => setRejecting(null)}
          onDone={() => {
            onNotice(`${rejecting.application_number} was rejected.`)
            setRejecting(null)
            reload()
          }}
        />
      ) : null}
    </>
  )
}

function AcceptModal({ app, onClose, onDone }: { app: CustomerApplication; onClose: () => void; onDone: (result: CustomerApplication) => void }) {
  const [note, setNote] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      onDone(await api.post<CustomerApplication>(`/applications/${app.id}/accept`, note ? { note } : {}))
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Modal title={`Accept ${app.application_number}`} onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <ErrorBanner error={error} />
        <p>
          {app.type === 'loan'
            ? <>Creates a loan application for <b>{app.customer_name}</b> — {money(app.amount)} × {app.tenure} installments on {app.product_name}. It then needs approval (by someone else, unless single-owner approval is on) and disbursement.</>
            : <>Opens a <b>{app.product_name}</b> savings account for <b>{app.customer_name}</b> — {money(app.amount)}{savingsTenure(app)}.</>}
        </p>
        <Field label="Note (optional)"><input value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} placeholder="e.g. Verified at home visit" /></Field>
        <div className="form__actions">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={saving}>{saving ? 'Accepting…' : 'Accept'}</button>
        </div>
      </form>
    </Modal>
  )
}

function ReasonModal({ title, hint, action, path, onClose, onDone }: { title: string; hint: string; action: string; path: string; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      await api.post(path, { reason })
      onDone()
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Modal title={title} onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <ErrorBanner error={bannerError(error)} />
        <Field label="Reason *" error={fieldErrors(error).reason} hint={hint}>
          <input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} autoFocus />
        </Field>
        <div className="form__actions">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--danger-solid" disabled={saving || reason.trim().length < 3}>{saving ? 'Saving…' : action}</button>
        </div>
      </form>
    </Modal>
  )
}

const savingsTenure = (a: CustomerApplication) => (a.tenure && a.tenure_unit ? ` for ${tenureLabel({ value: Number(a.tenure), unit: a.tenure_unit })}` : '')

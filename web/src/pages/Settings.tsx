import { useEffect, useState, type FormEvent } from 'react'
import { api } from '../api'
import { useAuth } from '../auth'
import { ErrorBanner, Field, Loading, Modal, Notice, PageHeader, Pager, StatusBadge, bannerError, fieldErrors } from '../components/ui'
import { dateTime, date as fmtDate, titleCase } from '../format'
import { usePagedList } from '../hooks'
import { useFetch } from '../hooks'
import type { Branch } from '../types'

interface SettingsData {
  company_name: string
  business_timezone: string
  deposits_enabled: string
  owner_self_approval?: string
  weekly_off_days: string
  mfa_required_for_privileged: string
  loan_approval_limit: string
  max_customer_exposure: string
  agent_cash_limit: string
  provision_rate_standard: string
  provision_rate_substandard: string
  provision_rate_doubtful: string
  provision_rate_loss: string
  closed_through: string
  allow_bank_overdraft: string
  require_collection_location?: string
  upi_vpa: string
  upi_payee_name: string
  business_date: string
}

export function Settings() {
  const { refresh } = useAuth()
  const { data, error, setData } = useFetch<SettingsData>('/settings')
  const { data: branches, reload: reloadBranches } = useFetch<Branch[]>('/branches')
  const [company, setCompany] = useState('')
  const [timezone, setTimezone] = useState('')
  const [saveError, setSaveError] = useState<unknown>(null)
  const [notice, setNotice] = useState('')
  const [toggling, setToggling] = useState(false)
  const [branchCode, setBranchCode] = useState('')
  const [branchName, setBranchName] = useState('')
  const [branchError, setBranchError] = useState<unknown>(null)

  useEffect(() => {
    if (data) {
      setCompany(data.company_name)
      setTimezone(data.business_timezone)
    }
  }, [data])

  if (!data) return error ? <ErrorBanner error={error} /> : <Loading />

  async function saveGeneral(e: FormEvent) {
    e.preventDefault()
    setSaveError(null)
    try {
      setData(await api.patch<SettingsData>('/settings', { company_name: company, business_timezone: timezone }))
      await refresh()
      setNotice('Settings saved.')
    } catch (err) {
      setSaveError(err)
    }
  }

  async function addBranch(e: FormEvent) {
    e.preventDefault()
    setBranchError(null)
    try {
      await api.post('/branches', { code: branchCode.trim().toUpperCase(), name: branchName })
      setBranchCode('')
      setBranchName('')
      reloadBranches()
      setNotice('Branch added.')
    } catch (err) {
      setBranchError(err)
    }
  }

  const depositsOn = data.deposits_enabled === '1'

  return (
    <>
      <PageHeader title="Settings" />
      {notice ? <Notice>{notice}</Notice> : null}

      <div className="grid grid--2">
        <form className="card stack" onSubmit={saveGeneral}>
          <h2 className="card__title">General</h2>
          <ErrorBanner error={bannerError(saveError)} />
          <Field label="Company name" error={fieldErrors(saveError).company_name}>
            <input value={company} onChange={(e) => setCompany(e.target.value)} />
          </Field>
          <p className="small muted">{`Business date ${data.business_date} · India time (IST). Dues, collections and the ledger follow this date.`}</p>
          <div className="row" style={{ justifyContent: 'flex-end' }}>
            <button type="submit" className="btn btn--primary">Save</button>
          </div>
        </form>

        <section className="card stack">
          <h2 className="card__title">Compliance gate: deposits</h2>
          <p className="muted">
            Accepting deposits and promising returns requires the right legal authorisation. Keep this off until your legal adviser has approved the products and workflow.
          </p>
          <div className="row row--between">
            <div>
              <strong>Deposit-taking</strong>
              <div className="small muted">{depositsOn ? 'Enabled — accounts, contributions and payouts are live.' : 'Disabled — no deposit accounts can be opened or funded.'}</div>
            </div>
            <label className="toggle">
              <input type="checkbox" checked={depositsOn} onChange={() => setToggling(true)} aria-label="Deposit-taking enabled" />
            </label>
          </div>
        </section>
      </div>

      <UpiSettings settings={data} onSaved={async (s) => { setData(s); await refresh(); setNotice('UPI collection details saved.') }} />
      <LimitsSettings settings={data} onSaved={(s) => { setData(s); setNotice('Limits and provisioning rates saved.') }} />

      <section className="card" style={{ marginTop: 16 }}>
        <h2 className="card__title" style={{ marginBottom: 12 }}>Branches</h2>
        <table className="table table--compact">
          <thead><tr><th>Code</th><th>Name</th></tr></thead>
          <tbody>
            {(branches ?? []).map((b) => <tr key={b.id}><td className="mono">{b.code}</td><td>{b.name}</td></tr>)}
          </tbody>
        </table>
        <form className="row row--wrap" style={{ marginTop: 14, alignItems: 'flex-end' }} onSubmit={addBranch}>
          <Field label="Code" error={fieldErrors(branchError).code}>
            <input value={branchCode} onChange={(e) => setBranchCode(e.target.value)} placeholder="BR02" style={{ width: 120 }} />
          </Field>
          <Field label="Name" error={fieldErrors(branchError).name}>
            <input value={branchName} onChange={(e) => setBranchName(e.target.value)} placeholder="Market Road Branch" style={{ minWidth: 260 }} />
          </Field>
          <button type="submit" className="btn">Add branch</button>
        </form>
      </section>

      <div className="grid grid--2" style={{ marginTop: 16 }}>
        <CalendarSettings settings={data} onSaved={(s) => { setData(s); setNotice('Calendar saved.') }} />
        <SecuritySettings settings={data} onSaved={async (s) => { setData(s); await refresh(); setNotice('Security policy saved.') }} />
      </div>
      <Outbox />

      {toggling ? (
        <DepositToggleModal
          enable={!depositsOn}
          onClose={() => setToggling(false)}
          onDone={async (updated) => {
            setData(updated)
            setToggling(false)
            await refresh()
            setNotice(updated.deposits_enabled === '1' ? 'Deposit-taking enabled.' : 'Deposit-taking disabled.')
          }}
        />
      ) : null}
    </>
  )
}

function DepositToggleModal({ enable, onClose, onDone }: { enable: boolean; onClose: () => void; onDone: (s: SettingsData) => void }) {
  const [reason, setReason] = useState('')
  const [confirmed, setConfirmed] = useState(false)
  const [error, setError] = useState<unknown>(null)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      onDone(await api.patch<SettingsData>('/settings', { deposits_enabled: enable, reason }))
    } catch (err) {
      setError(err)
    }
  }

  return (
    <Modal title={enable ? 'Enable deposit-taking' : 'Disable deposit-taking'} onClose={onClose}>
      <form onSubmit={submit} className="stack">
        {enable ? (
          <div className="banner banner--warning">Only enable this once the business holds the required authorisation and the deposit products, rates and payout rules have been legally approved.</div>
        ) : (
          <p>Existing deposit accounts stay visible but no new accounts, contributions or payouts can be processed.</p>
        )}
        <ErrorBanner error={bannerError(error)} />
        <Field label="Reason / approval reference *" error={fieldErrors(error).reason}>
          <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder={enable ? 'e.g. Legal approval LEG-2026-14' : ''} />
        </Field>
        {enable ? (
          <label className="checkbox">
            <input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} />
            I confirm legal approval has been obtained.
          </label>
        ) : null}
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={enable && !confirmed}>{enable ? 'Enable' : 'Disable'}</button>
        </div>
      </form>
    </Modal>
  )
}

const WEEKDAYS = [['1', 'Mon'], ['2', 'Tue'], ['3', 'Wed'], ['4', 'Thu'], ['5', 'Fri'], ['6', 'Sat'], ['7', 'Sun']]

function CalendarSettings({ settings, onSaved }: { settings: SettingsData; onSaved: (s: SettingsData) => void }) {
  const { data, reload } = useFetch<{ weekly_off_days: string; holidays: { holiday_date: string; name: string }[] }>('/holidays')
  const [off, setOff] = useState<string[]>(settings.weekly_off_days.split(',').filter(Boolean))
  const [day, setDay] = useState('')
  const [name, setName] = useState('')
  const [error, setError] = useState<unknown>(null)

  async function saveOff(next: string[]) {
    setOff(next)
    try {
      onSaved(await api.patch<SettingsData>('/settings', { weekly_off_days: [...next].sort().join(',') }))
    } catch (err) {
      setError(err)
    }
  }

  async function add(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.post('/holidays', { holiday_date: day, name })
      setDay('')
      setName('')
      reload()
    } catch (err) {
      setError(err)
    }
  }

  return (
    <section className="card stack">
      <h2 className="card__title">Business calendar</h2>
      <p className="small muted">Loan products set to "move to next business day" skip these days when schedules are created. Existing schedules are not changed.</p>
      <div>
        <div className="field__label" style={{ marginBottom: 6 }}>Weekly off-days</div>
        <div className="row row--wrap">
          {WEEKDAYS.map(([n, label]) => (
            <label key={n} className="checkbox">
              <input type="checkbox" checked={off.includes(n)} onChange={(e) => saveOff(e.target.checked ? [...off, n] : off.filter((x) => x !== n))} />
              {label}
            </label>
          ))}
        </div>
      </div>
      <ErrorBanner error={bannerError(error)} />
      <form className="row row--wrap" style={{ alignItems: 'flex-end' }} onSubmit={add}>
        <Field label="Holiday date" error={fieldErrors(error).holiday_date}><input type="date" value={day} onChange={(e) => setDay(e.target.value)} /></Field>
        <Field label="Name" error={fieldErrors(error).name}><input value={name} onChange={(e) => setName(e.target.value)} placeholder="Diwali" /></Field>
        <button type="submit" className="btn" disabled={!day || !name}>Add</button>
      </form>
      <table className="table table--compact">
        <tbody>
          {(data?.holidays ?? []).length === 0 ? <tr><td className="muted">No upcoming holidays.</td></tr> : (data?.holidays ?? []).map((h) => (
            <tr key={h.holiday_date}>
              <td>{fmtDate(h.holiday_date)}</td>
              <td>{h.name}</td>
              <td className="num"><button type="button" className="btn btn--ghost btn--small" onClick={() => api.delete(`/holidays/${h.holiday_date}`).then(reload).catch(setError)}>Remove</button></td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}

function SecuritySettings({ settings, onSaved }: { settings: SettingsData; onSaved: (s: SettingsData) => void }) {
  const [error, setError] = useState<unknown>(null)
  const required = settings.mfa_required_for_privileged === '1'
  const selfApproval = settings.owner_self_approval === '1'

  async function save(changes: Record<string, boolean>) {
    setError(null)
    try {
      onSaved(await api.patch<SettingsData>('/settings', changes))
    } catch (err) {
      setError(err)
    }
  }
  const toggle = () => save({ mfa_required_for_privileged: !required })

  return (
    <section className="card stack">
      <h2 className="card__title">Security</h2>
      <div className="row row--between">
        <div>
          <strong>Require two-factor sign-in</strong>
          <div className="small muted">Super Admins, Admins and Finance must use an authenticator app. Others can opt in from their profile menu.</div>
        </div>
        <label className="toggle"><input type="checkbox" checked={required} onChange={toggle} aria-label="Require two-factor sign-in" /></label>
      </div>
      {fieldErrors(error).mfa_required_for_privileged ? (
        <div className="banner banner--warning">{fieldErrors(error).mfa_required_for_privileged} Use "Two-factor sign-in" in your profile menu (bottom left).</div>
      ) : <ErrorBanner error={error} />}
      <div className="row row--between">
        <div>
          <strong>Single-owner approval</strong>
          <div className="small muted">Normally the person who submits a loan, payout, reversal or journal cannot approve it. Turn this on only while you are the only approver: Super Admins may then approve their own requests. Every decision is still audited.</div>
        </div>
        <label className="toggle"><input type="checkbox" checked={selfApproval} onChange={() => save({ owner_self_approval: !selfApproval })} aria-label="Single-owner approval" /></label>
      </div>
      <p className="small muted">Sessions use HttpOnly cookies with CSRF protection, expire after 8 hours, and every sign-in is audited.</p>
    </section>
  )
}

interface OutboxRow { id: string; channel: string; event: string; recipient: string; body: string; status: string; created_at: string; sent_at: string | null; error: string | null; customer_name: string | null }

function Outbox() {
  const { rows, meta, update } = usePagedList<OutboxRow>('/notifications/outbox?per_page=10')
  return (
    <details className="card card--flush" style={{ marginTop: 16 }}>
      <summary className="card__header" style={{ cursor: 'pointer' }}>
        <h2 className="card__title">SMS log</h2>
        <span className="small muted">Messages stay queued until an SMS provider is connected.</span>
      </summary>
      <div className="table-wrap">
        <table className="table table--compact">
          <thead><tr><th>Created</th><th>To</th><th>Event</th><th>Message</th><th>Status</th></tr></thead>
          <tbody>
            {(rows ?? []).length === 0 ? <tr><td colSpan={5} className="table__empty">No messages.</td></tr> : (rows ?? []).map((m) => (
              <tr key={m.id}>
                <td>{dateTime(m.created_at)}</td>
                <td>{m.customer_name}<div className="small muted mono">{m.recipient}</div></td>
                <td>{titleCase(m.event.replace('.', ' '))}</td>
                <td style={{ whiteSpace: 'normal', maxWidth: 380 }}>{m.body}</td>
                <td><StatusBadge status={m.status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div style={{ padding: '0 16px 12px' }}>{meta ? <Pager page={meta.page} totalPages={meta.total_pages} onPage={(p) => update({ page: String(p) })} /> : null}</div>
    </details>
  )
}

const LIMIT_FIELDS: { key: keyof SettingsData; label: string; hint: string }[] = [
  { key: 'loan_approval_limit', label: 'Branch manager approval limit (₹)', hint: 'Larger loans need an Admin / Super Admin.' },
  { key: 'max_customer_exposure', label: 'Max exposure per customer (₹)', hint: 'Outstanding + in-process principal.' },
  { key: 'agent_cash_limit', label: 'Collector cash-in-hand limit (₹)', hint: 'Cash collection blocks until handed over.' },
  { key: 'provision_rate_standard', label: 'Provision – standard (%)', hint: 'Includes SMA-0/1/2.' },
  { key: 'provision_rate_substandard', label: 'Provision – sub-standard (%)', hint: 'NPA up to 12 months.' },
  { key: 'provision_rate_doubtful', label: 'Provision – doubtful (%)', hint: 'NPA 12–36 months.' },
  { key: 'provision_rate_loss', label: 'Provision – loss (%)', hint: 'NPA over 36 months.' },
]

function LimitsSettings({ settings, onSaved }: { settings: SettingsData; onSaved: (s: SettingsData) => void }) {
  const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(LIMIT_FIELDS.map((f) => [f.key, String(Number(settings[f.key]))])))
  const [overdraft, setOverdraft] = useState(settings.allow_bank_overdraft === '1')
  const [requireLocation, setRequireLocation] = useState(settings.require_collection_location === '1')
  const [error, setError] = useState<unknown>(null)

  async function save(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      onSaved(await api.patch<SettingsData>('/settings', { ...values, allow_bank_overdraft: overdraft, require_collection_location: requireLocation }))
    } catch (err) {
      setError(err)
    }
  }

  return (
    <form className="card stack" style={{ marginTop: 16 }} onSubmit={save}>
      <h2 className="card__title">Credit, cash &amp; provisioning policy</h2>
      <p className="small muted">Defaults follow common IRACP practice; confirm them with your accountant and regulator. Every change is audited.</p>
      <ErrorBanner error={bannerError(error)} />
      <div className="grid grid--4 grid--tight">
        {LIMIT_FIELDS.map((f) => (
          <Field key={f.key} label={f.label} hint={f.hint} error={fieldErrors(error)[f.key]}>
            <input inputMode="decimal" value={values[f.key]} onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))} />
          </Field>
        ))}
      </div>
      <label className="checkbox">
        <input type="checkbox" checked={overdraft} onChange={(e) => setOverdraft(e.target.checked)} />
        Allow the bank account to go overdrawn (only if you have an overdraft facility). Branch cash can never go negative.
      </label>
      <label className="checkbox">
        <input type="checkbox" checked={requireLocation} onChange={(e) => setRequireLocation(e.target.checked)} />
        Agents must have location (GPS) on to record a collection. Off: location is still saved when available, and collections without it are counted in the Demand vs collection report.
      </label>
      <div className="row" style={{ justifyContent: 'flex-end' }}><button type="submit" className="btn btn--primary">Save policy</button></div>
    </form>
  )
}

function UpiSettings({ settings, onSaved }: { settings: SettingsData; onSaved: (s: SettingsData) => void }) {
  const [vpa, setVpa] = useState(settings.upi_vpa)
  const [payee, setPayee] = useState(settings.upi_payee_name)
  const [error, setError] = useState<unknown>(null)

  async function save(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      onSaved(await api.patch<SettingsData>('/settings', { upi_vpa: vpa.trim(), upi_payee_name: payee.trim() }))
    } catch (err) {
      setError(err)
    }
  }

  return (
    <form className="card stack" style={{ marginTop: 16 }} onSubmit={save}>
      <div className="card__header" style={{ marginBottom: 0 }}>
        <h2 className="card__title">UPI QR collection</h2>
        <span className={`badge ${settings.upi_vpa ? 'badge--success' : 'badge--warning'}`}>{settings.upi_vpa ? 'Active' : 'Not set up'}</span>
      </div>
      <p className="small muted">Agents show a QR with the exact amount to this UPI ID. Customers pay with any UPI app; the agent submits the UTR or a screenshot, and finance confirms it against your bank statement.</p>
      <ErrorBanner error={bannerError(error)} />
      <div className="grid grid--2 grid--tight">
        <Field label="Organisation UPI ID (VPA)" error={fieldErrors(error).upi_vpa} hint="Your business/merchant VPA, e.g. finserve@okhdfcbank. Leave empty to turn UPI QR off.">
          <input value={vpa} onChange={(e) => setVpa(e.target.value)} autoCapitalize="none" placeholder="name@bank" />
        </Field>
        <Field label="Payee name shown to customers" error={fieldErrors(error).upi_payee_name}>
          <input value={payee} onChange={(e) => setPayee(e.target.value)} placeholder="KBSAVE" maxLength={50} />
        </Field>
      </div>
      <div className="row" style={{ justifyContent: 'flex-end' }}><button type="submit" className="btn btn--primary">Save UPI details</button></div>
    </form>
  )
}

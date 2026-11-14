import { useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { CustomerPicker } from '../components/CustomerPicker'
import { Details, ErrorBanner, Field, Notice, PageHeader, Segmented, StatusBadge, Tabs, bannerError, fieldErrors } from '../components/ui'
import { date, money, titleCase } from '../format'
import { useFetch } from '../hooks'
import { LOAN_UNIT, depositTenures, tenureLabel } from '../plans'
import type { DepositTerms, LoanTerms } from '../types'

type Kind = 'loans' | 'savings'
type Row = Record<string, string | number>
interface Result {
  row: number
  ok: boolean
  customer: string | null
  customer_is_new?: boolean
  errors?: Record<string, string>
  [key: string]: unknown
}
interface ImportResponse { kind: Kind; committed: boolean; ok: number; failed: number; rows: Result[] }
interface Plan<T> { id: string; code: string; name: string; status: string; terms: T }

const ASSET_CLASS: Record<string, string> = {
  standard: 'Standard (on time)', sma_0: 'SMA-0 (1–30 days late)', sma_1: 'SMA-1 (31–60 days late)', sma_2: 'SMA-2 (61–90 days late)',
  substandard: 'NPA (over 90 days late)', doubtful: 'NPA – doubtful', loss: 'NPA – loss',
}

const CUSTOMER_COLUMNS = ['name', 'mobile', 'address', 'city', 'state', 'pincode', 'branch', 'agent_email']
const COLUMNS: Record<Kind, string[]> = {
  loans: [...CUSTOMER_COLUMNS, 'plan_code', 'loan_amount', 'installments', 'start_date', 'amount_received', 'note'],
  savings: [...CUSTOMER_COLUMNS, 'plan_code', 'amount', 'tenure', 'opened_on', 'balance', 'interest'],
}
const EXAMPLE: Record<Kind, string[]> = {
  loans: ['Sunita Devi', '9876543210', '5 Gandhi Nagar', 'Patna', 'Bihar', '800001', '', '', 'DCL-DAILY', '10000', '50', '15-09-2026', '4500', 'Register page 12'],
  savings: ['Raju Yadav', '9876500000', '9 Station Road', 'Patna', 'Bihar', '800001', '', '', 'RD-DAILY', '100', '1 year', '01-08-2026', '8800', '0'],
}

/** Bring in loans and savings from the old manual register: one at a time, or a whole sheet. */
export function Migration() {
  const { user } = useAuth()
  const [tab, setTab] = useState<'loan' | 'savings' | 'sheet'>('loan')
  const depositsOn = user?.settings.deposits_enabled ?? false
  const tabs: { id: typeof tab; label: string }[] = [{ id: 'loan', label: 'One loan' }]
  if (depositsOn) tabs.push({ id: 'savings', label: 'One savings account' })
  tabs.push({ id: 'sheet', label: 'Import from Excel' })
  return (
    <>
      <PageHeader
        title="Bring in old accounts"
        subtitle="Loans and savings from your old register: they start on their real date, with what was already collected."
      />
      <Notice tone="info">
        <div>
          The repayment schedule runs from the old start date. What was already received pays the installments in order; anything unpaid shows as overdue,
          without back-dated late fees. The books get one opening-balance entry — no cash is recorded today. Add your actual cash and bank money separately
          in <b>Accounting → Funds → Add capital</b>.
        </div>
      </Notice>
      <Tabs tabs={tabs} value={tab} onChange={setTab} />
      {tab === 'loan' ? <OneLoan /> : tab === 'savings' ? <OneSavings /> : <Sheet depositsOn={depositsOn} />}
    </>
  )
}

// ---- One account -----------------------------------------------------------------------------

function CustomerChoice({ value, onChange, errors }: { value: Row; onChange: (row: Row) => void; errors: Record<string, string> }) {
  const [mode, setMode] = useState<'existing' | 'new'>('existing')
  const set = (k: string) => (e: { target: { value: string } }) => onChange({ ...value, [k]: e.target.value })
  return (
    <>
      <Segmented<'existing' | 'new'> options={[{ id: 'existing', label: 'Existing customer' }, { id: 'new', label: 'New customer' }]} value={mode} onChange={(m) => { setMode(m); onChange({}) }} />
      {mode === 'existing' ? (
        <Field label="Customer *" error={errors.customer ?? errors.mobile}>
          <CustomerPicker value={String(value.customer_id ?? '')} onChange={(id) => onChange({ customer_id: id })} />
        </Field>
      ) : (
        <div className="grid grid--3 grid--tight">
          <Field label="Name *" error={errors.name}><input value={String(value.name ?? '')} onChange={set('name')} /></Field>
          <Field label="Mobile *" error={errors.mobile}><input inputMode="tel" value={String(value.mobile ?? '')} onChange={set('mobile')} /></Field>
          <Field label="Address *" error={errors.address}><input value={String(value.address ?? '')} onChange={set('address')} /></Field>
          <Field label="City *" error={errors.city}><input value={String(value.city ?? '')} onChange={set('city')} /></Field>
          <Field label="State *" error={errors.state}><input value={String(value.state ?? '')} onChange={set('state')} /></Field>
          <Field label="PIN code *" error={errors.pincode}><input inputMode="numeric" maxLength={6} value={String(value.pincode ?? '')} onChange={set('pincode')} /></Field>
          <Field label="Agent's login email" error={errors.agent_email} hint="Optional: the agent who collects from them."><input type="email" value={String(value.agent_email ?? '')} onChange={set('agent_email')} /></Field>
        </div>
      )}
    </>
  )
}

/** Shared check / bring-in buttons for a single row. */
function useSingle(kind: Kind) {
  const [result, setResult] = useState<ImportResponse | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [busy, setBusy] = useState(false)
  async function run(row: Row, commit: boolean) {
    setBusy(true)
    setError(null)
    try {
      setResult(await api.post<ImportResponse>('/migration/import', { kind, commit, rows: [row] }))
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }
  const outcome = result?.rows[0]
  return { run, result, outcome, error, busy, errors: { ...fieldErrors(error), ...(outcome?.errors ?? {}) }, reset: () => setResult(null) }
}

function OneLoan() {
  const { data: plans } = useFetch<Plan<LoanTerms>[]>('/loan-products')
  const [customer, setCustomer] = useState<Row>({})
  const [form, setForm] = useState({ product_id: '', loan_amount: '', installments: '', start_date: '', amount_received: '', note: '' })
  const single = useSingle('loans')
  const plan = plans?.find((p) => p.id === form.product_id)
  const row = { ...customer, ...form }
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => { single.reset(); setForm((f) => ({ ...f, [k]: e.target.value })) }
  const submit = (commit: boolean) => (e?: FormEvent) => { e?.preventDefault(); void single.run(row, commit) }
  const done = single.result?.committed && single.outcome?.ok

  return (
    <div className="grid grid--2-1" style={{ marginTop: 16 }}>
      <form className="card stack" onSubmit={submit(false)} noValidate>
        <ErrorBanner error={bannerError(single.error)} />
        <CustomerChoice value={customer} onChange={(c) => { single.reset(); setCustomer(c) }} errors={single.errors} />
        <Field label="Plan *" error={single.errors.plan_code} hint="The plan whose rate and repayment type the old loan follows (inactive plans are listed too).">
          <select value={form.product_id} onChange={set('product_id')}>
            <option value="">Choose…</option>
            {(plans ?? []).map((p) => <option key={p.id} value={p.id}>{p.name} ({p.code}) · {titleCase(p.terms.repayment_frequency)}{p.status !== 'active' ? ' · inactive' : ''}</option>)}
          </select>
        </Field>
        <div className="grid grid--2 grid--tight">
          <Field label="Loan amount given (₹) *" error={single.errors.loan_amount}><input inputMode="decimal" value={form.loan_amount} onChange={set('loan_amount')} /></Field>
          <Field label={`Number of installments${plan ? ` (${LOAN_UNIT[plan.terms.repayment_frequency]})` : ''} *`} error={single.errors.installments}><input inputMode="numeric" value={form.installments} onChange={set('installments')} /></Field>
          <Field label="Start date (loan given on) *" error={single.errors.start_date}><input type="date" value={form.start_date} onChange={set('start_date')} /></Field>
          <Field label="Total received so far (₹) *" error={single.errors.amount_received} hint="All installments paid up to today, added together."><input inputMode="decimal" value={form.amount_received} onChange={set('amount_received')} /></Field>
        </div>
        <Field label="Note" error={single.errors.note}><input value={form.note} onChange={set('note')} placeholder="e.g. Register page 12" maxLength={255} /></Field>
        <div className="form__actions">
          <button type="submit" className="btn" disabled={single.busy}>Check</button>
          <button type="button" className="btn btn--primary" disabled={single.busy || !single.outcome?.ok || single.result?.committed} onClick={() => submit(true)()}>Bring in this loan</button>
        </div>
      </form>
      <section className="card">
        <h2 className="card__title">{done ? 'Brought in' : 'How it will look today'}</h2>
        {single.outcome?.ok ? (
          <>
            <Details rows={[
              ['Customer', `${single.outcome.customer}${single.outcome.customer_is_new ? ' (new)' : ''}`],
              ['Status', <StatusBadge key="s" status={String(single.outcome.status)} />],
              ['Installment', money(String(single.outcome.installment_amount))],
              ['Installments paid', String(single.outcome.installments_paid)],
              ['Principal still owed', money(String(single.outcome.principal_outstanding))],
              ['Overdue now', money(String(single.outcome.overdue_amount))],
              ['Next due', single.outcome.next_due_date ? date(String(single.outcome.next_due_date)) : '—'],
              ['Classification', ASSET_CLASS[String(single.outcome.asset_class)] ?? titleCase(String(single.outcome.asset_class))],
            ]} />
            {done ? <Link to={`/loans/${single.outcome.loan_id}`} className="btn btn--block" style={{ marginTop: 12 }}>Open {String(single.outcome.loan_number)}</Link> : <p className="small muted">Nothing is saved until you press <b>Bring in this loan</b>.</p>}
          </>
        ) : <p className="muted">Fill in the old loan and press <b>Check</b> to see its schedule position before saving.</p>}
      </section>
    </div>
  )
}

function OneSavings() {
  const { data: plans } = useFetch<Plan<DepositTerms>[]>('/deposit-products')
  const [customer, setCustomer] = useState<Row>({})
  const [form, setForm] = useState({ product_id: '', amount: '', tenure: '', opened_on: '', balance: '', interest: '' })
  const single = useSingle('savings')
  const plan = plans?.find((p) => p.id === form.product_id)
  const fixed = plan?.terms.scheme === 'fixed'
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => { single.reset(); setForm((f) => ({ ...f, [k]: e.target.value })) }
  const row = { ...customer, ...form }
  const submit = (commit: boolean) => (e?: FormEvent) => { e?.preventDefault(); void single.run(row, commit) }
  const done = single.result?.committed && single.outcome?.ok

  return (
    <div className="grid grid--2-1" style={{ marginTop: 16 }}>
      <form className="card stack" onSubmit={submit(false)} noValidate>
        <ErrorBanner error={bannerError(single.error)} />
        <CustomerChoice value={customer} onChange={(c) => { single.reset(); setCustomer(c) }} errors={single.errors} />
        <Field label="Plan *" error={single.errors.plan_code}>
          <select value={form.product_id} onChange={(e) => { set('product_id')(e); setForm((f) => ({ ...f, product_id: e.target.value, tenure: '' })) }}>
            <option value="">Choose…</option>
            {(plans ?? []).map((p) => <option key={p.id} value={p.id}>{p.name} ({p.code})</option>)}
          </select>
        </Field>
        <div className="grid grid--2 grid--tight">
          <Field label={fixed ? 'Deposit amount (₹) *' : 'Installment (₹) *'} error={single.errors.amount}><input inputMode="decimal" value={form.amount} onChange={set('amount')} /></Field>
          <Field label="Tenure *" error={single.errors.tenure}>
            <select value={form.tenure} onChange={set('tenure')} disabled={!plan}>
              <option value="">{plan ? 'Choose…' : 'Choose the plan first'}</option>
              {plan ? depositTenures(plan.terms).map((t) => <option key={tenureLabel(t)} value={tenureLabel(t)}>{tenureLabel(t)}</option>) : null}
            </select>
          </Field>
          <Field label="Opened on *" error={single.errors.opened_on}><input type="date" value={form.opened_on} onChange={set('opened_on')} /></Field>
          <Field label="Balance saved so far (₹)" error={single.errors.balance} hint={fixed ? 'Leave empty if the full deposit was paid.' : 'All installments collected up to today.'}><input inputMode="decimal" value={form.balance} onChange={set('balance')} /></Field>
          <Field label="Interest added so far (₹)" error={single.errors.interest} hint="Leave empty if none."><input inputMode="decimal" value={form.interest} onChange={set('interest')} /></Field>
        </div>
        <div className="form__actions">
          <button type="submit" className="btn" disabled={single.busy}>Check</button>
          <button type="button" className="btn btn--primary" disabled={single.busy || !single.outcome?.ok || single.result?.committed} onClick={() => submit(true)()}>Bring in this account</button>
        </div>
      </form>
      <section className="card">
        <h2 className="card__title">{done ? 'Brought in' : 'How it will look today'}</h2>
        {single.outcome?.ok ? (
          <>
            <Details rows={[
              ['Customer', `${single.outcome.customer}${single.outcome.customer_is_new ? ' (new)' : ''}`],
              ['Status', <StatusBadge key="s" status={String(single.outcome.status)} />],
              ['Balance', money(String(single.outcome.balance))],
              ['Interest so far', money(String(single.outcome.interest))],
              ['Matures on', date(String(single.outcome.maturity_date))],
              ['Installments due now', String(single.outcome.installments_due_now)],
            ]} />
            {done ? <Link to={`/deposits/${single.outcome.deposit_account_id}`} className="btn btn--block" style={{ marginTop: 12 }}>Open {String(single.outcome.deposit_number)}</Link> : <p className="small muted">Nothing is saved until you press <b>Bring in this account</b>.</p>}
          </>
        ) : <p className="muted">Fill in the old account and press <b>Check</b>.</p>}
      </section>
    </div>
  )
}

// ---- Whole sheet -----------------------------------------------------------------------------

/** Minimal CSV reader: quotes, commas and line breaks inside quotes, CRLF, Excel's BOM. */
function parseCsv(text: string): string[][] {
  const rows: string[][] = []
  let row: string[] = []
  let cell = ''
  let quoted = false
  const src = text.replace(/^﻿/, '')
  for (let i = 0; i < src.length; i++) {
    const c = src[i]
    if (quoted) {
      if (c === '"' && src[i + 1] === '"') { cell += '"'; i++ } else if (c === '"') quoted = false
      else cell += c
    } else if (c === '"') quoted = true
    else if (c === ',') { row.push(cell); cell = '' }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && src[i + 1] === '\n') i++
      row.push(cell); rows.push(row); row = []; cell = ''
    } else cell += c
  }
  if (cell !== '' || row.length > 0) { row.push(cell); rows.push(row) }
  return rows.filter((r) => r.some((v) => v.trim() !== ''))
}

const csvLine = (values: string[]) => values.map((v) => (/[",\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v)).join(',')

function Sheet({ depositsOn }: { depositsOn: boolean }) {
  const [kind, setKind] = useState<Kind>('loans')
  const [rows, setRows] = useState<Row[] | null>(null)
  const [fileName, setFileName] = useState('')
  const [result, setResult] = useState<ImportResponse | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [busy, setBusy] = useState(false)
  const { data: loanPlans } = useFetch<Plan<LoanTerms>[]>('/loan-products')
  const { data: savingPlans } = useFetch<Plan<DepositTerms>[]>(depositsOn ? '/deposit-products' : null)

  function template() {
    const csv = [csvLine(COLUMNS[kind]), csvLine(EXAMPLE[kind])].join('\r\n')
    const url = URL.createObjectURL(new Blob([`﻿${csv}\r\n`], { type: 'text/csv' }))
    const a = document.createElement('a')
    a.href = url
    a.download = `kbsave-old-${kind}-template.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  async function read(file: File | undefined) {
    setResult(null)
    setError(null)
    if (!file) return
    setFileName(file.name)
    const lines = parseCsv(await file.text())
    const header = (lines[0] ?? []).map((h) => h.trim().toLowerCase().replace(/[\s-]+/g, '_'))
    const missing = COLUMNS[kind].filter((c) => !['branch', 'agent_email', 'note', 'interest', 'balance'].includes(c) && !header.includes(c))
    if (missing.length > 0) {
      setRows(null)
      setError(new Error(`This sheet is missing columns: ${missing.join(', ')}. Download the template and keep its first row as it is.`))
      return
    }
    setRows(lines.slice(1).map((cells) => Object.fromEntries(header.map((h, i) => [h, (cells[i] ?? '').trim()]))))
  }

  async function send(commit: boolean) {
    if (!rows) return
    setBusy(true)
    setError(null)
    try {
      setResult(await api.post<ImportResponse>('/migration/import', { kind, commit, rows }))
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }

  const planCodes = useMemo(
    () => (kind === 'loans' ? (loanPlans ?? []).map((p) => `${p.code} (${titleCase(p.terms.repayment_frequency)})`) : (savingPlans ?? []).map((p) => `${p.code} (${depositTenures(p.terms).map(tenureLabel).join(', ')})`)),
    [kind, loanPlans, savingPlans],
  )

  return (
    <div className="stack" style={{ marginTop: 16 }}>
      <section className="card stack">
        {depositsOn ? <Segmented<Kind> options={[{ id: 'loans', label: 'Loans' }, { id: 'savings', label: 'Savings' }]} value={kind} onChange={(k) => { setKind(k); setRows(null); setResult(null) }} /> : null}
        <ol className="sheet-steps">
          <li><button type="button" className="btn btn--small" onClick={template}>Download the template</button> and open it in Excel or Google Sheets.</li>
          <li>One row per {kind === 'loans' ? 'loan' : 'savings account'}. Keep the first row (the column names) as it is. Dates like <b>15-09-2026</b>.{kind === 'savings' ? <> Tenure like <b>12 months</b> or <b>45 days</b>.</> : null}</li>
          <li>Save as <b>CSV</b> (File → Download → CSV, or Save as → CSV UTF-8), then choose it here: <input type="file" accept=".csv,text/csv" onChange={(e) => void read(e.target.files?.[0])} /></li>
        </ol>
        <p className="small muted">Plan codes: {planCodes.join(' · ') || '—'}. A customer whose mobile number already exists is reused; otherwise they are created from the row.</p>
        <ErrorBanner error={error} />
        {rows ? (
          <div className="row row--wrap" style={{ gap: 8 }}>
            <span className="small">{fileName}: <b>{rows.length}</b> rows</span>
            <button type="button" className="btn" disabled={busy} onClick={() => void send(false)}>{busy && !result ? 'Checking…' : 'Check all rows'}</button>
            {result && !result.committed && result.ok > 0 ? (
              <button type="button" className="btn btn--primary" disabled={busy} onClick={() => void send(true)}>Import {result.ok} good row{result.ok === 1 ? '' : 's'}</button>
            ) : null}
          </div>
        ) : null}
      </section>
      {result ? <SheetResult result={result} /> : null}
    </div>
  )
}

function SheetResult({ result }: { result: ImportResponse }) {
  const loans = result.kind === 'loans'
  const cell = (r: Result): ReactNode => {
    if (!r.ok) return <span className="sheet-error">{Object.entries(r.errors ?? {}).map(([k, v]) => `${titleCase(k)}: ${v}`).join(' · ')}</span>
    if (loans) return `${r.installments_paid} paid · owes ${money(String(r.principal_outstanding))}${Number(r.overdue_amount) > 0 ? ` · overdue ${money(String(r.overdue_amount))}` : ''}`
    return `Balance ${money(String(r.balance))} · matures ${date(String(r.maturity_date))}`
  }
  return (
    <section className="card">
      {result.committed
        ? <Notice>{result.ok} imported{result.failed ? `, ${result.failed} not imported — fix those rows in the sheet and import them again` : ''}.</Notice>
        : <Notice tone={result.failed ? 'warning' : 'success'}>{result.ok} rows are ready{result.failed ? `, ${result.failed} have mistakes (shown in red; they will be skipped)` : ''}. Nothing is saved yet.</Notice>}
      <div className="table-wrap table-wrap--plain">
        <table className="table table--compact">
          <thead><tr><th>Sheet row</th><th>Customer</th><th>Result</th><th>{loans ? 'Loan' : 'Account'}</th></tr></thead>
          <tbody>
            {result.rows.map((r) => (
              <tr key={r.row}>
                <td>{r.row + 1}</td>
                <td>{r.customer ?? '—'}{r.customer_is_new ? <span className="small muted"> (new)</span> : null}</td>
                <td>{cell(r)}</td>
                <td>{r.ok && result.committed
                  ? (loans ? <Link to={`/loans/${r.loan_id}`}>{String(r.loan_number)}</Link> : <Link to={`/deposits/${r.deposit_account_id}`}>{String(r.deposit_number)}</Link>)
                  : r.ok ? <StatusBadge status={String(r.status)} /> : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

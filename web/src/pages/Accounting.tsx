import { useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { ApiError, api } from '../api'
import { useAuth } from '../auth'
import { Icons } from '../components/icons'
import { ErrorBanner, Field, Loading, Modal, Notice, PageHeader, Pager, Tabs, bannerError, fieldErrors } from '../components/ui'
import { date, dateTime, money, titleCase } from '../format'
import { useFetch, useLocalPagedList } from '../hooks'

type Tab = 'funds' | 'controls' | 'accounts' | 'pnl' | 'balance' | 'journal' | 'settlement' | 'provisions'

interface Account { code: string; name: string; type: string; normal_balance: 'debit' | 'credit'; balance: string; total_debit: string; total_credit: string }

const CONTROL = ['1010', '1030', '1100', '1110', '1190', '2000', '2010', '2300']

export function Accounting() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const tab = (params.get('tab') as Tab) ?? 'funds'
  const tabs: { id: Tab; label: string }[] = [
    { id: 'funds', label: 'Funds' },
    { id: 'controls', label: 'Controls & day-end' },
    { id: 'accounts', label: 'Chart of accounts' },
    { id: 'pnl', label: 'Profit & loss' },
    { id: 'balance', label: 'Balance sheet' },
    { id: 'provisions', label: 'Provisioning' },
    { id: 'settlement', label: 'UPI settlement' },
    ...(can('accounting.journal') ? [{ id: 'journal' as Tab, label: 'Manual journal' }] : []),
  ]
  return (
    <>
      <PageHeader title="Accounting" subtitle="Double-entry general ledger. Control accounts are maintained by the system and reconciled to their subledgers." />
      <Tabs tabs={tabs} value={tab} onChange={(t) => setParams({ tab: t })} />
      {tab === 'funds' ? <FundsTab /> : null}
      {tab === 'controls' ? <ControlsTab /> : null}
      {tab === 'accounts' ? <AccountsTab /> : null}
      {tab === 'pnl' ? <PnlTab /> : null}
      {tab === 'balance' ? <BalanceTab /> : null}
      {tab === 'provisions' ? <ProvisionsTab /> : null}
      {tab === 'settlement' ? <SettlementTab /> : null}
      {tab === 'journal' ? <JournalTab /> : null}
    </>
  )
}

interface FundsPosition { bank: string; capital: string; branches: { id: string; code: string; name: string; cash: string }[] }
type FundsMove = 'capital' | 'bank_to_cash' | 'cash_to_bank'

const MOVES: { id: FundsMove; label: string; hint: string }[] = [
  { id: 'capital', label: 'Add capital', hint: 'Owner puts money into the business (bank or branch cash).' },
  { id: 'bank_to_cash', label: 'Bank → branch cash', hint: 'Cash withdrawn from the bank for a branch, e.g. to pay loans in cash.' },
  { id: 'cash_to_bank', label: 'Branch cash → bank', hint: 'Branch cash deposited into the bank.' },
]

/** Where the money is, and how it gets there: capital in, and moves between the bank and branch cash. */
function FundsTab() {
  const { can } = useAuth()
  const { data, error, reload } = useFetch<FundsPosition>('/accounting/funds')
  const [move, setMove] = useState<FundsMove | null>(null)
  const [notice, setNotice] = useState('')
  if (!data) return error ? <ErrorBanner error={error} /> : <Loading />
  const empty = Number(data.bank) <= 0 && data.branches.every((b) => Number(b.cash) <= 0)
  return (
    <div className="stack">
      {notice ? <Notice>{notice}</Notice> : null}
      {empty ? <Notice tone="warning">The business has no money recorded yet, so loans cannot be disbursed. Start with "Add capital".</Notice> : null}
      <div className="summary-strip">
        <div><span className="small muted">Bank account</span><strong>{money(data.bank)}</strong></div>
        {data.branches.map((b) => <div key={b.id}><span className="small muted">{`${b.name} cash`}</span><strong>{money(b.cash)}</strong></div>)}
        <div><span className="small muted">Owner's capital</span><strong>{money(data.capital)}</strong></div>
      </div>
      {can('accounting.funds') ? (
        <div className="row row--wrap">
          {MOVES.map((m) => <button key={m.id} type="button" className={m.id === 'capital' ? 'btn btn--primary' : 'btn'} onClick={() => setMove(m.id)}>{m.label}</button>)}
        </div>
      ) : null}
      <p className="small muted">Loans paid out by UPI come from the bank account; loans paid in cash come from that branch's cash. Every entry is posted to the ledger and audited.</p>
      {move ? (
        <FundsModal
          move={move}
          position={data}
          onClose={() => setMove(null)}
          onDone={(message) => {
            setMove(null)
            setNotice(message)
            reload()
          }}
        />
      ) : null}
    </div>
  )
}

function FundsModal({ move, position, onClose, onDone }: { move: FundsMove; position: FundsPosition; onClose: () => void; onDone: (message: string) => void }) {
  const info = MOVES.find((m) => m.id === move)!
  const [amount, setAmount] = useState('')
  const [into, setInto] = useState<'bank' | 'cash'>('bank')
  const [branchId, setBranchId] = useState(position.branches[0]?.id ?? '')
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const errors = fieldErrors(error)
  const needsBranch = move !== 'capital' || into === 'cash'

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      await api.post('/accounting/funds', { type: move, amount, into: move === 'capital' ? into : undefined, branch_id: needsBranch ? Number(branchId) : undefined, reference: reference || null, note: note || null })
      onDone(`${info.label}: ${money(amount)} recorded.`)
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={info.label} onClose={onClose}>
      <form onSubmit={submit} className="stack">
        <p className="small muted">{info.hint}</p>
        <ErrorBanner error={bannerError(error)} />
        <Field label="Amount (₹) *" error={errors.amount}><input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus /></Field>
        {move === 'capital' ? (
          <Field label="Put it into">
            <select value={into} onChange={(e) => setInto(e.target.value as 'bank' | 'cash')}>
              <option value="bank">Bank account</option>
              <option value="cash">Branch cash</option>
            </select>
          </Field>
        ) : null}
        {needsBranch ? (
          <Field label="Branch *" error={errors.branch_id}>
            <select value={branchId} onChange={(e) => setBranchId(e.target.value)}>
              {position.branches.map((b) => <option key={b.id} value={b.id}>{`${b.name} (cash ${money(b.cash)})`}</option>)}
            </select>
          </Field>
        ) : null}
        <Field label="Reference" error={errors.reference} hint="Bank UTR, cheque or slip number (optional)."><input value={reference} onChange={(e) => setReference(e.target.value)} /></Field>
        <Field label="Note" error={errors.note}><input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Optional" /></Field>
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={saving || !amount}>{saving ? 'Saving…' : `Record ${amount ? money(amount) : ''}`}</button>
        </div>
      </form>
    </Modal>
  )
}

interface Check { check: string; account: string | null; ledger: string; subledger: string; difference: string; ok: boolean }
interface CloseCheck { check: string; ok: boolean; blocking: boolean; detail: string }

function ControlsTab() {
  const { can } = useAuth()
  const { data: controls, error, reload } = useFetch<{ checks: Check[]; as_of: string }>('/accounting/controls')
  const { data: close, reload: reloadClose } = useFetch<{ closed_through: string | null; business_date: string; checks: CloseCheck[]; history: { business_date: string; closed_at: string; closed_by_name: string }[] }>('/accounting/day-close')
  const [closeError, setCloseError] = useState<unknown>(null)
  const [notice, setNotice] = useState('')
  const allOk = controls?.checks.every((c) => c.ok)

  async function closeDay() {
    if (!close) return
    setCloseError(null)
    try {
      await api.post('/accounting/day-close', { business_date: close.business_date })
      setNotice(`Books closed through ${close.business_date}. No further postings can be dated on or before it.`)
      reloadClose()
      reload()
    } catch (err) {
      setCloseError(err)
      reloadClose()
    }
  }

  return (
    <div className="stack">
      {notice ? <Notice>{notice}</Notice> : null}
      <ErrorBanner error={error} />
      <section className="card card--flush">
        <div className="card__header">
          <h2 className="card__title">GL ↔ subledger reconciliation</h2>
          {controls ? <span className={`badge ${allOk ? 'badge--success' : 'badge--danger'}`}>{allOk ? 'All controls pass' : 'Differences found'}</span> : null}
        </div>
        {!controls ? <Loading /> : (
          <div className="table-wrap">
            <table className="table">
              <thead><tr><th>Control</th><th>Account</th><th className="num">General ledger</th><th className="num">Subledger</th><th className="num">Difference</th><th>Result</th></tr></thead>
              <tbody>
                {controls.checks.map((c) => (
                  <tr key={c.check} className={c.ok ? undefined : 'is-highlight'}>
                    <td>{c.check}</td>
                    <td className="mono">{c.account ?? '—'}</td>
                    <td className="num">{c.account ? money(c.ledger) : c.ledger}</td>
                    <td className="num">{c.account ? money(c.subledger) : c.subledger}</td>
                    <td className="num">{c.account ? money(c.difference) : c.difference}</td>
                    <td>{c.ok ? <span className="badge badge--success"><Icons.check /> Pass</span> : <span className="badge badge--danger">Fail</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="card">
        <div className="card__header">
          <h2 className="card__title">Day-end close</h2>
          <span className="small muted">Books closed through: <strong>{close?.closed_through ? date(close.closed_through) : 'never'}</strong></span>
        </div>
        {close ? (
          <>
            <p className="muted" style={{ marginBottom: 12 }}>Closing {date(close.business_date)} locks the ledger for that date and everything before it.</p>
            <ul className="task-list">
              {close.checks.map((c) => (
                <li key={c.check} className="task" style={{ cursor: 'default' }}>
                  <span className={`badge ${c.ok ? 'badge--success' : c.blocking ? 'badge--danger' : 'badge--warning'}`}>{c.ok ? 'OK' : c.blocking ? 'Blocking' : 'Warning'}</span>
                  <span style={{ flex: 1 }}>{c.check}<div className="small muted">{c.detail}</div></span>
                </li>
              ))}
            </ul>
            <ErrorBanner error={closeError instanceof ApiError && closeError.code === 'DAY_CLOSE_BLOCKED' ? new Error('Resolve the blocking checks first.') : closeError} />
            {can('accounting.close') ? (
              <div className="row" style={{ justifyContent: 'flex-end', marginTop: 12 }}>
                <button type="button" className="btn btn--primary" disabled={close.closed_through !== null && close.closed_through >= close.business_date} onClick={closeDay}>
                  Close {date(close.business_date)}
                </button>
              </div>
            ) : null}
            {close.history.length > 0 ? (
              <>
                <h3 className="card__subtitle">Recent closes</h3>
                <table className="table table--compact"><tbody>
                  {close.history.map((h) => <tr key={h.business_date}><td>{date(h.business_date)}</td><td>{h.closed_by_name}</td><td className="muted">{dateTime(h.closed_at)}</td></tr>)}
                </tbody></table>
              </>
            ) : null}
          </>
        ) : <Loading />}
      </section>
    </div>
  )
}

function AccountsTab() {
  const { data, error } = useFetch<{ as_of: string; accounts: Account[] }>('/accounting/accounts')
  const [ledger, setLedger] = useState<Account | null>(null)
  if (!data) return error ? <ErrorBanner error={error} /> : <Loading />
  const groups = ['asset', 'liability', 'equity', 'income', 'expense']
  return (
    <>
      {groups.map((g) => (
        <section key={g} className="card card--flush" style={{ marginBottom: 16 }}>
          <div className="card__header"><h2 className="card__title">{titleCase(g)}s</h2></div>
          <div className="table-wrap">
            <table className="table">
              <thead><tr><th>Code</th><th>Account</th><th className="num">Debits</th><th className="num">Credits</th><th className="num">Balance</th><th /></tr></thead>
              <tbody>
                {data.accounts.filter((a) => a.type === g).map((a) => (
                  <tr key={a.code}>
                    <td className="mono">{a.code}</td>
                    <td>{a.name}{CONTROL.includes(a.code) ? <span className="badge" style={{ marginLeft: 6 }}>Control</span> : null}</td>
                    <td className="num">{money(a.total_debit)}</td>
                    <td className="num">{money(a.total_credit)}</td>
                    <td className="num"><strong>{money(a.balance)}</strong> <span className="small muted">{a.normal_balance === 'debit' ? 'Dr' : 'Cr'}</span></td>
                    <td><button type="button" className="btn btn--small btn--ghost" onClick={() => setLedger(a)}>Ledger</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ))}
      {ledger ? <LedgerModal account={ledger} onClose={() => setLedger(null)} /> : null}
    </>
  )
}

function LedgerModal({ account, onClose }: { account: Account; onClose: () => void }) {
  const { user } = useAuth()
  const today = user?.settings.business_date ?? ''
  const [from, setFrom] = useState(() => {
    const d = new Date(`${today}T00:00:00`)
    d.setDate(d.getDate() - 30)
    return d.toISOString().slice(0, 10)
  })
  const [to, setTo] = useState(today)
  const { data } = useFetch<{ opening_balance: string; closing_balance: string; lines: { entry_number: string; entry_date: string; source_type: string; description: string; debit: string; credit: string; balance: string; posted_by: string | null }[] }>(
    `/accounting/accounts/${account.code}/ledger?date_from=${from}&date_to=${to}`,
  )
  return (
    <Modal title={`${account.code} · ${account.name}`} onClose={onClose} wide>
      <div className="toolbar">
        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="From" />
        <input type="date" value={to} onChange={(e) => setTo(e.target.value)} aria-label="To" />
      </div>
      {!data ? <Loading /> : (
        <div className="table-wrap table-wrap--plain table-wrap--scroll">
          <table className="table table--compact">
            <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th className="num">Debit</th><th className="num">Credit</th><th className="num">Balance</th></tr></thead>
            <tbody>
              <tr className="is-muted"><td colSpan={5}>Opening balance</td><td className="num">{money(data.opening_balance)}</td></tr>
              {data.lines.map((l, i) => (
                <tr key={i}>
                  <td>{date(l.entry_date)}</td><td className="mono small">{l.entry_number}</td>
                  <td style={{ whiteSpace: 'normal' }}>{l.description}<div className="small muted">{titleCase(l.source_type)}{l.posted_by ? ` · ${l.posted_by}` : ''}</div></td>
                  <td className="num">{Number(l.debit) ? money(l.debit) : ''}</td><td className="num">{Number(l.credit) ? money(l.credit) : ''}</td>
                  <td className="num">{money(l.balance)}</td>
                </tr>
              ))}
              <tr><td colSpan={5}><strong>Closing balance</strong></td><td className="num"><strong>{money(data.closing_balance)}</strong></td></tr>
            </tbody>
          </table>
        </div>
      )}
    </Modal>
  )
}

function PnlTab() {
  const { user } = useAuth()
  const today = user?.settings.business_date ?? ''
  const [from, setFrom] = useState(`${today.slice(0, 8)}01`)
  const [to, setTo] = useState(today)
  const { data, error } = useFetch<{ income: { code: string; name: string; amount: string }[]; expenses: { code: string; name: string; amount: string }[]; total_income: string; total_expenses: string; net_profit: string }>(
    `/accounting/income-statement?date_from=${from}&date_to=${to}`,
  )
  return (
    <>
      <div className="toolbar">
        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="From" />
        <input type="date" value={to} onChange={(e) => setTo(e.target.value)} aria-label="To" />
      </div>
      <ErrorBanner error={error} />
      {!data ? <Loading /> : (
        <section className="card">
          <h2 className="card__title">Profit &amp; loss · {date(from)} – {date(to)}</h2>
          <Statement title="Income" rows={data.income} total={data.total_income} />
          <Statement title="Expenses" rows={data.expenses} total={data.total_expenses} />
          <div className="highlight row row--between" style={{ marginTop: 16 }}>
            <span className="highlight__label" style={{ fontSize: 15 }}>Net {Number(data.net_profit) >= 0 ? 'profit' : 'loss'}</span>
            <span className="highlight__value">{money(data.net_profit)}</span>
          </div>
        </section>
      )}
    </>
  )
}

function BalanceTab() {
  const { user } = useAuth()
  const [asOf, setAsOf] = useState(user?.settings.business_date ?? '')
  const { data, error } = useFetch<{ assets: (Account & { signed: string })[]; liabilities: Account[]; equity: Account[]; retained_earnings: string; total_assets: string; total_liabilities: string; total_equity: string; balanced: boolean }>(
    `/accounting/balance-sheet?as_of=${asOf}`,
  )
  return (
    <>
      <div className="toolbar"><input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} aria-label="As of" /></div>
      <ErrorBanner error={error} />
      {!data ? <Loading /> : (
        <div className="grid grid--2">
          <section className="card">
            <h2 className="card__title">Assets</h2>
            <Statement title="" rows={data.assets.map((a) => ({ code: a.code, name: a.name, amount: a.signed }))} total={data.total_assets} totalLabel="Total assets" />
          </section>
          <section className="card">
            <h2 className="card__title">Liabilities &amp; equity</h2>
            <Statement title="Liabilities" rows={data.liabilities.map((a) => ({ code: a.code, name: a.name, amount: a.balance }))} total={data.total_liabilities} />
            <Statement title="Equity" rows={[...data.equity.map((a) => ({ code: a.code, name: a.name, amount: a.balance })), { code: '—', name: 'Retained earnings (P&L to date)', amount: data.retained_earnings }]} total={data.total_equity} />
            <div className={`banner ${data.balanced ? 'banner--success' : 'banner--error'}`} style={{ marginTop: 12 }}>
              {data.balanced ? 'Balanced: assets = liabilities + equity' : 'Not balanced — investigate immediately'}
            </div>
          </section>
        </div>
      )}
    </>
  )
}

function Statement({ title, rows, total, totalLabel }: { title: string; rows: { code: string; name: string; amount: string }[]; total: string; totalLabel?: string }) {
  return (
    <>
      {title ? <h3 className="card__subtitle">{title}</h3> : null}
      <dl className="details details--compact">
        {rows.filter((r) => Number(r.amount) !== 0).map((r) => (
          <div key={r.code + r.name} className="details__row"><dt><span className="mono small muted">{r.code}</span> {r.name}</dt><dd>{money(r.amount)}</dd></div>
        ))}
        <div className="details__row details__row--total"><dt>{totalLabel ?? `Total ${title.toLowerCase()}`}</dt><dd>{money(total)}</dd></div>
      </dl>
    </>
  )
}

function ProvisionsTab() {
  const { can } = useAuth()
  const { data, error, reload } = useFetch<{ held: string; rates: Record<string, string>; runs: { id: string; run_date: string; required_amount: string; held_before: string; adjustment: string; created_at: string; detail: { asset_class: string; loans: number; principal: string; rate: string; provision: string }[] }[] }>('/accounting/provisions')
  const [runError, setRunError] = useState<unknown>(null)
  if (!data) return error ? <ErrorBanner error={error} /> : <Loading />
  const latest = data.runs[0]
  return (
    <div className="stack">
      <section className="card">
        <div className="card__header">
          <h2 className="card__title">Loan-loss provision</h2>
          {can('accounting.reconcile') ? (
            <button type="button" className="btn" onClick={() => api.post('/accounting/provisions/run').then(reload).catch(setRunError)}>Recalculate now</button>
          ) : null}
        </div>
        <ErrorBanner error={runError} />
        <div className="stats">
          <div className="kpi kpi--violet"><div className="kpi__label">Provision held (1190)</div><div className="kpi__value">{money(data.held)}</div></div>
          {latest ? <div className="kpi kpi--blue"><div className="kpi__label">Required at last run</div><div className="kpi__value">{money(latest.required_amount)}</div><div className="kpi__hint">{date(latest.run_date)}</div></div> : null}
        </div>
        <p className="small muted">Rates: standard {data.rates.standard}% · sub-standard {data.rates.substandard}% · doubtful {data.rates.doubtful}% · loss {data.rates.loss}% of principal outstanding. Change them in Settings. The daily job trues the provision up or writes it back.</p>
        {latest ? (
          <table className="table table--compact" style={{ marginTop: 12 }}>
            <thead><tr><th>Asset class</th><th className="num">Loans</th><th className="num">Principal</th><th className="num">Rate</th><th className="num">Provision</th></tr></thead>
            <tbody>
              {latest.detail.map((d) => (
                <tr key={d.asset_class}><td><AssetClassBadge value={d.asset_class} /></td><td className="num">{d.loans}</td><td className="num">{money(d.principal)}</td><td className="num">{d.rate}%</td><td className="num">{money(d.provision)}</td></tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </section>
      <section className="card card--flush">
        <div className="card__header"><h2 className="card__title">Run history</h2></div>
        <div className="table-wrap"><table className="table table--compact">
          <thead><tr><th>Date</th><th className="num">Required</th><th className="num">Held before</th><th className="num">Adjustment</th></tr></thead>
          <tbody>{data.runs.map((r) => <tr key={r.id}><td>{dateTime(r.created_at)}</td><td className="num">{money(r.required_amount)}</td><td className="num">{money(r.held_before)}</td><td className="num">{money(r.adjustment)}</td></tr>)}</tbody>
        </table></div>
      </section>
    </div>
  )
}

function SettlementTab() {
  const { can, user } = useAuth()
  const { data: unsettled, reload } = useFetch<{ id: string; payment_number: string; amount: string; reference: string; business_date: string; customer_name: string }[]>('/accounting/settlements/unsettled')
  const { rows: batches, meta: batchMeta, page: batchPage, setPage: setBatchPage, reload: reloadBatches } = useLocalPagedList<{ id: string; batch_number: string; bank_reference: string; settlement_date: string; gross_amount: string; fees: string; net_amount: string; payment_count: string; created_by_name: string }>('/accounting/settlements')
  const [selected, setSelected] = useState<string[]>([])
  const [reference, setReference] = useState('')
  const [settlementDate, setSettlementDate] = useState(user?.settings.business_date ?? '')
  const [fees, setFees] = useState('0')
  const [credited, setCredited] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [notice, setNotice] = useState('')
  const gross = (unsettled ?? []).filter((p) => selected.includes(p.id)).reduce((s, p) => s + Number(p.amount), 0)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.post('/accounting/settlements', { payment_ids: selected.map(Number), bank_reference: reference, settlement_date: settlementDate, fees, amount_credited: credited })
      setSelected([])
      setReference('')
      setCredited('')
      setNotice('Settlement reconciled: clearing moved to bank and fees expensed.')
      reload()
      reloadBatches()
    } catch (err) {
      setError(err)
    }
  }

  return (
    <div className="stack">
      {notice ? <Notice>{notice}</Notice> : null}
      <section className="card">
        <h2 className="card__title" style={{ marginBottom: 6 }}>Unsettled UPI collections</h2>
        <p className="small muted" style={{ marginBottom: 12 }}>Confirmed UPI payments sit in "Payment provider clearing" until the provider pays them out. Tick the payments in a bank credit, enter the bank reference and fees, and the amounts must match exactly.</p>
        {(unsettled ?? []).length === 0 ? <p className="muted">Nothing awaiting settlement.</p> : (
          <table className="table table--compact">
            <thead><tr><th /><th>Payment</th><th>Customer</th><th>UPI ref</th><th>Date</th><th className="num">Amount</th></tr></thead>
            <tbody>
              {(unsettled ?? []).map((p) => (
                <tr key={p.id}>
                  <td><input type="checkbox" checked={selected.includes(p.id)} onChange={(e) => setSelected((s) => (e.target.checked ? [...s, p.id] : s.filter((x) => x !== p.id)))} aria-label={`Include ${p.payment_number}`} /></td>
                  <td className="mono small">{p.payment_number}</td><td>{p.customer_name}</td><td className="mono small">{p.reference}</td><td>{date(p.business_date)}</td><td className="num">{money(p.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {can('accounting.reconcile') && selected.length > 0 ? (
          <form onSubmit={submit} className="stack" style={{ marginTop: 14 }}>
            <ErrorBanner error={bannerError(error)} />
            <div className="grid grid--4 grid--tight">
              <Field label="Bank reference *" error={fieldErrors(error).bank_reference}><input value={reference} onChange={(e) => setReference(e.target.value)} /></Field>
              <Field label="Settlement date" error={fieldErrors(error).settlement_date}><input type="date" value={settlementDate} onChange={(e) => setSettlementDate(e.target.value)} /></Field>
              <Field label="Provider fees (₹)" error={fieldErrors(error).fees}><input inputMode="decimal" value={fees} onChange={(e) => setFees(e.target.value)} /></Field>
              <Field label="Amount credited (₹) *" error={fieldErrors(error).amount_credited} hint={`Selected ${money(String(gross))} − fees = ${money(String(gross - Number(fees || 0)))}`}><input inputMode="decimal" value={credited} onChange={(e) => setCredited(e.target.value)} /></Field>
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}><button type="submit" className="btn btn--primary">Reconcile {selected.length} payment(s)</button></div>
          </form>
        ) : null}
      </section>
      <section className="card card--flush">
        <div className="card__header"><h2 className="card__title">Settlement batches</h2></div>
        <div className="table-wrap"><table className="table table--compact">
          <thead><tr><th>Batch</th><th>Bank ref</th><th>Date</th><th className="num">Payments</th><th className="num">Gross</th><th className="num">Fees</th><th className="num">Net</th><th>By</th></tr></thead>
          <tbody>
            {(batches ?? []).length === 0 ? <tr><td colSpan={8} className="table__empty">No settlements yet.</td></tr> : (batches ?? []).map((b) => (
              <tr key={b.id}><td className="mono">{b.batch_number}</td><td className="mono small">{b.bank_reference}</td><td>{date(b.settlement_date)}</td><td className="num">{b.payment_count}</td><td className="num">{money(b.gross_amount)}</td><td className="num">{money(b.fees)}</td><td className="num">{money(b.net_amount)}</td><td>{b.created_by_name}</td></tr>
            ))}
          </tbody>
        </table></div>
        {batchMeta ? <Pager page={batchPage} totalPages={batchMeta.total_pages} onPage={setBatchPage} /> : null}
      </section>
    </div>
  )
}

function JournalTab() {
  const { data } = useFetch<{ accounts: Account[] }>('/accounting/accounts')
  const allowed = (data?.accounts ?? []).filter((a) => !CONTROL.includes(a.code))
  const [description, setDescription] = useState('')
  const [reason, setReason] = useState('')
  const [lines, setLines] = useState([{ account_code: '5500', debit: '', credit: '' }, { account_code: '1020', debit: '', credit: '' }])
  const [error, setError] = useState<unknown>(null)
  const [notice, setNotice] = useState('')
  const debits = lines.reduce((s, l) => s + Number(l.debit || 0), 0)
  const credits = lines.reduce((s, l) => s + Number(l.credit || 0), 0)
  const errors = fieldErrors(error)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await api.post('/accounting/journals', { description, reason, lines: lines.map((l) => ({ account_code: l.account_code, debit: l.debit || '0', credit: l.credit || '0' })) })
      setNotice('Journal sent for approval. It posts only after another authorised person approves it.')
      setDescription('')
      setReason('')
      setLines([{ account_code: '5500', debit: '', credit: '' }, { account_code: '1020', debit: '', credit: '' }])
    } catch (err) {
      setError(err)
    }
  }

  return (
    <form className="card stack" onSubmit={submit}>
      <h2 className="card__title">New manual journal</h2>
      <p className="small muted">For expenses, capital, bank charges and corrections. Control accounts (loans, interest, deposits, agent cash, clearing, provision) are excluded — the system maintains them from transactions.</p>
      {notice ? <Notice>{notice}</Notice> : null}
      <ErrorBanner error={bannerError(error)} />
      <div className="grid grid--2 grid--tight">
        <Field label="Description *" error={errors.description}><input value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Office rent – October" /></Field>
        <Field label="Supporting reason / document *" error={errors.reason}><input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Invoice #44 from landlord" /></Field>
      </div>
      <table className="table table--compact">
        <thead><tr><th>Account</th><th className="num">Debit</th><th className="num">Credit</th><th /></tr></thead>
        <tbody>
          {lines.map((l, i) => (
            <tr key={i}>
              <td>
                <select value={l.account_code} onChange={(e) => setLines((ls) => ls.map((x, j) => (j === i ? { ...x, account_code: e.target.value } : x)))}>
                  {allowed.map((a) => <option key={a.code} value={a.code}>{a.code} · {a.name}</option>)}
                </select>
                {errors[`lines.${i}`] ? <div className="field__error">{errors[`lines.${i}`]}</div> : null}
              </td>
              <td><input inputMode="decimal" value={l.debit} onChange={(e) => setLines((ls) => ls.map((x, j) => (j === i ? { ...x, debit: e.target.value, credit: '' } : x)))} /></td>
              <td><input inputMode="decimal" value={l.credit} onChange={(e) => setLines((ls) => ls.map((x, j) => (j === i ? { ...x, credit: e.target.value, debit: '' } : x)))} /></td>
              <td>{lines.length > 2 ? <button type="button" className="btn btn--ghost btn--small" onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}>Remove</button> : null}</td>
            </tr>
          ))}
          <tr>
            <td><button type="button" className="btn btn--small" onClick={() => setLines((ls) => [...ls, { account_code: allowed[0]?.code ?? '5500', debit: '', credit: '' }])}>Add line</button></td>
            <td className="num"><strong>{money(String(debits))}</strong></td>
            <td className="num"><strong>{money(String(credits))}</strong></td>
            <td>{debits === credits && debits > 0 ? <span className="badge badge--success">Balanced</span> : <span className="badge badge--danger">Unbalanced</span>}</td>
          </tr>
        </tbody>
      </table>
      {errors.lines ? <div className="field__error">{errors.lines}</div> : null}
      <div className="row" style={{ justifyContent: 'flex-end' }}><button type="submit" className="btn btn--primary" disabled={debits !== credits || debits === 0}>Submit for approval</button></div>
    </form>
  )
}

const CLASS_TONE: Record<string, string> = {
  standard: 'success', sma_0: 'warning', sma_1: 'warning', sma_2: 'warning', substandard: 'danger', doubtful: 'danger', loss: 'danger',
}
const CLASS_LABEL: Record<string, string> = {
  standard: 'Standard', sma_0: 'SMA-0', sma_1: 'SMA-1', sma_2: 'SMA-2', substandard: 'NPA · Sub-standard', doubtful: 'NPA · Doubtful', loss: 'NPA · Loss',
}

export function AssetClassBadge({ value }: { value: string }) {
  return <span className={`badge badge--${CLASS_TONE[value] ?? 'info'}`}>{CLASS_LABEL[value] ?? titleCase(value)}</span>
}

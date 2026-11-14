import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { CustomerPicker } from '../components/CustomerPicker'
import { RecordPaymentModal } from '../components/RecordPaymentModal'
import { Details, ErrorBanner, Field, Loading, Modal, Notice, PageHeader, Pager, Segmented, StatusBadge, Tabs, bannerError, fieldErrors } from '../components/ui'
import { date, dateTime, money, percent, titleCase } from '../format'
import { useFetch, usePagedList } from '../hooks'
import type { DepositAccount, DepositTerms, PaymentMethod, Payout } from '../types'
import { PlanPicker } from '../components/PlanPicker'
import { DEPOSIT_TYPES, coversLabel, depositPer, depositTenures, depositType, listOr, rateSummary, tenureKey, tenureLabel } from '../plans'
import { TenurePicker } from '../components/Tenure'
import { ContributionCalendar } from '../components/ContributionCalendar'

interface Row {
  id: string
  deposit_number: string
  scheme: string
  status: string
  installment_amount: string | null
  deposit_amount: string | null
  opened_on: string
  maturity_date: string
  principal_balance: string
  accrued_interest: string
  customer_name: string
  customer_number: string
  product_name: string
}

export function DepositGate({ children }: { children: ReactNode }) {
  const { user, can } = useAuth()
  if (!user?.settings.deposits_enabled) {
    return (
      <>
        <PageHeader title="Deposits" />
        <div className="card stack">
          <Notice tone="warning">Deposit-taking is switched off until compliance approval.</Notice>
          <p>Once it is on, you can open daily, weekly and monthly savings accounts and fixed deposits for customers, and collect them on your route.</p>
          {can('settings.manage') ? (
            <p>
              <Link to="/settings" className="btn btn--primary">Turn on in Settings</Link>
            </p>
          ) : (
            <p className="muted">Ask your Super Admin to turn on deposit-taking in Settings.</p>
          )}
        </div>
      </>
    )
  }
  return <>{children}</>
}

export function Deposits() {
  const { can } = useAuth()
  const { rows, meta, error, params, update } = usePagedList<Row>('/deposit-accounts')
  const [search, setSearch] = useState(params.get('q') ?? '')

  return (
    <DepositGate>
      <PageHeader
        title="Savings"
        subtitle={meta ? `${meta.total.toLocaleString('en-IN')} accounts` : undefined}
        actions={
          can('deposits.open') ? (
            <Link to="/deposits/new" className="btn btn--primary">
              Open account
            </Link>
          ) : null
        }
      />
      <div className="toolbar">
        <Segmented
          options={[
            { id: '', label: 'All' },
            { id: 'active', label: 'Active' },
            { id: 'matured', label: 'Matured' },
            { id: 'pending_closure', label: 'Closing' },
            { id: 'closed', label: 'Closed' },
          ]}
          value={params.get('status') ?? ''}
          onChange={(status) => update({ status })}
        />
      </div>
      <form className="toolbar" onSubmit={(e) => { e.preventDefault(); update({ q: search.trim() }) }}>
        <input type="search" placeholder="Deposit number or customer" value={search} onChange={(e) => setSearch(e.target.value)} />
      </form>
      <ErrorBanner error={error} />
      {rows === null && !error ? <Loading /> : null}
      {rows ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Account</th>
                <th>Customer</th>
                <th>Product</th>
                <th className="num">Balance</th>
                <th className="num">Accrued interest</th>
                <th>Matures</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={7} className="table__empty">No deposit accounts.</td>
                </tr>
              ) : (
                rows.map((d) => (
                  <tr key={d.id}>
                    <td><Link to={`/deposits/${d.id}`} className="mono">{d.deposit_number}</Link></td>
                    <td>{d.customer_name}<div className="small muted mono">{d.customer_number}</div></td>
                    <td>{d.product_name}<div className="small muted">{titleCase(d.scheme)}</div></td>
                    <td className="num">{money(d.principal_balance)}</td>
                    <td className="num">{money(d.accrued_interest)}</td>
                    <td>{date(d.maturity_date)}</td>
                    <td><StatusBadge status={d.status} /></td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      ) : null}
      {meta ? <Pager page={meta.page} totalPages={meta.total_pages} onPage={(p) => update({ page: String(p) })} /> : null}
    </DepositGate>
  )
}

interface DepositProduct {
  id: string
  code: string
  name: string
  status: string
  terms: DepositTerms
}


export function DepositNew() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const { data: products } = useFetch<DepositProduct[]>('/deposit-products')
  const [customerId, setCustomerId] = useState(params.get('customer_id') ?? '')
  const [productId, setProductId] = useState('')
  const [amount, setAmount] = useState('')
  const [tenureChoice, setTenureChoice] = useState('')
  const [preview, setPreview] = useState<{ total_deposited: string; interest_earned: string; maturity_value: string; installments: number } | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const errors = fieldErrors(error)
  const active = (products ?? []).filter((p) => p.status === 'active')
  const product = active.find((p) => p.id === productId)

  const plans = active.map((p) => ({
    id: p.id,
    type: depositType(p.terms),
    name: p.name,
    lines: [
      `${rateSummary(p.terms.annual_interest_rate, depositTenures(p.terms), depositPer(p.terms))} · ${listOr(depositTenures(p.terms).map(tenureLabel))}`,
      p.terms.scheme === 'recurring' ? `From ${money(p.terms.min_installment)} per installment` : `${money(p.terms.min_amount)} – ${money(p.terms.max_amount)}`,
    ],
  }))

  const tenures = product ? depositTenures(product.terms) : []
  const tenure = tenures.find((o) => tenureKey(o) === tenureChoice) ?? tenures[0]
  useEffect(() => {
    setPreview(null)
    if (!product || !amount) return
    const t = setTimeout(() => {
      api
        .post<typeof preview>('/deposit-products/preview', { terms: product.terms, amount, ...(tenure ? { tenure_value: tenure.value, tenure_unit: tenure.unit } : {}) })
        .then(setPreview)
        .catch(() => setPreview(null))
    }, 350)
    return () => clearTimeout(t)
  }, [product, amount, tenure])

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const account = await api.post<DepositAccount>('/deposit-accounts', {
        customer_id: Number(customerId),
        product_id: Number(productId),
        amount,
        ...(tenure ? { tenure_value: tenure.value, tenure_unit: tenure.unit } : {}),
      })
      navigate(`/deposits/${account.id}`)
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <DepositGate>
      <PageHeader title="Open savings account" back={{ to: '/deposits', label: 'Savings' }} />
      <div className="grid grid--2-1">
        <form className="card stack" onSubmit={submit} noValidate>
          <ErrorBanner error={bannerError(error)} />
          <Field label="Customer *" error={errors.customer_id}>
            <CustomerPicker value={customerId} onChange={setCustomerId} />
          </Field>
          <Field label="Account type *" error={errors.product_id}>
            <PlanPicker types={DEPOSIT_TYPES} plans={plans} value={productId} onChange={setProductId} />
          </Field>
          {product ? (
            <Field
              label={product.terms.scheme === 'recurring' ? `${titleCase(product.terms.contribution_frequency)} installment (₹) *` : 'Deposit amount (₹) *'}
              error={errors.amount}
              hint={product.terms.scheme === 'recurring' ? `Minimum ${money(product.terms.min_installment)}` : `${money(product.terms.min_amount)} – ${money(product.terms.max_amount)}`}
            >
              <input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
            </Field>
          ) : null}
          {product && tenures.length > 0 ? (
            <Field label="Tenure *" error={errors.tenure}>
              <TenurePicker options={tenures} value={tenure ? tenureKey(tenure) : ''} onChange={(o) => setTenureChoice(tenureKey(o))} per={product ? depositPer(product.terms) : undefined} />
            </Field>
          ) : null}
          {product?.terms.scheme === 'fixed' ? <p className="small muted">After opening, record the deposit amount as a payment to fund the account.</p> : null}
          <div className="form__actions">
            <button type="submit" className="btn btn--primary" disabled={saving || !customerId}>Open account</button>
          </div>
        </form>
        <section className="card">
          <h2 className="card__title">Illustration at maturity</h2>
          {preview ? (
            <>
              <div className="highlight">
                <div className="highlight__label">Maturity value</div>
                <div className="highlight__value">{money(preview.maturity_value)}</div>
              </div>
              <Details compact rows={[['Installments', String(preview.installments)], ['Total deposited', money(preview.total_deposited)], ['Interest earned', money(preview.interest_earned)]]} />
              <p className="small muted" style={{ marginTop: 8 }}>Assumes every installment is paid on time. Actual interest accrues daily on the real balance.</p>
            </>
          ) : (
            <p className="muted">Enter an amount to see the illustration.</p>
          )}
        </section>
      </div>
    </DepositGate>
  )
}

export function DepositDetail() {
  const { id } = useParams()
  const { user, can } = useAuth()
  const { data: d, error, reload } = useFetch<DepositAccount>(`/deposit-accounts/${id}`)
  const [tab, setTab] = useState<'contributions' | 'interest' | 'payouts'>('contributions')
  const [paying, setPaying] = useState(false)
  const [requesting, setRequesting] = useState(false)
  const [notice, setNotice] = useState('')

  if (!d) return error ? <ErrorBanner error={error} /> : <Loading />
  const isCustomer = user?.customer_scope === 'own'

  return (
    <>
      <PageHeader
        back={isCustomer ? { to: '/', label: 'My accounts' } : { to: '/deposits', label: 'Savings' }}
        title={<>{d.product_name} <span className="mono muted" style={{ fontSize: 18 }}>{d.deposit_number}</span></>}
        subtitle={<><StatusBadge status={d.status} />{isCustomer ? null : <Link to={`/customers/${d.customer_id}`}>{d.customer_name}</Link>}<span>· {d.branch_name}</span></>}
        actions={
          d.deposits_enabled ? (
            <>
              <Link to={`/deposits/${d.id}/statement`} className="btn">Statement</Link>
              {d.status === 'active' && can('payments.create') ? (
                <button type="button" className="btn btn--primary" onClick={() => setPaying(true)}>Record contribution</button>
              ) : null}
              {d.payout_quote && can('payouts.request') ? (
                <button type="button" className="btn" onClick={() => setRequesting(true)}>
                  Request {d.payout_quote.payout_type === 'maturity' ? 'maturity payout' : 'premature closure'}
                </button>
              ) : null}
            </>
          ) : null
        }
      />
      {notice ? <Notice>{notice}</Notice> : null}
      {!d.deposits_enabled ? <Notice tone="warning">Deposit operations are currently disabled. Balances are shown read-only.</Notice> : null}

      <div className="stats">
        <div className="stat stat--accent">
          <div className="stat__label">Balance</div>
          <div className="stat__value">{money(d.principal_balance)}</div>
          <div className="stat__hint">{d.scheme === 'recurring' ? `${money(d.installment_amount)} ${d.terms.contribution_frequency}` : `Fixed ${money(d.deposit_amount)}`}</div>
        </div>
        <div className="stat">
          <div className="stat__label">Interest accrued</div>
          <div className="stat__value">{money(d.accrued_interest)}</div>
          <div className="stat__hint">{percent(d.terms.annual_interest_rate)} {d.terms.interest_method === 'flat' ? 'flat' : 'p.a.'} · to {date(d.last_accrual_date)}</div>
        </div>
        <div className="stat">
          <div className="stat__label">Maturity</div>
          <div className="stat__value" style={{ fontSize: 22 }}>{date(d.maturity_date)}</div>
          <div className="stat__hint">Opened {date(d.opened_on)}</div>
        </div>
        {d.payout_quote ? (
          <div className="stat">
            <div className="stat__label">{d.payout_quote.payout_type === 'maturity' ? 'Payable at maturity' : 'If closed today'}</div>
            <div className="stat__value">{money(d.payout_quote.net_amount)}</div>
            {Number(d.payout_quote.penalty_amount) > 0 ? <div className="stat__hint">after penalty {money(d.payout_quote.penalty_amount)}</div> : null}
          </div>
        ) : d.payout_blocked_reason ? (
          <div className="stat">
            <div className="stat__label">Early closure</div>
            <div className="stat__hint" style={{ marginTop: 6 }}>{d.payout_blocked_reason}</div>
          </div>
        ) : null}
      </div>

      {d.calendar && d.calendar.length > 0 ? (
        <div style={{ marginBottom: 16 }}>
          <ContributionCalendar days={d.calendar} status={d.status === 'active' ? d.contribution : undefined} frequency={d.terms.contribution_frequency} installment={d.installment_amount} />
        </div>
      ) : null}

      <Tabs
        tabs={[
          { id: 'contributions', label: `Contributions (${d.contributions.length})` },
          { id: 'interest', label: 'Interest' },
          { id: 'payouts', label: `Payouts (${d.payouts.length})` },
        ]}
        value={tab}
        onChange={setTab}
      />

      {tab === 'contributions' ? (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Payment</th><th>Date</th><th>For</th><th>Method</th><th>Collected by</th><th>Status</th><th className="num">Amount</th></tr></thead>
            <tbody>
              {d.contributions.length === 0 ? (
                <tr><td colSpan={7} className="table__empty">No contributions yet.</td></tr>
              ) : (
                d.contributions.map((p) => (
                  <tr key={p.id} className={p.status === 'reversed' ? 'is-muted' : undefined}>
                    <td><Link to={`/payments/${p.id}`} className="mono">{p.payment_number}</Link></td>
                    <td>{date(p.business_date)}</td>
                    <td className="small">{coversLabel(p, date) ?? '—'}</td>
                    <td>{titleCase(p.method)}</td>
                    <td>{p.collected_by_name}</td>
                    <td><StatusBadge status={p.status} /></td>
                    <td className="num">{money(p.amount)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      ) : null}

      {tab === 'interest' ? (
        <div className="grid grid--2">
          <section className="card">
            <h2 className="card__title">Terms (version {d.product_version})</h2>
            <Details
              rows={[
                ['Rate', d.terms.interest_method === 'flat'
                  ? `${percent(d.terms.annual_interest_rate)} flat on the total deposited, paid at maturity`
                  : `${percent(d.terms.annual_interest_rate)} p.a., simple, accrued daily`],
                ['Tenure', d.tenure_value && d.tenure_unit ? tenureLabel({ value: Number(d.tenure_value), unit: d.tenure_unit }) : listOr(depositTenures(d.terms).map(tenureLabel))],
                ['Premature closure', d.terms.premature_withdrawal_allowed ? `After ${d.terms.lock_in_days} days, rate reduced by ${percent(d.terms.premature_penalty_percent)}` : 'Not allowed'],
                ['Interest paid to date', money(d.interest_paid)],
              ]}
            />
          </section>
          <section className="card card--flush">
            <div className="card__header"><h2 className="card__title">Recent accruals</h2></div>
            <div className="table-wrap table-wrap--scroll">
              <table className="table table--compact">
                <tbody>
                  {d.accruals.length === 0 ? (
                    <tr><td className="table__empty">No interest accrued yet.</td></tr>
                  ) : (
                    d.accruals.map((a, i) => (
                      <tr key={i}><td>{date(a.from_date)} – {date(a.to_date)}</td><td className="num">{money(a.amount)}</td></tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </section>
        </div>
      ) : null}

      {tab === 'payouts' ? <PayoutTable payouts={d.payouts} onChanged={reload} /> : null}

      {paying ? (
        <RecordPaymentModal
          target={{ accountType: 'deposit', accountId: d.id, accountNumber: d.deposit_number, customerName: d.customer_name, suggestedAmount: d.scheme === 'fixed' ? d.deposit_amount ?? undefined : d.installment_amount ?? undefined }}
          onClose={() => setPaying(false)}
          onRecorded={reload}
        />
      ) : null}
      {requesting && d.payout_quote ? (
        <RequestPayoutModal
          account={d}
          onClose={() => setRequesting(false)}
          onDone={() => {
            setRequesting(false)
            setNotice('Payout requested. It needs approval from another person before it can be paid.')
            reload()
          }}
        />
      ) : null}
    </>
  )
}

function RequestPayoutModal({ account, onClose, onDone }: { account: DepositAccount; onClose: () => void; onDone: () => void }) {
  const q = account.payout_quote!
  const [note, setNote] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      await api.post(`/deposit-accounts/${account.id}/payout-request`, { note: note || null })
      onDone()
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={q.payout_type === 'maturity' ? 'Request maturity payout' : 'Request premature closure'} onClose={onClose}>
      <form onSubmit={submit} className="stack">
        <Details compact rows={[['Principal', money(q.principal_amount)], ['Interest', money(q.interest_amount)], ['Penalty', money(q.penalty_amount)], [<strong key="l">Net payable</strong>, <strong key="v">{money(q.net_amount)}</strong>]]} />
        <p className="small muted">Interest stops accruing while the request is open. Figures include interest up to yesterday.</p>
        <ErrorBanner error={error} />
        <Field label="Note"><input value={note} onChange={(e) => setNote(e.target.value)} /></Field>
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={saving}>Submit request</button>
        </div>
      </form>
    </Modal>
  )
}

export function PayoutTable({ payouts, onChanged }: { payouts: Payout[]; onChanged: () => void }) {
  const { user, can } = useAuth()
  const [acting, setActing] = useState<{ payout: Payout; mode: 'decide' | 'pay' } | null>(null)

  return (
    <>
      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr><th>Payout</th><th>Account</th><th>Type</th><th>Requested</th><th className="num">Net amount</th><th>Status</th><th /></tr>
          </thead>
          <tbody>
            {payouts.length === 0 ? (
              <tr><td colSpan={7} className="table__empty">No payout requests. They appear here when someone requests a payout from a savings account.</td></tr>
            ) : (
              payouts.map((p) => (
                <tr key={p.id}>
                  <td className="mono">{p.payout_number}</td>
                  <td>
                    <Link to={`/deposits/${p.deposit_account_id}`} className="mono">{p.deposit_number}</Link>
                    {p.customer_name ? <div className="small muted">{p.customer_name}</div> : null}
                  </td>
                  <td>{titleCase(p.payout_type)}</td>
                  <td>{p.requested_by_name}<div className="small muted">{dateTime(p.requested_at)}</div></td>
                  <td className="num">{money(p.net_amount)}</td>
                  <td><StatusBadge status={p.status} /></td>
                  <td>
                    {p.status === 'requested' && can('payouts.approve') && (Number(p.requested_by) !== user?.id || Boolean(user?.settings.self_approval)) ? (
                      <button type="button" className="btn btn--small" onClick={() => setActing({ payout: p, mode: 'decide' })}>Review</button>
                    ) : p.status === 'approved' && can('payouts.pay') ? (
                      <button type="button" className="btn btn--small btn--primary" onClick={() => setActing({ payout: p, mode: 'pay' })}>Pay</button>
                    ) : null}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
      {acting ? <PayoutActionModal {...acting} onClose={() => setActing(null)} onDone={() => { setActing(null); onChanged() }} /> : null}
    </>
  )
}

function PayoutActionModal({ payout, mode, onClose, onDone }: { payout: Payout; mode: 'decide' | 'pay'; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('')
  const [method, setMethod] = useState<PaymentMethod>('upi')
  const [reference, setReference] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const errors = fieldErrors(error)

  async function run(body: Record<string, unknown>, path: string) {
    setSaving(true)
    setError(null)
    try {
      await api.post(`/payout-requests/${payout.id}/${path}`, body)
      onDone()
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={mode === 'pay' ? 'Pay out' : 'Review payout'} onClose={onClose}>
      <Details
        compact
        rows={[
          ['Account', payout.deposit_number],
          ['Principal', money(payout.principal_amount)],
          ['Interest', money(payout.interest_amount)],
          ['Penalty', money(payout.penalty_amount)],
          [<strong key="l">Net payable</strong>, <strong key="v">{money(payout.net_amount)}</strong>],
        ]}
      />
      <ErrorBanner error={bannerError(error)} />
      {mode === 'decide' ? (
        <>
          <Field label="Reason (required to reject)" error={errors.reason}>
            <textarea value={reason} onChange={(e) => setReason(e.target.value)} />
          </Field>
          <div className="modal__footer">
            <button type="button" className="btn btn--danger" disabled={saving} onClick={() => run({ decision: 'rejected', reason }, 'decide')}>Reject</button>
            <button type="button" className="btn btn--primary" disabled={saving} onClick={() => run({ decision: 'approved', reason: reason || null }, 'decide')}>Approve</button>
          </div>
        </>
      ) : (
        <>
          <Field label="Method">
            <Segmented options={[{ id: 'upi', label: 'UPI' }, { id: 'cash', label: 'Cash' }]} value={method} onChange={setMethod} />
          </Field>
          {method !== 'cash' ? (
            <Field label="UTR / UPI reference *" error={errors.reference}>
              <input value={reference} onChange={(e) => setReference(e.target.value)} />
            </Field>
          ) : null}
          <div className="modal__footer">
            <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
            <button type="button" className="btn btn--primary" disabled={saving} onClick={() => run({ method, reference: reference || null }, 'pay')}>Confirm payment of {money(payout.net_amount)}</button>
          </div>
        </>
      )}
    </Modal>
  )
}

export function Payouts() {
  const { rows, meta, error, params, update, reload } = usePagedList<Payout>('/payout-requests')
  return (
    <DepositGate>
      <PageHeader title="Savings payouts" subtitle="Paying savings back to customers — when an account matures, or when the customer closes it early." />
      <details className="card payout-help" open={params.get('status') === null && (rows?.length ?? 0) === 0}>
        <summary><strong>How payouts work</strong></summary>
        <ol className="steps" style={{ marginTop: 12 }}>
          <li className="step"><span className="step__num">1</span><div><strong>Request</strong><div className="small muted">Open the savings account → <b>Request payout</b>. At maturity the customer gets savings + full interest; closing early pays the lower rate after the lock-in.</div></div></li>
          <li className="step"><span className="step__num">2</span><div><strong>Approve</strong><div className="small muted">A different person checks and approves it (or the owner, if single-owner approval is on).</div></div></li>
          <li className="step"><span className="step__num">3</span><div><strong>Pay</strong><div className="small muted">Finance pays it by cash or UPI and the account closes. The money leaves branch cash / bank in the books.</div></div></li>
        </ol>
      </details>
      <div className="toolbar">
        <Segmented
          options={[{ id: '', label: 'All' }, { id: 'requested', label: 'Requested' }, { id: 'approved', label: 'Approved' }, { id: 'paid', label: 'Paid' }, { id: 'rejected', label: 'Rejected' }]}
          value={params.get('status') ?? ''}
          onChange={(status) => update({ status })}
        />
      </div>
      <ErrorBanner error={error} />
      {rows === null && !error ? <Loading /> : null}
      {rows ? <PayoutTable payouts={rows} onChanged={reload} /> : null}
      {meta ? <Pager page={meta.page} totalPages={meta.total_pages} onPage={(p) => update({ page: String(p) })} /> : null}
    </DepositGate>
  )
}

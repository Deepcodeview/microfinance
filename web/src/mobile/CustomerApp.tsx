import { useCallback, useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { Link, NavLink, Navigate, Outlet, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { Icons } from '../components/icons'
import { LanguageSwitch } from '../components/LanguageSwitch'
import { MfaRequiredScreen } from '../components/MfaSetup'
import { UpdateBanner } from '../components/UpdateBanner'
import { PlanPicker } from '../components/PlanPicker'
import { Details, Empty, ErrorBanner, Field, Loading, Notice, PageHeader, StatusBadge, bannerError, fieldErrors } from '../components/ui'
import { date, money, percent, titleCase } from '../format'
import { useFetch } from '../hooks'
import { DEPOSIT_TYPES, LOAN_TYPES, LOAN_UNIT, depositPer, depositTenures, depositType, listOr, loanTenureSummary, rateSummary, tenureKey, tenureLabel } from '../plans'
import { TenurePicker } from '../components/Tenure'
import type { Catalog, CustomerApplication, DepositTerms, PublicLoanTerms, PublicPlan } from '../types'
import { AppRoleGate, MobileLayout } from './MobileLayout'

type Kind = 'loan' | 'savings'

// ---- Catalogue (plans are public; cached briefly so moving between screens is instant) -----------

let catalogCache: { at: number; data: Catalog } | null = null

export function useCatalog() {
  const [catalog, setCatalog] = useState<Catalog | null>(catalogCache?.data ?? null)
  const [error, setError] = useState<unknown>(null)
  useEffect(() => {
    if (catalogCache && Date.now() - catalogCache.at < 60_000) return
    api
      .get<Catalog>('/public/catalog')
      .then((data) => {
        catalogCache = { at: Date.now(), data }
        setCatalog(data)
      })
      .catch(setError)
  }, [])
  return { catalog, error }
}

const loanLines = (t: PublicLoanTerms) => [
  `${rateSummary(t.annual_interest_rate, t.tenure_options)} · ${titleCase(t.interest_method)}`,
  `${money(t.min_amount)} – ${money(t.max_amount)}`,
  loanTenureSummary(t),
]
const savingsLines = (t: DepositTerms) => [
  `${rateSummary(t.annual_interest_rate, depositTenures(t), depositPer(t))} · ${listOr(depositTenures(t).map(tenureLabel))}`,
  t.scheme === 'recurring' ? `From ${money(t.min_installment)} per installment` : `${money(t.min_amount)} – ${money(t.max_amount)}`,
]

/** Whole rupees for an input's starting value ("5000.0000" -> "5000"). */
const rupees = (v: string) => String(Math.round(Number(v)))

/** Same-app path from ?next=, so sign-in / registration return to the plan being applied for. */
function useNext(): string {
  const [params] = useSearchParams()
  const next = params.get('next') ?? ''
  return next.startsWith('/') && !next.startsWith('//') ? next : '/'
}

// ---- Shell -----------------------------------------------------------------------------------

/** Customer app frame: the full app once signed in, a browse-and-register frame before that. */
export function CustomerShell() {
  const { user, loading } = useAuth()
  if (loading) return <Loading />
  if (user?.mfa_setup_required) return <MfaRequiredScreen />
  if (user) {
    return (
      <AppRoleGate>
        <MobileLayout />
      </AppRoleGate>
    )
  }
  return <PublicLayout />
}

/** Screens that need a signed-in customer; others are sent to sign in and brought back afterwards. */
export function SignedIn() {
  const { user } = useAuth()
  const location = useLocation()
  if (!user) return <Navigate to={`/login?next=${encodeURIComponent(location.pathname + location.search)}`} replace />
  return <Outlet />
}

const PUBLIC_TABS = [
  { to: '/', label: 'Home', icon: Icons.home, end: true },
  { to: '/plans', label: 'Plans', icon: Icons.wallet },
  { to: '/register', label: 'Register', icon: Icons.users },
  { to: '/login', label: 'Sign in', icon: Icons.key },
]

function PublicLayout() {
  const { catalog } = useCatalog()
  const location = useLocation()
  useEffect(() => {
    window.scrollTo(0, 0)
  }, [location.pathname])
  return (
    <div className="m-shell">
      <header className="m-bar">
        <span className="brand__mark">K</span>
        <div className="m-bar__title">
          <div className="m-bar__app">{catalog?.company_name || 'KBSAVE'}</div>
          <div className="m-bar__sub">Loans &amp; savings</div>
        </div>
        <Link to="/login" className="btn btn--small">Sign in</Link>
      </header>
      <UpdateBanner />
      <main className="m-content">
        <Outlet />
      </main>
      <nav className="m-tabs" aria-label="Main">
        {PUBLIC_TABS.map((t) => (
          <NavLink key={t.to} to={t.to} end={t.end} className="m-tab">
            <t.icon />
            <span>{t.label}</span>
          </NavLink>
        ))}
      </nav>
    </div>
  )
}

// ---- Public home -----------------------------------------------------------------------------

export function Welcome() {
  const { catalog, error } = useCatalog()
  return (
    <>
      <section className="hero" aria-label="Welcome">
        <div className="hero__label">Welcome to {catalog?.company_name || 'KBSAVE'}</div>
        <div className="hero__value hero__value--title">Loans and savings at your doorstep</div>
        <div className="hero__sub">Daily, weekly and monthly plans. Register and apply from your phone — our agent collects at your door.</div>
        <div className="hero__actions">
          <Link to="/register" className="btn btn--light">Create account</Link>
          <Link to="/login" className="btn btn--ghost-light">Sign in</Link>
        </div>
      </section>

      <h2 className="form-section">How it works</h2>
      <ol className="steps">
        <li className="step"><span className="step__num">1</span><div><strong>Register</strong><div className="small muted">With your mobile number and address.</div></div></li>
        <li className="step"><span className="step__num">2</span><div><strong>Choose a plan &amp; apply</strong><div className="small muted">See the exact installment before you apply.</div></div></li>
        <li className="step"><span className="step__num">3</span><div><strong>We verify &amp; approve</strong><div className="small muted">Our team checks your details and confirms in the app.</div></div></li>
      </ol>

      <ErrorBanner error={error} />
      {catalog ? <PlanSections catalog={catalog} limit={3} /> : error ? null : <Loading />}

      <div className="card row row--between row--wrap" style={{ marginTop: 16 }}>
        <div>
          <strong className="serif" style={{ fontSize: 17 }}>Already a customer?</strong>
          <div className="small muted">Sign in with your mobile number to see loans, savings and receipts.</div>
        </div>
        <Link to="/login" className="btn">Sign in</Link>
      </div>
      <div style={{ marginTop: 16 }}>
        <LanguageSwitch block />
      </div>
    </>
  )
}

function PlanSections({ catalog, limit }: { catalog: Catalog; limit?: number }) {
  const loans = limit ? catalog.loan_products.slice(0, limit) : catalog.loan_products
  const savings = limit ? catalog.deposit_products.slice(0, limit) : catalog.deposit_products
  return (
    <>
      <div className="row row--between" style={{ alignItems: 'baseline' }}>
        <h2 className="form-section">Loan plans</h2>
        {limit && catalog.loan_products.length > limit ? <Link to="/plans" className="small">See all</Link> : null}
      </div>
      {loans.length === 0 ? (
        <p className="muted">No loan plans right now.</p>
      ) : (
        <div className="plan-list">
          {loans.map((p) => <PlanTile key={p.id} to={`/plans/loan/${p.id}`} name={p.name} badge={titleCase(p.terms.repayment_frequency)} lines={loanLines(p.terms)} />)}
        </div>
      )}
      {catalog.deposits_enabled ? (
        <>
          <div className="row row--between" style={{ alignItems: 'baseline' }}>
            <h2 className="form-section">Savings plans</h2>
            {limit && catalog.deposit_products.length > limit ? <Link to="/plans?tab=savings" className="small">See all</Link> : null}
          </div>
          {savings.length === 0 ? (
            <p className="muted">No savings plans right now.</p>
          ) : (
            <div className="plan-list">
              {savings.map((p) => (
                <PlanTile key={p.id} to={`/plans/savings/${p.id}`} name={p.name} badge={DEPOSIT_TYPES.find((t) => t.key === depositType(p.terms))?.label ?? ''} lines={savingsLines(p.terms)} />
              ))}
            </div>
          )}
        </>
      ) : null}
    </>
  )
}

function PlanTile({ to, name, badge, lines }: { to: string; name: string; badge: string; lines: string[] }) {
  return (
    <Link to={to} className="plan-card plan-tile">
      <span className="row row--between" style={{ width: '100%' }}>
        <span className="plan-card__name">{name}</span>
        {badge ? <span className="badge">{badge}</span> : null}
      </span>
      {lines.map((l) => <span key={l} className="plan-card__line">{l}</span>)}
      <span className="plan-tile__more">View details →</span>
    </Link>
  )
}

export function Plans() {
  const { catalog, error } = useCatalog()
  return (
    <>
      <PageHeader title="Loan & savings plans" subtitle="Tap a plan to see the full details and calculate your installment." />
      <ErrorBanner error={error} />
      {catalog ? <PlanSections catalog={catalog} /> : error ? null : <Loading />}
    </>
  )
}

// ---- Plan details + calculator --------------------------------------------------------------

export function PlanDetail() {
  const { kind, id } = useParams<{ kind: Kind; id: string }>()
  const { catalog, error } = useCatalog()
  const { user } = useAuth()
  if (!catalog) return error ? <ErrorBanner error={error} /> : <Loading />
  const plan = kind === 'loan' ? catalog.loan_products.find((p) => p.id === id) : catalog.deposit_products.find((p) => p.id === id)
  if (!plan) return <Empty title="Plan not available">This plan is no longer offered. <Link to="/plans">See current plans</Link></Empty>
  return kind === 'loan'
    ? <LoanPlanDetail plan={plan as PublicPlan<PublicLoanTerms>} signedIn={!!user} />
    : <SavingsPlanDetail plan={plan as PublicPlan<DepositTerms>} signedIn={!!user} />
}

function ApplyActions({ applyPath, signedIn }: { applyPath: string; signedIn: boolean }) {
  if (signedIn) return <Link to={applyPath} className="btn btn--primary btn--block">Apply for this plan</Link>
  const next = encodeURIComponent(applyPath)
  return (
    <div className="stack" style={{ gap: 8 }}>
      <Link to={`/register?next=${next}`} className="btn btn--primary btn--block">Register &amp; apply</Link>
      <Link to={`/login?next=${next}`} className="btn btn--block">Already registered? Sign in</Link>
    </div>
  )
}

function LoanPlanDetail({ plan, signedIn }: { plan: PublicPlan<PublicLoanTerms>; signedIn: boolean }) {
  const t = plan.terms
  const unit = LOAN_UNIT[t.repayment_frequency]
  const [amount, setAmount] = useState(rupees(t.min_amount))
  const [tenure, setTenure] = useState(String(t.min_tenure))
  const [preview, setPreview] = useState<{ annual_interest_rate: string; installment_amount: string; total_interest: string; total_payable: string; processing_fee: string; tax_on_processing_fee: string; net_disbursement: string } | null>(null)
  const [calcError, setCalcError] = useState<unknown>(null)

  useEffect(() => {
    setPreview(null)
    setCalcError(null)
    if (!amount || !tenure) return
    const timer = setTimeout(() => {
      api.post<typeof preview>(`/public/loan-products/${plan.id}/preview`, { principal: amount, tenure: Number(tenure) }).then(setPreview).catch(setCalcError)
    }, 350)
    return () => clearTimeout(timer)
  }, [plan.id, amount, tenure])

  const lateFee = t.late_fee_type === 'fixed' ? `${money(t.late_fee_value)} per missed installment` : `${percent(t.late_fee_value)} of the overdue amount`
  return (
    <>
      <PageHeader title={plan.name} back={{ to: '/plans', label: 'Plans' }} subtitle={plan.description ?? `${titleCase(t.repayment_frequency)} repayment loan`} />
      <section className="card">
        <h2 className="card__title">Plan details</h2>
        <Details rows={[
          ['Interest', `${rateSummary(t.annual_interest_rate, t.tenure_options)} (${titleCase(t.interest_method)})`],
          ['You repay', titleCase(t.repayment_frequency)],
          ['Loan amount', `${money(t.min_amount)} – ${money(t.max_amount)}`],
          ['Tenure', loanTenureSummary(t)],
          ['Processing fee', `${percent(t.processing_fee_percent)} + GST ${percent(t.tax_on_fees_percent)} (deducted at disbursement)`],
          ['Late fee', `${lateFee}, after ${t.grace_period_days} day(s) grace`],
          ['Pay early', t.prepayment_allowed ? (Number(t.prepayment_charge_percent) > 0 ? `Allowed, ${percent(t.prepayment_charge_percent)} charge` : 'Allowed, no charge') : 'Not allowed'],
        ]} />
      </section>
      <section className="card" style={{ marginTop: 14 }}>
        <h2 className="card__title">Calculate your installment</h2>
        <div className="grid grid--2 grid--tight">
          <Field label="Loan amount (₹)" error={fieldErrors(calcError).principal}><input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
          {t.tenure_options?.length ? null : (
            <Field label={`Tenure (${unit})`} error={fieldErrors(calcError).tenure}><input inputMode="numeric" value={tenure} onChange={(e) => setTenure(e.target.value.replace(/\D/g, ''))} /></Field>
          )}
        </div>
        {t.tenure_options?.length ? (
          <Field label="Tenure" error={fieldErrors(calcError).tenure}>
            <LoanTenurePicker terms={t} tenure={tenure} onChange={setTenure} />
          </Field>
        ) : null}
        {preview ? (
          <>
            <div className="highlight" style={{ marginTop: 10 }}>
              <div className="highlight__label">{titleCase(t.repayment_frequency)} installment at {percent(preview.annual_interest_rate)} a year</div>
              <div className="highlight__value">{money(preview.installment_amount)}</div>
            </div>
            <Details compact rows={[
              ['Total interest', money(preview.total_interest)],
              ['Total you repay', money(preview.total_payable)],
              ['Processing fee + GST', money(String(Number(preview.processing_fee) + Number(preview.tax_on_processing_fee)))],
              ['You receive', money(preview.net_disbursement)],
            ]} />
          </>
        ) : null}
      </section>
      <div style={{ marginTop: 16 }}>
        <ApplyActions signedIn={signedIn} applyPath={`/apply/loan?plan=${plan.id}&amount=${encodeURIComponent(amount)}&tenure=${encodeURIComponent(tenure)}`} />
      </div>
    </>
  )
}

function SavingsPlanDetail({ plan, signedIn }: { plan: PublicPlan<DepositTerms>; signedIn: boolean }) {
  const t = plan.terms
  const recurring = t.scheme === 'recurring'
  const [amount, setAmount] = useState(rupees(recurring ? t.min_installment : t.min_amount))
  const tenures = depositTenures(t)
  const [term, setTerm] = useState(tenureKey(tenures[0]))
  const chosen = tenures.find((o) => tenureKey(o) === term) ?? tenures[0]
  const [preview, setPreview] = useState<{ annual_interest_rate: string; installments: number; total_deposited: string; interest_earned: string; maturity_value: string } | null>(null)

  useEffect(() => {
    setPreview(null)
    if (!amount) return
    const timer = setTimeout(() => {
      api
        .post<typeof preview>(`/public/deposit-products/${plan.id}/preview`, { amount, tenure_value: chosen.value, tenure_unit: chosen.unit })
        .then(setPreview)
        .catch(() => setPreview(null))
    }, 350)
    return () => clearTimeout(timer)
  }, [plan.id, amount, chosen.value, chosen.unit])

  return (
    <>
      <PageHeader title={plan.name} back={{ to: '/plans', label: 'Plans' }} subtitle={plan.description ?? (DEPOSIT_TYPES.find((d) => d.key === depositType(t))?.hint ?? '')} />
      <section className="card">
        <h2 className="card__title">Plan details</h2>
        <Details rows={[
          ['Interest', t.interest_method === 'flat' ? `${rateSummary(t.annual_interest_rate, depositTenures(t), depositPer(t))}, paid at maturity` : `${rateSummary(t.annual_interest_rate, depositTenures(t))} (simple)`],
          ['Type', DEPOSIT_TYPES.find((d) => d.key === depositType(t))?.label ?? titleCase(t.scheme)],
          ['Tenure', listOr(tenures.map(tenureLabel))],
          [recurring ? 'Minimum installment' : 'Deposit amount', recurring ? money(t.min_installment) : `${money(t.min_amount)} – ${money(t.max_amount)}`],
          ['Withdraw early', t.premature_withdrawal_allowed ? `Allowed after ${t.lock_in_days} days, ${percent(t.premature_penalty_percent)} lower interest` : 'Not allowed before maturity'],
        ]} />
      </section>
      <section className="card" style={{ marginTop: 14 }}>
        <h2 className="card__title">See what you will get</h2>
        <Field label={recurring ? `${titleCase(t.contribution_frequency)} installment (₹)` : 'Deposit amount (₹)'}><input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
        {tenures.length > 1 ? (
          <Field label="Tenure">
            <TenurePicker options={tenures} value={tenureKey(chosen)} onChange={(o) => setTerm(tenureKey(o))} per={depositPer(t)} />
          </Field>
        ) : null}
        {preview ? (
          <>
            <div className="highlight" style={{ marginTop: 10 }}>
              <div className="highlight__label">Maturity value at {percent(preview.annual_interest_rate)} {depositPer(t)}</div>
              <div className="highlight__value">{money(preview.maturity_value)}</div>
            </div>
            <Details compact rows={[['Installments', String(preview.installments)], ['You deposit', money(preview.total_deposited)], ['Interest earned', money(preview.interest_earned)]]} />
            <p className="small muted">Assumes every installment is paid on time.</p>
          </>
        ) : null}
      </section>
      <div style={{ marginTop: 16 }}>
        <ApplyActions signedIn={signedIn} applyPath={`/apply/savings?plan=${plan.id}&amount=${encodeURIComponent(amount)}&term=${tenureKey(chosen)}`} />
      </div>
    </>
  )
}

// ---- Registration ----------------------------------------------------------------------------

export function Register() {
  const { user, register } = useAuth()
  const { catalog } = useCatalog()
  const navigate = useNavigate()
  const next = useNext()
  const [form, setForm] = useState({ full_name: '', phone: '', email: '', password: '', password2: '', address_line: '', city: '', state: '', pincode: '', branch_id: '', occupation: '' })
  const [consent, setConsent] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const errors = { ...fieldErrors(error) }
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  if (user) return <Navigate to={next} replace />

  async function submit(e: FormEvent) {
    e.preventDefault()
    if (form.password !== form.password2) {
      setError({ fields: { password2: 'Passwords do not match.' } })
      return
    }
    setSaving(true)
    setError(null)
    try {
      const { password2: _ignored, ...details } = form
      const payload = Object.fromEntries(Object.entries(details).filter(([, v]) => v.trim() !== ''))
      await register(payload)
      navigate(next, { replace: true })
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  const branches = catalog?.branches ?? []
  const passwordMismatch = (error as { fields?: Record<string, string> } | null)?.fields?.password2
  return (
    <>
      <PageHeader title="Create your account" subtitle="Takes 2 minutes. Our team verifies your details before any loan or savings account is opened." />
      <form className="card stack" onSubmit={submit} noValidate>
        <ErrorBanner error={passwordMismatch ? null : bannerError(error)} />
        <Field label="Full name (as on Aadhaar) *" error={errors.full_name}><input value={form.full_name} onChange={set('full_name')} autoComplete="name" /></Field>
        <Field label="Mobile number *" error={errors.phone} hint="You will sign in with this number."><input type="tel" inputMode="tel" value={form.phone} onChange={set('phone')} autoComplete="tel" /></Field>
        <Field label="Email (optional)" error={errors.email}><input type="email" autoCapitalize="none" value={form.email} onChange={set('email')} autoComplete="email" /></Field>
        <div className="grid grid--2 grid--tight">
          <Field label="Password *" error={errors.password} hint="At least 8 characters."><input type="password" value={form.password} onChange={set('password')} autoComplete="new-password" /></Field>
          <Field label="Password again *" error={passwordMismatch}><input type="password" value={form.password2} onChange={set('password2')} autoComplete="new-password" /></Field>
        </div>
        <Field label="House / street / village *" error={errors.address_line}><input value={form.address_line} onChange={set('address_line')} autoComplete="street-address" /></Field>
        <div className="grid grid--2 grid--tight">
          <Field label="City / town *" error={errors.city}><input value={form.city} onChange={set('city')} autoComplete="address-level2" /></Field>
          <Field label="State *" error={errors.state}><input value={form.state} onChange={set('state')} autoComplete="address-level1" /></Field>
        </div>
        <div className="grid grid--2 grid--tight">
          <Field label="PIN code *" error={errors.pincode}><input inputMode="numeric" maxLength={6} value={form.pincode} onChange={(e) => setForm((f) => ({ ...f, pincode: e.target.value.replace(/\D/g, '') }))} autoComplete="postal-code" /></Field>
          <Field label="Work / occupation" error={errors.occupation}><input value={form.occupation} onChange={set('occupation')} placeholder="e.g. Shopkeeper" /></Field>
        </div>
        {branches.length > 1 ? (
          <Field label="Nearest branch" error={errors.branch_id}>
            <select value={form.branch_id} onChange={set('branch_id')}>
              <option value="">Not sure — let the office decide</option>
              {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </Field>
        ) : null}
        <label className="checkbox consent">
          <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
          <span>I confirm these details are correct and agree that {catalog?.company_name || 'KBSAVE'} may verify them (KYC) and contact me.</span>
        </label>
        <button type="submit" className="btn btn--primary btn--block" disabled={saving || !consent}>{saving ? 'Creating account…' : 'Create account'}</button>
        <p className="small muted" style={{ textAlign: 'center' }}>Already registered? <Link to={`/login${next !== '/' ? `?next=${encodeURIComponent(next)}` : ''}`}>Sign in</Link></p>
      </form>
    </>
  )
}

// ---- Apply (signed in) -----------------------------------------------------------------------

export function Apply() {
  const { kind } = useParams<{ kind: Kind }>()
  const [params] = useSearchParams()
  const { user } = useAuth()
  const navigate = useNavigate()
  const { catalog, error: catalogError } = useCatalog()
  const { data: customer } = useFetch<{ status: string }>(user?.customer_id ? `/customers/${user.customer_id}` : null)
  const [productId, setProductId] = useState(params.get('plan') ?? '')
  const [amount, setAmount] = useState(params.get('amount') ?? '')
  const [tenure, setTenure] = useState(params.get('tenure') ?? '')
  const [term, setTerm] = useState(params.get('term') ?? '')
  const [purpose, setPurpose] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const errors = fieldErrors(error)
  const isLoan = kind === 'loan'

  const loanPlans = useMemo(() => catalog?.loan_products ?? [], [catalog])
  const savingsPlans = useMemo(() => catalog?.deposit_products ?? [], [catalog])
  const plans = useMemo(
    () => (isLoan
      ? loanPlans.map((p) => ({ id: p.id, type: p.terms.repayment_frequency, name: p.name, lines: loanLines(p.terms) }))
      : savingsPlans.map((p) => ({ id: p.id, type: depositType(p.terms), name: p.name, lines: savingsLines(p.terms) }))),
    [isLoan, loanPlans, savingsPlans],
  )
  // Loan and savings plans are numbered separately, so only look in the list for this kind.
  const loanPlan = isLoan ? loanPlans.find((p) => p.id === productId) : undefined
  const savingsPlan = isLoan ? undefined : savingsPlans.find((p) => p.id === productId)

  const choose = useCallback(
    (id: string) => {
      if (id === productId) return
      setProductId(id)
      const lp = isLoan ? loanPlans.find((p) => p.id === id) : undefined
      const sp = isLoan ? undefined : savingsPlans.find((p) => p.id === id)
      if (lp) {
        setTenure(String(lp.terms.min_tenure))
        setAmount((a) => a || rupees(lp.terms.min_amount))
      }
      if (sp) {
        setAmount(rupees(sp.terms.scheme === 'recurring' ? sp.terms.min_installment : sp.terms.min_amount))
        setTerm(tenureKey(depositTenures(sp.terms)[0]))
      }
    },
    [productId, isLoan, loanPlans, savingsPlans],
  )

  if (kind !== 'loan' && kind !== 'savings') return <Navigate to="/" replace />
  if (!catalog) return catalogError ? <ErrorBanner error={catalogError} /> : <Loading />
  if (!isLoan && !catalog.deposits_enabled) {
    return (
      <>
        <PageHeader title="Open a savings account" back={{ to: '/', label: 'Home' }} />
        <Notice tone="warning">Savings accounts are not available yet. Please check again later.</Notice>
      </>
    )
  }

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const created = await api.post<CustomerApplication>('/my/applications', {
        type: kind,
        product_id: Number(productId),
        amount,
        ...(isLoan ? { tenure: Number(tenure) } : savingsTenure ? { tenure: savingsTenure.value, tenure_unit: savingsTenure.unit } : {}),
        purpose: purpose || null,
      })
      navigate(`/applications?new=${encodeURIComponent(created.application_number)}`, { replace: true })
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  const unit = loanPlan ? LOAN_UNIT[loanPlan.terms.repayment_frequency] : ''
  const savingsTenures = savingsPlan ? depositTenures(savingsPlan.terms) : []
  const savingsTenure = savingsTenures.find((o) => tenureKey(o) === term) ?? savingsTenures[0]
  return (
    <>
      <PageHeader title={isLoan ? 'Apply for a loan' : 'Open a savings account'} back={{ to: '/', label: 'Home' }} subtitle="Our team reviews every application and confirms it in the app." />
      {customer?.status === 'pending' ? (
        <Notice tone="info">Your registration is being verified. You can apply now — we will process this application once your registration is approved.</Notice>
      ) : null}
      <form className="card stack" onSubmit={submit} noValidate style={{ marginTop: 12 }}>
        <ErrorBanner error={bannerError(error)} />
        <Field label={isLoan ? 'Repayment type *' : 'Account type *'} error={errors.product_id}>
          <PlanPicker types={isLoan ? LOAN_TYPES : DEPOSIT_TYPES} plans={plans} value={productId} onChange={choose} />
        </Field>
        {loanPlan ? (
          <div className="grid grid--2 grid--tight">
            <Field label="Loan amount (₹) *" error={errors.amount} hint={`${money(loanPlan.terms.min_amount)} – ${money(loanPlan.terms.max_amount)}`}>
              <input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
            </Field>
            {loanPlan.terms.tenure_options?.length ? null : (
              <Field label={`Tenure (${unit}) *`} error={errors.tenure} hint={`${loanPlan.terms.min_tenure} – ${loanPlan.terms.max_tenure} ${unit}`}>
                <input inputMode="numeric" value={tenure} onChange={(e) => setTenure(e.target.value.replace(/\D/g, ''))} />
              </Field>
            )}
          </div>
        ) : null}
        {loanPlan?.terms.tenure_options?.length ? (
          <Field label="Tenure *" error={errors.tenure}>
            <LoanTenurePicker terms={loanPlan.terms} tenure={tenure} onChange={setTenure} />
          </Field>
        ) : null}
        {savingsPlan ? (
          <Field
            label={savingsPlan.terms.scheme === 'recurring' ? `${titleCase(savingsPlan.terms.contribution_frequency)} installment (₹) *` : 'Deposit amount (₹) *'}
            error={errors.amount}
            hint={savingsPlan.terms.scheme === 'recurring' ? `Minimum ${money(savingsPlan.terms.min_installment)}` : `${money(savingsPlan.terms.min_amount)} – ${money(savingsPlan.terms.max_amount)}`}
          >
            <input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
          </Field>
        ) : null}
        {savingsPlan && savingsTenure ? (
          <Field label="Tenure *" error={errors.tenure}>
            <TenurePicker options={depositTenures(savingsPlan.terms)} value={tenureKey(savingsTenure)} onChange={(o) => setTerm(tenureKey(o))} per={depositPer(savingsPlan.terms)} />
          </Field>
        ) : null}
        <Field label={isLoan ? 'What is the loan for?' : 'Note (optional)'} error={errors.purpose}>
          <input value={purpose} onChange={(e) => setPurpose(e.target.value)} placeholder={isLoan ? 'e.g. Shop stock, school fees' : ''} maxLength={255} />
        </Field>
        <button type="submit" className="btn btn--primary btn--block" disabled={saving || !productId || !amount || (isLoan && !tenure)}>
          {saving ? 'Sending…' : 'Send application'}
        </button>
        <p className="small muted">Sending an application is free. Nothing is charged until a loan is approved and paid out.</p>
      </form>
    </>
  )
}

// ---- My applications -------------------------------------------------------------------------

export function MyApplications() {
  const [params] = useSearchParams()
  const created = params.get('new')
  const { data, error, reload } = useFetch<CustomerApplication[]>('/my/applications')
  return (
    <>
      <PageHeader title="My applications" back={{ to: '/', label: 'Home' }} />
      {created ? <Notice>Application {created} sent. We will let you know here when it is reviewed.</Notice> : null}
      <ErrorBanner error={error} />
      {!data ? (error ? null : <Loading />) : data.length === 0 ? (
        <div className="card"><Empty title="No applications yet">Choose a plan and apply — it takes a minute.</Empty></div>
      ) : (
        <div className="stack" style={{ marginTop: 12 }}>
          {data.map((a) => <ApplicationCard key={a.id} app={a} onChanged={reload} />)}
        </div>
      )}
      <div className="row row--wrap" style={{ gap: 8, marginTop: 16 }}>
        <Link to="/apply/loan" className="btn">Apply for a loan</Link>
        <Link to="/plans" className="btn btn--ghost">See plans</Link>
      </div>
    </>
  )
}

const NEXT_STEP: Record<CustomerApplication['status'], string> = {
  submitted: 'Waiting for review by our team.',
  accepted: '',
  rejected: 'Not approved.',
  cancelled: 'You cancelled this application.',
}

export function ApplicationCard({ app, onChanged }: { app: CustomerApplication; onChanged?: () => void }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<unknown>(null)
  async function cancel() {
    setBusy(true)
    setError(null)
    try {
      await api.post(`/my/applications/${app.id}/cancel`)
      onChanged?.()
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }
  let step: ReactNode = NEXT_STEP[app.status]
  if (app.status === 'accepted' && app.loan_id) {
    step = <>Accepted — loan <Link to={`/loans/${app.loan_id}`}>{app.loan_number}</Link> is {titleCase(app.loan_status ?? 'pending_approval').toLowerCase()}.</>
  } else if (app.status === 'accepted' && app.deposit_account_id) {
    step = <>Accepted — savings account <Link to={`/deposits/${app.deposit_account_id}`}>{app.deposit_number}</Link> is open.</>
  }
  return (
    <div className="card app-card">
      <div className="row row--between">
        <strong>{app.type === 'loan' ? 'Loan' : 'Savings'} · {app.product_name ?? 'Plan'}</strong>
        <StatusBadge status={app.status} />
      </div>
      <div className="small muted mono">{app.application_number} · {date(app.created_at)}</div>
      <div className="app-card__amount">
        {money(app.amount)}
        {app.type === 'loan' && app.tenure ? <span className="small muted"> for {app.tenure} installments</span> : null}
        {app.type === 'savings' && app.tenure && app.tenure_unit ? <span className="small muted"> for {tenureLabel({ value: Number(app.tenure), unit: app.tenure_unit })}</span> : null}
      </div>
      <div className="small">{step}{app.decision_note && app.status === 'rejected' ? ` ${app.decision_note}` : ''}</div>
      <ErrorBanner error={error} />
      {app.status === 'submitted' && onChanged ? (
        <button type="button" className="btn btn--ghost btn--small" onClick={cancel} disabled={busy} style={{ alignSelf: 'flex-start' }}>
          Cancel application
        </button>
      ) : null}
    </div>
  )
}

/** Loan tenure chips; `tenure` is the installment count the API expects. */
function LoanTenurePicker({ terms, tenure, onChange }: { terms: PublicLoanTerms; tenure: string; onChange: (installments: string) => void }) {
  const options = terms.tenure_options ?? []
  const unit = LOAN_UNIT[terms.repayment_frequency]
  const current = options.find((o) => String(o.installments) === tenure)
  return (
    <TenurePicker
      options={options}
      value={current ? tenureKey(current) : ''}
      onChange={(o) => onChange(String(o.installments))}
      note={(o) => (o.unit === unit ? null : `${o.installments} ${unit}`)}
    />
  )
}

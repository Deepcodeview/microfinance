import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { Details, ErrorBanner, Field, Loading, Notice, PageHeader, StatusBadge, bannerError, fieldErrors } from '../components/ui'
import { date, dateTime, money, percent, titleCase } from '../format'
import { useFetch } from '../hooks'
import { TenureOptionsEditor, TenurePicker } from '../components/Tenure'
import { depositPer, depositTenures, listOr, rateSummary, tenureKey, tenureLabel } from '../plans'
import type { DepositTerms } from '../types'
import { DeletePlanButton, ProductTabs } from './LoanProducts'

interface DepositProduct {
  id: string
  code: string
  name: string
  description: string | null
  status: 'active' | 'inactive'
  version: string
  version_id: string
  effective_from: string
  accounts_count: string
  terms: DepositTerms
  versions?: { id: string; version: string; change_reason: string; created_by_name: string; created_at: string; terms: DepositTerms }[]
}

type TermsForm = { [K in keyof DepositTerms]: DepositTerms[K] extends number ? string : DepositTerms[K] }

const trim = (v: string) => (v.includes('.') ? v.replace(/\.?0+$/, '') : v)
function toForm(t: DepositTerms): TermsForm {
  // Versions saved before tenure choices had one tenure_months; edit it as a single choice.
  const f = { ...t, tenure_options: depositTenures(t) } as unknown as Record<string, unknown>
  delete f.tenure_months
  for (const [k, v] of Object.entries(t)) {
    if (typeof v === 'number') f[k] = String(v)
    else if (typeof v === 'string' && /^\d+\.\d+$/.test(v)) f[k] = trim(v)
  }
  return f as TermsForm
}
function toPayload(f: TermsForm) {
  return { ...f, lock_in_days: /^\d+$/.test(f.lock_in_days) ? Number(f.lock_in_days) : f.lock_in_days }
}

export function DepositProducts() {
  const { can } = useAuth()
  const { data, error } = useFetch<DepositProduct[]>('/deposit-products')
  return (
    <>
      <PageHeader
        title="Loan &amp; savings plans"
        subtitle="Deposit terms are versioned like loan terms. Accounts keep the version they were opened on."
        actions={can('deposit_products.manage') ? <Link to="/deposit-products/new" className="btn btn--primary">New savings plan</Link> : null}
      />
      <ProductTabs active="deposit" />
      <ErrorBanner error={error} />
      {!data && !error ? <Loading /> : null}
      {data ? (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Code</th><th>Name</th><th>Type</th><th className="num">Rate</th><th className="num">Tenure</th><th>Version</th><th className="num">Accounts</th><th>Status</th></tr></thead>
            <tbody>
              {data.map((p) => (
                <tr key={p.id}>
                  <td><Link to={`/deposit-products/${p.id}`} className="mono">{p.code}</Link></td>
                  <td>{p.name}</td>
                  <td>{p.terms.scheme === 'fixed' ? 'Fixed deposit' : `${titleCase(p.terms.contribution_frequency)} savings`}</td>
                  <td className="num">{rateSummary(p.terms.annual_interest_rate, depositTenures(p.terms), p.terms.interest_method === 'flat' ? 'flat' : 'p.a.').replace(', by tenure', '')}</td>
                  <td className="num">{listOr(depositTenures(p.terms).map(tenureLabel))}</td>
                  <td>v{p.version} <span className="small muted">since {date(p.effective_from)}</span></td>
                  <td className="num">{p.accounts_count}</td>
                  <td><StatusBadge status={p.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </>
  )
}

export function DepositProductEditor() {
  const { id } = useParams()
  const creating = id === undefined
  const navigate = useNavigate()
  const { can } = useAuth()
  const readOnly = !can('deposit_products.manage')
  const [product, setProduct] = useState<DepositProduct | null>(null)
  const [terms, setTerms] = useState<TermsForm | null>(null)
  const [meta, setMeta] = useState({ code: '', name: '', description: '' })
  const [reason, setReason] = useState('')
  const [preview, setPreview] = useState<{ total_deposited: string; interest_earned: string; maturity_value: string } | null>(null)
  const [illustrate, setIllustrate] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [notice, setNotice] = useState('')
  const errors = fieldErrors(error)
  const te = (k: keyof DepositTerms) => errors[`terms.${k}`]

  useEffect(() => {
    if (creating) {
      api.get<{ terms: DepositTerms }>('/deposit-products/defaults').then((d) => setTerms(toForm(d.terms))).catch(setError)
    } else {
      api.get<DepositProduct>(`/deposit-products/${id}`).then((p) => {
        setProduct(p)
        setTerms(toForm(p.terms))
        setMeta({ code: p.code, name: p.name, description: p.description ?? '' })
      }).catch(setError)
    }
  }, [creating, id])

  // Illustration for one of the plan's tenures (the first until another is picked).
  const sampleTenure = terms?.tenure_options.find((o) => tenureKey(o) === illustrate) ?? terms?.tenure_options[0]
  useEffect(() => {
    if (!terms) return
    const amount = terms.scheme === 'fixed' ? terms.min_amount : terms.min_installment
    const t = setTimeout(() => {
      api
        .post<typeof preview>('/deposit-products/preview', { terms: toPayload(terms), amount, ...(sampleTenure ? { tenure_value: sampleTenure.value, tenure_unit: sampleTenure.unit } : {}) })
        .then(setPreview)
        .catch(() => setPreview(null))
    }, 400)
    return () => clearTimeout(t)
  }, [terms, sampleTenure])

  if (!terms) return error ? <ErrorBanner error={error} /> : <Loading />
  const set = <K extends keyof TermsForm>(k: K, v: TermsForm[K]) => setTerms((t) => (t ? { ...t, [k]: v } : t))

  async function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setNotice('')
    try {
      if (creating) {
        const p = await api.post<DepositProduct>('/deposit-products', { code: meta.code.trim().toUpperCase(), name: meta.name, description: meta.description || null, terms: toPayload(terms!) })
        navigate(`/deposit-products/${p.id}`, { replace: true })
      } else {
        const p = await api.post<DepositProduct>(`/deposit-products/${id}/versions`, { terms: toPayload(terms!), change_reason: reason })
        setProduct(p)
        setTerms(toForm(p.terms))
        setReason('')
        setNotice(`Saved as version ${p.version}. Existing accounts keep their original terms.`)
      }
    } catch (err) {
      setError(err)
    }
  }

  async function rename(name: string) {
    if (!product) return
    try {
      setProduct(await api.patch<DepositProduct>(`/deposit-products/${product.id}`, { name }))
      setNotice('Plan name saved.')
    } catch (err) {
      setError(err)
    }
  }

  async function toggleStatus() {
    if (!product) return
    try {
      const p = await api.patch<DepositProduct>(`/deposit-products/${product.id}`, { status: product.status === 'active' ? 'inactive' : 'active' })
      setProduct(p)
    } catch (err) {
      setError(err)
    }
  }

  const sample = terms.scheme === 'fixed' ? terms.min_amount : terms.min_installment

  return (
    <>
      <PageHeader
        back={{ to: '/deposit-products', label: 'Savings plans' }}
        title={creating ? 'New savings plan' : product?.name ?? ''}
        subtitle={product ? <><span className="mono">{product.code}</span> · v{product.version} <StatusBadge status={product.status} /></> : 'Defaults follow common bank practice. Use only rates and terms you are authorised to offer.'}
        actions={product && !readOnly ? <><button type="button" className="btn" onClick={toggleStatus}>{product.status === 'active' ? 'Deactivate' : 'Activate'}</button><DeletePlanButton kind="deposit" product={product} /></> : null}
      />
      {notice ? <Notice>{notice}</Notice> : null}
      <div className="grid grid--2-1">
        <form className="card" onSubmit={submit} noValidate>
          <ErrorBanner error={bannerError(error)} />
          <fieldset className="fieldset" disabled={readOnly}>
            <h2 className="form-section">Product</h2>
            <div className="grid grid--2 grid--tight">
              <Field label="Code *" error={errors.code}><input value={meta.code} disabled={!creating} onChange={(e) => setMeta({ ...meta, code: e.target.value })} /></Field>
              <Field label="Name *" error={errors.name}><input value={meta.name} onChange={(e) => setMeta({ ...meta, name: e.target.value })} onBlur={() => product && meta.name.trim() && meta.name !== product.name && rename(meta.name)} /></Field>
            </div>
            <h2 className="form-section">Scheme</h2>
            <div className="grid grid--3 grid--tight">
              <Field label="Type" error={te('scheme')}>
                <select value={terms.scheme} onChange={(e) => set('scheme', e.target.value as DepositTerms['scheme'])}>
                  <option value="recurring">Recurring (daily / weekly / monthly)</option>
                  <option value="fixed">Fixed (lump sum)</option>
                </select>
              </Field>
              <Field label="Interest" error={te('interest_method')} hint={terms.interest_method === 'flat' ? 'e.g. ₹50 a day for 6 months at 8%: ₹9,000 + ₹720 = ₹9,720' : 'Simple interest on each day\'s balance'}>
                <select value={terms.interest_method ?? 'daily'} onChange={(e) => set('interest_method', e.target.value as DepositTerms['interest_method'])}>
                  <option value="daily">% a year, on daily balance</option>
                  <option value="flat">Flat % on total deposited</option>
                </select>
              </Field>
              <Field label={terms.interest_method === 'flat' ? 'Interest rate (% on total, whole tenure)' : 'Interest rate (% p.a.)'} error={te('annual_interest_rate')}><input inputMode="decimal" value={terms.annual_interest_rate} onChange={(e) => set('annual_interest_rate', e.target.value)} /></Field>
              {terms.scheme === 'recurring' ? (
                <>
                  <Field label="Contribution frequency" error={te('contribution_frequency')}>
                    <select value={terms.contribution_frequency} onChange={(e) => set('contribution_frequency', e.target.value as DepositTerms['contribution_frequency'])}>
                      <option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option>
                    </select>
                  </Field>
                  <Field label="Minimum installment (₹)" error={te('min_installment')}><input inputMode="decimal" value={terms.min_installment} onChange={(e) => set('min_installment', e.target.value)} /></Field>
                </>
              ) : (
                <>
                  <Field label="Minimum deposit (₹)" error={te('min_amount')}><input inputMode="decimal" value={terms.min_amount} onChange={(e) => set('min_amount', e.target.value)} /></Field>
                  <Field label="Maximum deposit (₹)" error={te('max_amount')}><input inputMode="decimal" value={terms.max_amount} onChange={(e) => set('max_amount', e.target.value)} /></Field>
                </>
              )}
            </div>
            <h2 className="form-section">Tenures offered</h2>
            <p className="small muted" style={{ marginTop: -6 }}>The customer or staff picks one when the account is opened; it sets the maturity date. Add as many as you like, e.g. 45 days, 3 months, 6 months, 1 year. Give a tenure its own rate (e.g. 3 months 5%, 1 year 7%) or leave it empty for the plan's rate.</p>
            <Field label="Tenures *" error={te('tenure_options')}>
              <TenureOptionsEditor value={terms.tenure_options} onChange={(options) => set('tenure_options', options)} withRates rateUnit={terms.interest_method === 'flat' ? '% on total' : '% a year'} />
            </Field>
            <h2 className="form-section">Premature closure</h2>
            <div className="grid grid--3 grid--tight">
              <label className="checkbox"><input type="checkbox" checked={terms.premature_withdrawal_allowed} onChange={(e) => set('premature_withdrawal_allowed', e.target.checked)} />Allow premature closure</label>
              <Field label="Lock-in (days)" error={te('lock_in_days')}><input inputMode="numeric" value={terms.lock_in_days} onChange={(e) => set('lock_in_days', e.target.value)} /></Field>
              <Field label="Rate penalty (% points)" error={te('premature_penalty_percent')} hint="Interest is paid at the rate minus this."><input inputMode="decimal" value={terms.premature_penalty_percent} onChange={(e) => set('premature_penalty_percent', e.target.value)} /></Field>
            </div>
          </fieldset>
          {!readOnly ? (
            <>
              {!creating ? (
                <div style={{ marginTop: 18 }}>
                  <Field label="Reason for change *" error={errors.change_reason}><input value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
                </div>
              ) : null}
              <div className="form__actions">
                <button type="submit" className="btn btn--primary">{creating ? 'Create product' : 'Save as new version'}</button>
              </div>
            </>
          ) : null}
        </form>
        <div className="stack">
          <section className="card">
            <h2 className="card__title">Illustration</h2>
            {terms.tenure_options.length > 1 ? (
              <div style={{ margin: '8px 0' }}>
                <TenurePicker options={terms.tenure_options} value={sampleTenure ? tenureKey(sampleTenure) : ''} onChange={(o) => setIllustrate(tenureKey(o))} per={depositPer(terms)} />
              </div>
            ) : null}
            <p className="small muted" style={{ marginTop: 4 }}>
              {terms.scheme === 'fixed' ? `Deposit of ${money(sample)}` : `${money(sample)} ${terms.contribution_frequency}`}{sampleTenure ? ` for ${tenureLabel(sampleTenure)}` : ''}
            </p>
            {preview ? (
              <>
                <div className="highlight"><div className="highlight__label">Maturity value</div><div className="highlight__value">{money(preview.maturity_value)}</div></div>
                <Details compact rows={[['Deposited', money(preview.total_deposited)], ['Interest', money(preview.interest_earned)]]} />
              </>
            ) : <p className="muted">Fix the highlighted terms to see an illustration.</p>}
          </section>
          {product?.versions ? (
            <section className="card">
              <h2 className="card__title">Version history</h2>
              <ul className="history">
                {product.versions.map((v) => (
                  <li key={v.id}>
                    <strong>v{v.version}</strong> {v.id === product.version_id ? <span className="badge badge--success">current</span> : null}
                    <div>{v.change_reason}</div>
                    <div className="small muted">{dateTime(v.created_at)} · {v.created_by_name} · {percent(v.terms.annual_interest_rate)}</div>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}
        </div>
      </div>
    </>
  )
}

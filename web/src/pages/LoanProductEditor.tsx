import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ApiError, api } from '../api'
import { useAuth } from '../auth'
import { ErrorBanner, Field, Loading, PageHeader, StatusBadge, fieldErrors } from '../components/ui'
import { dateTime, money, titleCase } from '../format'
import { TenureOptionsEditor, TenurePicker } from '../components/Tenure'
import { loanInstallments, loanUnits, tenureKey } from '../plans'
import type { AllocationComponent, LoanPreview, LoanProduct, LoanTerms, TenureOption } from '../types'
import { DeletePlanButton } from './LoanProducts'

/** Form copy of LoanTerms: every input-backed value is a string while editing. */
type TermsForm = {
  [K in keyof LoanTerms]: LoanTerms[K] extends number ? string : LoanTerms[K]
}

const INT_FIELDS = ['min_tenure', 'max_tenure', 'grace_period_days'] as const

const FREQUENCY_UNIT: Record<LoanTerms['repayment_frequency'], string> = {
  daily: 'days',
  weekly: 'weeks',
  fortnightly: 'fortnights',
  monthly: 'months',
  quarterly: 'quarters',
  yearly: 'years',
}

const ALLOCATION_LABEL: Record<AllocationComponent, string> = {
  late_fee: 'Late fees',
  fees: 'Other fees',
  interest: 'Interest',
  principal: 'Principal',
}

/** "18.0000" -> "18" for editing; the API normalises scale again on save. */
function trimDecimal(value: string): string {
  return value.includes('.') ? value.replace(/\.?0+$/, '') : value
}

function toForm(terms: LoanTerms): TermsForm {
  const form = { ...terms } as unknown as Record<string, unknown>
  for (const [key, value] of Object.entries(terms)) {
    if (typeof value === 'number') form[key] = String(value)
    else if (typeof value === 'string' && /^\d+\.\d+$/.test(value)) form[key] = trimDecimal(value)
  }
  return form as TermsForm
}

function toPayload(form: TermsForm): Record<string, unknown> {
  const payload: Record<string, unknown> = { ...form }
  for (const key of INT_FIELDS) {
    payload[key] = /^\d+$/.test(form[key]) ? Number(form[key]) : form[key]
  }
  return payload
}

export function LoanProductEditor() {
  const { id } = useParams()
  const creating = id === undefined
  const navigate = useNavigate()
  const { can } = useAuth()
  const canManage = can('loan_products.manage')

  const [product, setProduct] = useState<LoanProduct | null>(null)
  const [terms, setTerms] = useState<TermsForm | null>(null)
  const [meta, setMeta] = useState({ code: '', name: '', description: '' })
  const [reason, setReason] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (creating) {
      api.get<{ terms: LoanTerms }>('/loan-products/defaults').then((d) => setTerms(toForm(d.terms))).catch(setError)
    } else {
      api
        .get<LoanProduct>(`/loan-products/${id}`)
        .then((p) => {
          setProduct(p)
          setTerms(toForm(p.terms))
          setMeta({ code: p.code, name: p.name, description: p.description ?? '' })
        })
        .catch(setError)
    }
  }, [creating, id])

  const errors = fieldErrors(error)
  const termError = (key: keyof LoanTerms) => errors[`terms.${key}`]
  const readOnly = !canManage

  const dirty = useMemo(() => {
    if (!product || !terms) return false
    return JSON.stringify(toForm(product.terms)) !== JSON.stringify(terms)
  }, [product, terms])

  function setTerm<K extends keyof TermsForm>(key: K, value: TermsForm[K]) {
    setTerms((t) => (t ? { ...t, [key]: value } : t))
    setNotice('')
  }

  function moveAllocation(index: number, delta: -1 | 1) {
    if (!terms) return
    const order = [...terms.allocation_order]
    const target = index + delta
    if (target < 0 || target >= order.length) return
    ;[order[index], order[target]] = [order[target], order[index]]
    setTerm('allocation_order', order)
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!terms) return
    setSaving(true)
    setError(null)
    setNotice('')
    try {
      if (creating) {
        const created = await api.post<LoanProduct>('/loan-products', {
          code: meta.code.trim().toUpperCase(),
          name: meta.name,
          description: meta.description || null,
          terms: toPayload(terms),
        })
        navigate(`/loan-products/${created.id}`, { replace: true })
      } else {
        const updated = await api.post<LoanProduct>(`/loan-products/${id}/versions`, {
          terms: toPayload(terms),
          change_reason: reason,
        })
        setProduct(updated)
        setTerms(toForm(updated.terms))
        setReason('')
        setNotice(`Saved as version ${updated.version}. Existing loans keep their original terms.`)
      }
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  async function saveDetails(changes: Partial<{ name: string; description: string | null; status: string }>) {
    setError(null)
    try {
      const updated = await api.patch<LoanProduct>(`/loan-products/${id}`, changes)
      setProduct(updated)
      setMeta({ code: updated.code, name: updated.name, description: updated.description ?? '' })
      setNotice('Product details saved.')
    } catch (err) {
      setError(err)
    }
  }

  if (!terms) return error ? <ErrorBanner error={error} /> : <Loading />

  const unit = FREQUENCY_UNIT[terms.repayment_frequency]
  const choices = terms.tenure_options ?? []
  const describeChoice = (o: TenureOption) => {
    const n = loanInstallments(terms.repayment_frequency, o)
    if (n === null) return null
    return o.unit === unit ? '' : `= ${n} ${n === 1 ? unit.replace(/s$/, '') : unit}`
  }
  // With choices, the min / max range simply follows the shortest and longest choice.
  function setChoices(options: TenureOption[]) {
    setTerm('tenure_options', options)
    const counts = options.map((o) => loanInstallments(terms!.repayment_frequency, o)).filter((n): n is number => n !== null)
    if (counts.length > 0) {
      setTerm('min_tenure', String(Math.min(...counts)))
      setTerm('max_tenure', String(Math.max(...counts)))
    }
  }

  return (
    <>
      <PageHeader
        back={{ to: '/loan-products', label: 'Loan plans' }}
        title={creating ? 'New loan plan' : (product?.name ?? '')}
        subtitle={
          product ? (
            <>
              <span className="mono">{product.code}</span> · v{product.version} · <StatusBadge status={product.status} />
            </>
          ) : (
            'Defaults follow standard bank practice. Adjust them to your approved policy.'
          )
        }
        actions={
          product && canManage ? (
            <>
              <button
                type="button"
                className="btn"
                onClick={() => saveDetails({ status: product.status === 'active' ? 'inactive' : 'active' })}
              >
                {product.status === 'active' ? 'Deactivate' : 'Activate'}
              </button>
              <DeletePlanButton kind="loan" product={product} />
            </>
          ) : null
        }
      />

      <div className="grid grid--2-1">
        <form className="card" onSubmit={onSubmit} noValidate>
          <ErrorBanner error={error instanceof ApiError && error.status === 422 && Object.keys(errors).length > 0 ? new Error('Please correct the highlighted fields.') : error} />
          {notice ? <div className="banner banner--success">{notice}</div> : null}

          <h2 className="form-section">Product</h2>
          <div className="grid grid--2">
            <Field label="Code *" error={errors.code} hint="Short unique code, e.g. PL-MONTHLY">
              <input value={meta.code} disabled={!creating} onChange={(e) => setMeta({ ...meta, code: e.target.value })} />
            </Field>
            <Field label="Name *" error={errors.name}>
              <input
                value={meta.name}
                disabled={readOnly}
                onChange={(e) => setMeta({ ...meta, name: e.target.value })}
                onBlur={() => product && meta.name !== product.name && saveDetails({ name: meta.name })}
              />
            </Field>
          </div>
          <Field label="Description" error={errors.description}>
            <input
              value={meta.description}
              disabled={readOnly}
              onChange={(e) => setMeta({ ...meta, description: e.target.value })}
              onBlur={() =>
                product && meta.description !== (product.description ?? '') && saveDetails({ description: meta.description || null })
              }
            />
          </Field>

          <fieldset disabled={readOnly} className="fieldset">
            <h2 className="form-section">Interest</h2>
            <div className="grid grid--3">
              <Field label="Interest method" error={termError('interest_method')}>
                <select
                  value={terms.interest_method}
                  onChange={(e) => setTerm('interest_method', e.target.value as LoanTerms['interest_method'])}
                >
                  <option value="reducing_balance">Reducing balance (EMI)</option>
                  <option value="flat">Flat rate</option>
                </select>
              </Field>
              <Field label="Interest rate (% per year)" error={termError('annual_interest_rate')}>
                <input inputMode="decimal" value={terms.annual_interest_rate} onChange={(e) => setTerm('annual_interest_rate', e.target.value)} />
              </Field>
              <Field label="Repayment frequency" error={termError('repayment_frequency')}>
                <select
                  value={terms.repayment_frequency}
                  onChange={(e) => setTerm('repayment_frequency', e.target.value as LoanTerms['repayment_frequency'])}
                >
                  <option value="daily">Daily</option>
                  <option value="weekly">Weekly</option>
                  <option value="fortnightly">Fortnightly</option>
                  <option value="monthly">Monthly</option>
                  <option value="quarterly">Quarterly</option>
                  <option value="yearly">Yearly</option>
                </select>
              </Field>
            </div>

            <h2 className="form-section">Limits</h2>
            <div className="grid grid--4">
              <Field label="Minimum amount (₹)" error={termError('min_amount')}>
                <input inputMode="decimal" value={terms.min_amount} onChange={(e) => setTerm('min_amount', e.target.value)} />
              </Field>
              <Field label="Maximum amount (₹)" error={termError('max_amount')}>
                <input inputMode="decimal" value={terms.max_amount} onChange={(e) => setTerm('max_amount', e.target.value)} />
              </Field>
              {choices.length === 0 ? (
                <>
                  <Field label={`Min tenure (${unit})`} error={termError('min_tenure')}>
                    <input inputMode="numeric" value={terms.min_tenure} onChange={(e) => setTerm('min_tenure', e.target.value)} />
                  </Field>
                  <Field label={`Max tenure (${unit})`} error={termError('max_tenure')}>
                    <input inputMode="numeric" value={terms.max_tenure} onChange={(e) => setTerm('max_tenure', e.target.value)} />
                  </Field>
                </>
              ) : null}
            </div>
            <Field
              label="Tenure choices"
              error={termError('tenure_options')}
              hint={choices.length === 0
                ? `Optional. Without choices any tenure between the min and max ${unit} is allowed. Add choices like 45 days, 3 months or 1 year to offer only those.`
                : 'Only these tenures can be chosen; a tenure may have its own rate (empty = the plan rate above). Remove all choices to allow any tenure between a min and max again.'}
            >
              <TenureOptionsEditor value={choices} onChange={setChoices} units={loanUnits(terms.repayment_frequency)} describe={describeChoice} withRates />
            </Field>

            <h2 className="form-section">Fees &amp; charges</h2>
            <div className="grid grid--3">
              <Field label="Processing fee (% of loan)" error={termError('processing_fee_percent')}>
                <input inputMode="decimal" value={terms.processing_fee_percent} onChange={(e) => setTerm('processing_fee_percent', e.target.value)} />
              </Field>
              <Field label="GST on fees (%)" error={termError('tax_on_fees_percent')}>
                <input inputMode="decimal" value={terms.tax_on_fees_percent} onChange={(e) => setTerm('tax_on_fees_percent', e.target.value)} />
              </Field>
              <Field label="Grace period (days)" error={termError('grace_period_days')} hint="Days after due date before a late fee applies.">
                <input inputMode="numeric" value={terms.grace_period_days} onChange={(e) => setTerm('grace_period_days', e.target.value)} />
              </Field>
              <Field label="Late fee type" error={termError('late_fee_type')}>
                <select value={terms.late_fee_type} onChange={(e) => setTerm('late_fee_type', e.target.value as LoanTerms['late_fee_type'])}>
                  <option value="fixed">Fixed amount per missed installment</option>
                  <option value="percent_of_overdue">% of overdue amount</option>
                </select>
              </Field>
              <Field label={terms.late_fee_type === 'fixed' ? 'Late fee (₹)' : 'Late fee (%)'} error={termError('late_fee_value')}>
                <input inputMode="decimal" value={terms.late_fee_value} onChange={(e) => setTerm('late_fee_value', e.target.value)} />
              </Field>
              <Field label="Installment rounding" error={termError('installment_rounding')}>
                <select
                  value={terms.installment_rounding}
                  onChange={(e) => setTerm('installment_rounding', e.target.value as LoanTerms['installment_rounding'])}
                >
                  <option value="rupee">Nearest rupee</option>
                  <option value="paise">Nearest paisa</option>
                </select>
              </Field>
              <Field label="Due dates on holidays" error={termError('holiday_adjustment')} hint="Weekly off-days and holidays are set in Settings.">
                <select
                  value={terms.holiday_adjustment ?? 'none'}
                  onChange={(e) => setTerm('holiday_adjustment', e.target.value as LoanTerms['holiday_adjustment'])}
                >
                  <option value="next_business_day">Move to next business day</option>
                  <option value="none">Keep calendar date</option>
                </select>
              </Field>
            </div>

            <h2 className="form-section">Prepayment</h2>
            <div className="grid grid--3">
              <label className="checkbox">
                <input type="checkbox" checked={terms.prepayment_allowed} onChange={(e) => setTerm('prepayment_allowed', e.target.checked)} />
                Allow prepayment / foreclosure
              </label>
              {terms.prepayment_allowed ? (
                <Field label="Prepayment charge (%)" error={termError('prepayment_charge_percent')} hint="RBI: nil for floating-rate loans to individuals.">
                  <input
                    inputMode="decimal"
                    value={terms.prepayment_charge_percent}
                    onChange={(e) => setTerm('prepayment_charge_percent', e.target.value)}
                  />
                </Field>
              ) : null}
            </div>

            <h2 className="form-section">Payment allocation order</h2>
            <p className="muted small">Each payment is applied in this order. Standard practice: charges, then interest, then principal.</p>
            {termError('allocation_order') ? <div className="field__error">{termError('allocation_order')}</div> : null}
            <ol className="allocation">
              {terms.allocation_order.map((component, index) => (
                <li key={component}>
                  <span>{ALLOCATION_LABEL[component]}</span>
                  {!readOnly ? (
                    <span className="allocation__controls">
                      <button type="button" className="btn btn--small btn--ghost" disabled={index === 0} onClick={() => moveAllocation(index, -1)} aria-label="Move up">
                        ↑
                      </button>
                      <button
                        type="button"
                        className="btn btn--small btn--ghost"
                        disabled={index === terms.allocation_order.length - 1}
                        onClick={() => moveAllocation(index, 1)}
                        aria-label="Move down"
                      >
                        ↓
                      </button>
                    </span>
                  ) : null}
                </li>
              ))}
            </ol>
          </fieldset>

          {canManage ? (
            <>
              {!creating ? (
                <Field label="Reason for change *" error={errors.change_reason} hint="Recorded in the version history and audit log.">
                  <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Board approved rate revision" />
                </Field>
              ) : null}
              <div className="form__actions">
                <Link to="/loan-products" className="btn btn--ghost">
                  Back
                </Link>
                <button type="submit" className="btn btn--primary" disabled={saving || (!creating && !dirty)}>
                  {saving ? 'Saving…' : creating ? 'Create product' : 'Save as new version'}
                </button>
              </div>
            </>
          ) : null}
        </form>

        <div className="stack">
          <PreviewPanel terms={terms} />
          {product?.versions ? <VersionHistory product={product} /> : null}
        </div>
      </div>
    </>
  )
}

function PreviewPanel({ terms }: { terms: TermsForm }) {
  const [principal, setPrincipal] = useState('100000')
  const [tenure, setTenure] = useState('12')
  const [preview, setPreview] = useState<LoanPreview | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [showSchedule, setShowSchedule] = useState(false)

  // Choices that make whole installments for the current repayment frequency.
  const sampleChoices = useMemo(
    () => (terms.tenure_options ?? []).flatMap((o) => {
      const n = loanInstallments(terms.repayment_frequency, o)
      return n === null ? [] : [{ ...o, installments: n }]
    }),
    [terms.tenure_options, terms.repayment_frequency],
  )

  // Start the sample inside the plan's limits (12 suits monthly plans; a yearly plan allows only 1–5), or on a choice.
  useEffect(() => {
    if (sampleChoices.length > 0) {
      setTenure((t) => (sampleChoices.some((o) => String(o.installments) === t) ? t : String(sampleChoices[0].installments)))
      return
    }
    const min = Number(terms.min_tenure)
    const max = Number(terms.max_tenure)
    if (!min || !max) return
    setTenure((t) => (Number(t) >= min && Number(t) <= max ? t : String(Math.min(max, Math.max(min, 12)))))
  }, [terms.min_tenure, terms.max_tenure, sampleChoices])

  useEffect(() => {
    const timer = setTimeout(() => {
      api
        .post<LoanPreview>('/loan-products/preview', {
          terms: toPayload(terms),
          principal,
          tenure: /^\d+$/.test(tenure) ? Number(tenure) : tenure,
        })
        .then((p) => {
          setPreview(p)
          setError(null)
        })
        .catch((err) => {
          setPreview(null)
          setError(err)
        })
    }, 400)
    return () => clearTimeout(timer)
  }, [terms, principal, tenure])

  const errors = fieldErrors(error)
  const termsInvalid = Object.keys(errors).some((k) => k.startsWith('terms'))

  return (
    <section className="card">
      <h2 className="card__title">Repayment preview</h2>
      <div className="grid grid--2">
        <Field label="Loan amount (₹)" error={errors.principal}>
          <input inputMode="decimal" value={principal} onChange={(e) => setPrincipal(e.target.value)} />
        </Field>
        {sampleChoices.length > 0 ? null : (
          <Field label={`Tenure (${FREQUENCY_UNIT[terms.repayment_frequency]})`} error={errors.tenure}>
            <input inputMode="numeric" value={tenure} onChange={(e) => setTenure(e.target.value)} />
          </Field>
        )}
      </div>
      {sampleChoices.length > 0 ? (
        <Field label="Tenure" error={errors.tenure}>
          <TenurePicker
            options={sampleChoices}
            value={tenureKey(sampleChoices.find((o) => String(o.installments) === tenure) ?? sampleChoices[0])}
            onChange={(o) => setTenure(String(o.installments))}
            note={(o) => (o.unit === FREQUENCY_UNIT[terms.repayment_frequency] ? null : `${o.installments} ${FREQUENCY_UNIT[terms.repayment_frequency]}`)}
          />
        </Field>
      ) : null}
      {termsInvalid ? <p className="muted small">Fix the highlighted terms to see a preview.</p> : null}
      {error && !(error instanceof ApiError && error.status === 422) ? <ErrorBanner error={error} /> : null}
      {preview ? (
        <>
          <div className="highlight">
            <div className="highlight__label">{titleCase(terms.repayment_frequency)} installment</div>
            <div className="highlight__value">{money(preview.installment_amount)}</div>
          </div>
          <dl className="details details--compact">
            <div className="details__row">
              <dt>Total interest</dt>
              <dd>{money(preview.total_interest)}</dd>
            </div>
            <div className="details__row">
              <dt>Total repayable</dt>
              <dd>{money(preview.total_payable)}</dd>
            </div>
            <div className="details__row">
              <dt>Processing fee</dt>
              <dd>{money(preview.processing_fee)}</dd>
            </div>
            <div className="details__row">
              <dt>GST on fee</dt>
              <dd>{money(preview.tax_on_processing_fee)}</dd>
            </div>
            <div className="details__row">
              <dt>Net disbursed to customer</dt>
              <dd>
                <strong>{money(preview.net_disbursement)}</strong>
              </dd>
            </div>
          </dl>
          <button type="button" className="btn btn--small btn--ghost" onClick={() => setShowSchedule((s) => !s)}>
            {showSchedule ? 'Hide schedule' : `Show schedule (${preview.schedule.length} rows)`}
          </button>
          {showSchedule ? (
            <div className="table-wrap table-wrap--scroll">
              <table className="table table--compact">
                <thead>
                  <tr>
                    <th>#</th>
                    <th className="num">Installment</th>
                    <th className="num">Principal</th>
                    <th className="num">Interest</th>
                    <th className="num">Balance</th>
                  </tr>
                </thead>
                <tbody>
                  {preview.schedule.map((row) => (
                    <tr key={row.number}>
                      <td>{row.number}</td>
                      <td className="num">{money(row.installment)}</td>
                      <td className="num">{money(row.principal)}</td>
                      <td className="num">{money(row.interest)}</td>
                      <td className="num">{money(row.balance)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
        </>
      ) : null}
    </section>
  )
}

function VersionHistory({ product }: { product: LoanProduct }) {
  return (
    <section className="card">
      <h2 className="card__title">Version history</h2>
      <ul className="history">
        {product.versions?.map((v) => (
          <li key={v.id}>
            <strong>v{v.version}</strong>
            {v.id === product.version_id ? <span className="badge badge--success">current</span> : null}
            <div>{v.change_reason}</div>
            <div className="muted small">
              {dateTime(v.created_at)} · {v.created_by_name} · {titleCase(v.terms.interest_method)} at {Number(v.terms.annual_interest_rate)}%
            </div>
          </li>
        ))}
      </ul>
    </section>
  )
}

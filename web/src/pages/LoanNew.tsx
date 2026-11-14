import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { CustomerPicker } from '../components/CustomerPicker'
import { PlanPicker } from '../components/PlanPicker'
import { ErrorBanner, Field, PageHeader, bannerError, fieldErrors } from '../components/ui'
import { money, titleCase } from '../format'
import { useFetch } from '../hooks'
import { TenurePicker } from '../components/Tenure'
import { LOAN_TYPES, LOAN_UNIT, loanTenureSummary, rateSummary, tenureKey } from '../plans'
import type { Loan, LoanPreview, LoanProduct } from '../types'

export function LoanNew() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const { data: products } = useFetch<LoanProduct[]>('/loan-products')
  const [customerId, setCustomerId] = useState(params.get('customer_id') ?? '')
  const [productId, setProductId] = useState('')
  const [principal, setPrincipal] = useState('')
  const [tenure, setTenure] = useState('')
  const [purpose, setPurpose] = useState('')
  const [preview, setPreview] = useState<LoanPreview | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const errors = fieldErrors(error)

  const active = useMemo(() => (products ?? []).filter((p) => p.status === 'active'), [products])
  const product = active.find((p) => p.id === productId)

  const plans = useMemo(
    () =>
      active.map((p) => ({
        id: p.id,
        type: p.terms.repayment_frequency,
        name: p.name,
        lines: [
          `${rateSummary(p.terms.annual_interest_rate, p.terms.tenure_options)} · ${titleCase(p.terms.interest_method)}`,
          `${money(p.terms.min_amount)} – ${money(p.terms.max_amount)}`,
          loanTenureSummary(p.terms),
        ],
      })),
    [active],
  )
  // Choosing a plan starts the tenure at its minimum; tapping the plan that is already chosen keeps what was typed.
  const chooseProduct = useCallback(
    (id: string) => {
      if (id === productId) return
      setProductId(id)
      const chosen = active.find((p) => p.id === id)
      setTenure(chosen ? String(chosen.terms.min_tenure) : '')
    },
    [productId, active],
  )

  useEffect(() => {
    setPreview(null)
    if (!product || !principal || !tenure) return
    const timer = setTimeout(() => {
      api
        .post<LoanPreview>('/loan-products/preview', { terms: product.terms, principal, tenure: Number(tenure) })
        .then(setPreview)
        .catch(() => setPreview(null))
    }, 350)
    return () => clearTimeout(timer)
  }, [product, principal, tenure])

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const loan = await api.post<Loan>('/loans', {
        customer_id: Number(customerId),
        product_id: Number(productId),
        principal,
        tenure: Number(tenure),
        purpose: purpose || null,
      })
      navigate(`/loans/${loan.id}`)
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  const unit = product ? LOAN_UNIT[product.terms.repayment_frequency] : 'installments'

  return (
    <>
      <PageHeader title="New loan application" back={{ to: '/loans', label: 'Loans' }} subtitle="Submitted applications need approval from a different person before disbursement." />
      <div className="grid grid--2-1">
        <form className="card" onSubmit={submit} noValidate>
          <ErrorBanner error={bannerError(error)} />
          <div className="stack">
            <Field label="Customer *" error={errors.customer_id}>
              <CustomerPicker value={customerId} onChange={setCustomerId} />
            </Field>
            <Field label="Repayment type *" error={errors.product_id}>
              <PlanPicker types={LOAN_TYPES} plans={plans} value={productId} onChange={chooseProduct} />
            </Field>
            {product ? (
              <div className="grid grid--2 grid--tight">
                <Field label="Amount (₹) *" error={errors.principal} hint={`${money(product.terms.min_amount)} – ${money(product.terms.max_amount)}`}>
                  <input inputMode="decimal" value={principal} onChange={(e) => setPrincipal(e.target.value)} />
                </Field>
                {product.terms.tenure_options?.length ? null : (
                  <Field label={`Tenure (${unit}) *`} error={errors.tenure} hint={`${product.terms.min_tenure} – ${product.terms.max_tenure} ${unit}`}>
                    <input inputMode="numeric" value={tenure} onChange={(e) => setTenure(e.target.value)} />
                  </Field>
                )}
              </div>
            ) : null}
            {product?.terms.tenure_options?.length ? (
              <Field label="Tenure *" error={errors.tenure}>
                <TenurePicker
                  options={product.terms.tenure_options}
                  value={tenureKey(product.terms.tenure_options.find((o) => String(o.installments) === tenure) ?? { value: 0, unit: 'days' })}
                  onChange={(o) => setTenure(String(o.installments))}
                  note={(o) => (o.unit === unit ? null : `${o.installments} ${unit}`)}
                />
              </Field>
            ) : null}
            <Field label="Purpose" error={errors.purpose}>
              <input value={purpose} onChange={(e) => setPurpose(e.target.value)} placeholder="e.g. Working capital for shop" />
            </Field>
          </div>
          <div className="form__actions">
            <button type="submit" className="btn btn--primary" disabled={saving || !customerId || !productId}>
              {saving ? 'Submitting…' : 'Submit for approval'}
            </button>
          </div>
        </form>

        <section className="card">
          <h2 className="card__title">Repayment summary</h2>
          {preview ? (
            <>
              <div className="highlight">
                <div className="highlight__label">{product ? titleCase(product.terms.repayment_frequency) : ''} installment</div>
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
                  <dt>Processing fee + GST</dt>
                  <dd>{money(String(Number(preview.processing_fee) + Number(preview.tax_on_processing_fee)))}</dd>
                </div>
                <div className="details__row details__row--total">
                  <dt>Customer receives</dt>
                  <dd>{money(preview.net_disbursement)}</dd>
                </div>
              </dl>
            </>
          ) : (
            <p className="muted">Enter an amount and tenure within the product limits to see the installment.</p>
          )}
        </section>
      </div>
    </>
  )
}

import type { PlanType } from './components/PlanPicker'
import type { DepositTerms, LoanTerms, TenureOption } from './types'

/** Tenure unit per repayment frequency ("50 days", "12 months"). */
export const LOAN_UNIT: Record<LoanTerms['repayment_frequency'], string> = {
  daily: 'days', weekly: 'weeks', fortnightly: 'fortnights', monthly: 'months', quarterly: 'quarters', yearly: 'years',
}

export const LOAN_TYPES: PlanType[] = [
  { key: 'daily', label: 'Daily', hint: 'Collected every day' },
  { key: 'weekly', label: 'Weekly', hint: 'Once a week' },
  { key: 'fortnightly', label: 'Fortnightly', hint: 'Every 2 weeks' },
  { key: 'monthly', label: 'Monthly', hint: 'Monthly EMI' },
  { key: 'quarterly', label: 'Quarterly', hint: 'Every 3 months' },
  { key: 'yearly', label: 'Yearly', hint: 'Once a year' },
]

export const DEPOSIT_TYPES: PlanType[] = [
  { key: 'daily', label: 'Daily savings', hint: 'Save a little every day' },
  { key: 'weekly', label: 'Weekly savings', hint: 'Save once a week' },
  { key: 'monthly', label: 'Monthly savings', hint: 'Save once a month' },
  { key: 'fixed', label: 'Fixed deposit', hint: 'One lump sum, fixed term' },
]

/** Plan-picker group for a savings plan: its contribution frequency, or "fixed". */
export const depositType = (terms: Pick<DepositTerms, 'scheme' | 'contribution_frequency'>) => (terms.scheme === 'fixed' ? 'fixed' : terms.contribution_frequency)

// ---- Tenure choices (mirrors api/src/Services/Tenure.php) ----------------------------------------

export const TENURE_UNITS: TenureOption['unit'][] = ['days', 'weeks', 'months', 'years']

/** "45 days", "1 month", "2 years". */
export function tenureLabel(o: Pick<TenureOption, 'value' | 'unit'>): string {
  const value = Number(o.value)
  return `${value} ${value === 1 ? o.unit.replace(/s$/, '') : o.unit}`
}

/** "5%" for a tenure with its own rate, else null. */
export const tenureRate = (o: Pick<TenureOption, 'rate'>) => (o.rate !== undefined && o.rate !== '' ? `${Number(o.rate)}%` : null)

/** How a savings plan's rate reads: per year, or once on the total deposited (flat plans). */
export const depositPer = (t: { interest_method?: string }) => (t.interest_method === 'flat' ? 'on total deposited' : 'a year')

/** "5% – 7.5% a year, by tenure" when tenures carry their own rates, else the plan rate, e.g. "7% a year". */
export function rateSummary(planRate: string, options: Pick<TenureOption, 'rate'>[] | undefined, per = 'a year'): string {
  const rates = options && options.length > 0 ? options.map((o) => Number(o.rate !== undefined && o.rate !== '' ? o.rate : planRate)) : [Number(planRate)]
  const low = Math.min(...rates), high = Math.max(...rates)
  return low === high ? `${low}% ${per}` : `${low}% – ${high}% ${per}, by tenure`
}

export const tenureKey = (o: Pick<TenureOption, 'value' | 'unit'>) => `${Number(o.value)}-${o.unit}`

/** Approximate days, for ordering. */
export const tenureDays = (o: Pick<TenureOption, 'value' | 'unit'>) => Number(o.value) * { days: 1, weeks: 7, months: 30, years: 365 }[o.unit]

/** A savings plan's choices; plan versions from before choices existed had a single tenure_months. */
export function depositTenures(terms: Pick<DepositTerms, 'tenure_options' | 'tenure_months'>): TenureOption[] {
  return terms.tenure_options?.length ? terms.tenure_options : [{ value: terms.tenure_months ?? 12, unit: 'months' }]
}

const LOAN_FACTORS: Record<LoanTerms['repayment_frequency'], Partial<Record<TenureOption['unit'], number | [number, number] | ['round', number, number]>>> = {
  daily: { days: 1, weeks: 7, months: 30, years: 365 },
  weekly: { days: [1, 7], weeks: 1, months: ['round', 52, 12], years: 52 },
  fortnightly: { days: [1, 14], weeks: [1, 2], months: ['round', 26, 12], years: 26 },
  monthly: { months: 1, years: 12 },
  quarterly: { months: [1, 3], years: 4 },
  yearly: { months: [1, 12], years: 1 },
}

/** Installments a loan tenure means for the repayment frequency, or null if it is not a whole number of them. */
export function loanInstallments(frequency: LoanTerms['repayment_frequency'], o: Pick<TenureOption, 'value' | 'unit'>): number | null {
  const factor = LOAN_FACTORS[frequency]?.[o.unit]
  const value = Number(o.value)
  if (factor === undefined || !Number.isInteger(value) || value < 1) return null
  if (typeof factor === 'number') return value * factor
  if (factor[0] === 'round') return Math.max(1, Math.round((value * factor[1]) / factor[2]))
  const [num, den] = factor as [number, number]
  return (value * num) % den === 0 ? (value * num) / den : null
}

/** Units that can make a whole number of installments for this frequency. */
export const loanUnits = (frequency: LoanTerms['repayment_frequency']) => TENURE_UNITS.filter((u) => LOAN_FACTORS[frequency]?.[u] !== undefined)

/** "45 days, 3 months or 1 year" / "50 – 300 days" for a loan plan. */
export function loanTenureSummary(terms: Pick<LoanTerms, 'tenure_options' | 'min_tenure' | 'max_tenure' | 'repayment_frequency'>): string {
  if (terms.tenure_options?.length) return listOr(terms.tenure_options.map(tenureLabel))
  return `${terms.min_tenure} – ${terms.max_tenure} ${LOAN_UNIT[terms.repayment_frequency]}`
}

/** "3 months, 6 months or 1 year". */
export function listOr(items: string[]): string {
  return items.length <= 1 ? (items[0] ?? '') : `${items.slice(0, -1).join(', ')} or ${items[items.length - 1]}`
}

/** "3 days", "1 week", "2 months": a count of a savings plan's collection periods. */
export function periodCount(n: number, frequency: 'daily' | 'weekly' | 'monthly' | null | undefined): string {
  const [one, many] = frequency === 'weekly' ? ['week', 'weeks'] : frequency === 'monthly' ? ['month', 'months'] : ['day', 'days']
  return `${n} ${n === 1 ? one : many}`
}

/** "for 26 Oct 2026" or "for 26 Oct – 29 Oct 2026 (4 installments)": what a savings payment paid for. */
export function coversLabel(p: { covers_from?: string | null; covers_to?: string | null; covers_count?: number | string | null }, fmt: (d: string) => string): string | null {
  const n = Number(p.covers_count ?? 0)
  if (!p.covers_from || !p.covers_to || n < 1) return null
  return n === 1 ? fmt(p.covers_from) : `${fmt(p.covers_from)} – ${fmt(p.covers_to)} (${n} installments)`
}

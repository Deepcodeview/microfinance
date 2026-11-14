import { useState } from 'react'
import { TENURE_UNITS, tenureDays, tenureKey, tenureLabel, tenureRate } from '../plans'
import type { TenureOption } from '../types'

const PRESETS: TenureOption[] = [
  { value: 45, unit: 'days' },
  { value: 3, unit: 'months' },
  { value: 6, unit: 'months' },
  { value: 1, unit: 'years' },
  { value: 2, unit: 'years' },
]

/**
 * Admin list of the tenures a plan offers: chips to remove, quick presets and a custom "number + unit" row.
 * `describe` adds a note per choice (e.g. "= 90 installments"); returning null marks it as not usable.
 */
export function TenureOptionsEditor({
  value,
  onChange,
  units = TENURE_UNITS,
  describe,
  withRates = false,
  rateUnit = '% a year',
}: {
  value: TenureOption[]
  onChange: (options: TenureOption[]) => void
  units?: TenureOption['unit'][]
  describe?: (option: TenureOption) => string | null
  /** Show a "% a year" box on each tenure (empty = the plan's rate). */
  withRates?: boolean
  /** Label after each rate box, e.g. "% on total" for flat savings plans. */
  rateUnit?: string
}) {
  const [amount, setAmount] = useState('')
  const [unit, setUnit] = useState<TenureOption['unit']>(units.includes('months') ? 'months' : units[0])
  const keys = new Set(value.map(tenureKey))
  const add = (option: TenureOption) => {
    if (keys.has(tenureKey(option))) return
    onChange([...value, option].sort((a, b) => tenureDays(a) - tenureDays(b)))
  }
  const presets = PRESETS.filter((p) => units.includes(p.unit) && !keys.has(tenureKey(p)) && (!describe || describe(p) !== null))

  return (
    <div className="tenure-editor">
      {value.length === 0 ? <p className="small muted">No tenures yet — add the ones this plan offers.</p> : null}
      <div className="tenure-chips">
        {value.map((o) => {
          const note = describe?.(o)
          return (
            <span key={tenureKey(o)} className={`tenure-chip${note === null ? ' tenure-chip--bad' : ''}`}>
              <strong>{tenureLabel(o)}</strong>
              {note ? <span className="tenure-chip__note">{note}</span> : null}
              {note === null ? <span className="tenure-chip__note">does not fit this plan</span> : null}
              {withRates ? (
                <label className="tenure-chip__rate">
                  <input
                    inputMode="decimal"
                    value={o.rate === undefined ? '' : String(Number(o.rate) || o.rate)}
                    placeholder="plan rate"
                    aria-label={`Interest rate for ${tenureLabel(o)}`}
                    onChange={(e) => onChange(value.map((x) => (tenureKey(x) === tenureKey(o) ? { ...x, rate: e.target.value.replace(/[^\d.]/g, '') || undefined } : x)))}
                  />
                  {rateUnit}
                </label>
              ) : null}
              <button type="button" aria-label={`Remove ${tenureLabel(o)}`} onClick={() => onChange(value.filter((x) => tenureKey(x) !== tenureKey(o)))}>×</button>
            </span>
          )
        })}
      </div>
      {presets.length > 0 ? (
        <div className="tenure-presets">
          <span className="small muted">Quick add:</span>
          {presets.map((p) => (
            <button key={tenureKey(p)} type="button" className="btn btn--small btn--ghost" onClick={() => add(p)}>+ {tenureLabel(p)}</button>
          ))}
        </div>
      ) : null}
      <div className="tenure-add">
        <input inputMode="numeric" value={amount} onChange={(e) => setAmount(e.target.value.replace(/\D/g, ''))} placeholder="e.g. 45" aria-label="Tenure length" />
        <select value={unit} onChange={(e) => setUnit(e.target.value as TenureOption['unit'])} aria-label="Tenure unit">
          {units.map((u) => <option key={u} value={u}>{u}</option>)}
        </select>
        <button
          type="button"
          className="btn btn--small"
          disabled={!amount || Number(amount) < 1}
          onClick={() => {
            add({ value: Number(amount), unit })
            setAmount('')
          }}
        >
          Add tenure
        </button>
      </div>
    </div>
  )
}

/** Pick one tenure of a plan (chips). `note` adds a second line, e.g. "90 installments". */
export function TenurePicker({
  options,
  value,
  onChange,
  note,
  per = 'a year',
}: {
  options: TenureOption[]
  value: string
  onChange: (option: TenureOption) => void
  note?: (option: TenureOption) => string | null
  /** How a tenure's own rate reads: "a year", or "on total deposited" for flat savings plans. */
  per?: string
}) {
  return (
    <div className="plan-types tenure-picker" role="radiogroup" aria-label="Tenure">
      {options.map((o) => (
        <button key={tenureKey(o)} type="button" role="radio" aria-checked={tenureKey(o) === value} className="plan-type" onClick={() => onChange(o)}>
          <span className="plan-type__label">{tenureLabel(o)}</span>
          {note?.(o) || tenureRate(o) ? <span className="plan-type__hint">{[note?.(o), tenureRate(o) ? `${tenureRate(o)} ${per}` : null].filter(Boolean).join(' · ')}</span> : null}
        </button>
      ))}
    </div>
  )
}

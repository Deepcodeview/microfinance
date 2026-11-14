import { useEffect, useMemo } from 'react'

export interface Plan {
  id: string
  /** Groups plans under a type chip, e.g. "daily" or "fixed". */
  type: string
  name: string
  lines: string[]
}

export interface PlanType {
  key: string
  label: string
  hint: string
}

/**
 * Two-step chooser: pick the account type (Daily / Weekly / Monthly / …), then the plan within it.
 * Types with no active plan are hidden, so admins control what agents see from Products.
 */
export function PlanPicker({ types, plans, value, onChange }: { types: PlanType[]; plans: Plan[]; value: string; onChange: (id: string) => void }) {
  const available = useMemo(() => types.filter((t) => plans.some((p) => p.type === t.key)), [types, plans])
  const current = plans.find((p) => p.id === value)
  const activeType = current?.type ?? available[0]?.key
  const shown = plans.filter((p) => p.type === activeType)

  useEffect(() => {
    if (!current && shown.length > 0) onChange(shown[0].id)
  }, [current, shown, onChange])

  if (plans.length === 0) return <p className="muted">No active plans. An admin can add them under Products.</p>

  return (
    <div className="plan-picker">
      <div className="plan-types" role="radiogroup" aria-label="Account type">
        {available.map((t) => (
          <button
            key={t.key}
            type="button"
            role="radio"
            aria-checked={t.key === activeType}
            className="plan-type"
            onClick={() => t.key !== activeType && onChange(plans.find((p) => p.type === t.key)!.id)}
          >
            <span className="plan-type__label">{t.label}</span>
            <span className="plan-type__hint">{t.hint}</span>
          </button>
        ))}
      </div>
      {shown.length > 1 || (shown[0] && shown[0].lines.length > 0) ? (
        <div className="plan-list" role="radiogroup" aria-label="Plan">
          {shown.map((p) => (
            <button key={p.id} type="button" role="radio" aria-checked={p.id === value} className="plan-card" onClick={() => onChange(p.id)}>
              <span className="plan-card__name">{p.name}</span>
              {p.lines.map((l) => <span key={l} className="plan-card__line">{l}</span>)}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  )
}

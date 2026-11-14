import { locale } from './i18n'
// Display-only formatting. Values arrive as decimal strings and are never used for arithmetic here.

const inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2 })

export function money(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  return inr.format(Number(value))
}

/** Compact Indian notation for headline figures: ₹12.4 L, ₹3.1 Cr. */
export function moneyShort(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const n = Number(value)
  if (Math.abs(n) >= 1e7) return `₹${(n / 1e7).toFixed(2)} Cr`
  if (Math.abs(n) >= 1e5) return `₹${(n / 1e5).toFixed(2)} L`
  return inr.format(n).replace(/\.00$/, '')
}

export function percent(value: string): string {
  return `${Number(value).toString()}%`
}

export function date(value: string | null | undefined): string {
  if (!value) return '—'
  const iso = value.length === 10 ? `${value}T00:00:00` : `${value.replace(' ', 'T')}Z`
  return new Date(iso).toLocaleDateString(locale(), { day: '2-digit', month: 'short', year: 'numeric' })
}

export function dateTime(value: string | null | undefined): string {
  if (!value) return '—'
  return new Date(`${value.replace(' ', 'T')}Z`).toLocaleString(locale(), {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function titleCase(value: string): string {
  return value
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
    .replace(/\bUpi\b/g, 'UPI')
}

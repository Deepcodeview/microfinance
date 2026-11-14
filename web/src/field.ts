import { publicOrigin } from './config'
import { money } from './format'
import { coversLabel } from './plans'
import type { Payment } from './types'

/** A phone location reading sent with collections and visits. */
export interface GeoReading {
  latitude: number
  longitude: number
  accuracy: number
  captured_at: string
}

/**
 * Current phone location, or null if GPS is off, permission was refused or it took too long.
 * A reading from the last minute is reused so the payment screen stays quick.
 */
export function getLocation(timeoutMs = 10000): Promise<GeoReading | null> {
  if (!('geolocation' in navigator)) return Promise.resolve(null)
  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: Math.round(p.coords.accuracy), captured_at: new Date(p.timestamp).toISOString() }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: timeoutMs, maximumAge: 60000 },
    )
  })
}

export const mapsLink = (lat: string | number, lng: string | number) => `https://maps.google.com/?q=${lat},${lng}`

/** wa.me link with the receipt text for the customer's number (Indian numbers get the 91 country code). */
export function whatsappReceiptLink(p: Pick<Payment, 'customer_phone' | 'customer_name' | 'receipt_number' | 'amount' | 'account_number' | 'business_date' | 'method' | 'verification_code' | 'covers_from' | 'covers_to' | 'covers_count'>, company: string): string {
  const digits = p.customer_phone.replace(/\D/g, '')
  const phone = digits.length === 10 ? `91${digits}` : digits
  const lines = [
    `${company} — payment receipt ${p.receipt_number ?? ''}`.trim(),
    `Dear ${p.customer_name}, we received ${money(p.amount)} for ${p.account_number} on ${p.business_date} (${p.method.toUpperCase()}).`,
    coversLabel(p, (d) => d) ? `Savings installments: ${coversLabel(p, (d) => d)}.` : '',
    p.verification_code ? `Check this receipt: ${publicOrigin()}/verify/${p.verification_code}` : '',
    'Thank you.',
  ].filter(Boolean)
  return `https://wa.me/${phone}?text=${encodeURIComponent(lines.join('\n'))}`
}

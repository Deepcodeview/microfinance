import QRCode from 'qrcode'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ApiError, api, isNetworkError } from '../api'
import { useAuth } from '../auth'
import { APP_MODE } from '../config'
import { getLocation, whatsappReceiptLink, type GeoReading } from '../field'
import { money } from '../format'
import { newIdempotencyKey } from '../hooks'
import { queuePayment } from '../offline'
import type { Payment, PaymentMethod } from '../types'
import { ErrorBanner, Field, Modal, Segmented, bannerError, fieldErrors } from './ui'

export interface PaymentTarget {
  accountType: 'loan' | 'deposit'
  accountId: string
  accountNumber: string
  customerName: string
  suggestedAmount?: string
  settlementAmount?: string
}

interface UpiIntent {
  uri: string
  vpa: string
  payee: string
  amount: string
  tr: string
  note: string
}

const METHODS: { id: PaymentMethod; label: string }[] = [
  { id: 'cash', label: 'Cash' },
  { id: 'upi', label: 'UPI QR' },
]

/** Shrinks a phone screenshot so uploads stay fast on mobile data (max 1400px, JPEG). */
export async function compressImage(file: File): Promise<string> {
  const bitmap = await createImageBitmap(file)
  const scale = Math.min(1, 1400 / Math.max(bitmap.width, bitmap.height))
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(bitmap.width * scale)
  canvas.height = Math.round(bitmap.height * scale)
  canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
  return canvas.toDataURL('image/jpeg', 0.82)
}

export function RecordPaymentModal({ target, onClose, onRecorded }: { target: PaymentTarget; onClose: () => void; onRecorded: (p: Payment) => void }) {
  const { user, can } = useAuth()
  // One key per open form: a double-click or network retry can never record the payment twice.
  const key = useRef(newIdempotencyKey())
  const [purpose, setPurpose] = useState<'installment' | 'settlement' | 'contribution'>(target.accountType === 'deposit' ? 'contribution' : 'installment')
  const [amount, setAmount] = useState(target.suggestedAmount ? String(Number(target.suggestedAmount)) : '')
  const [method, setMethod] = useState<PaymentMethod>('cash')
  const [reference, setReference] = useState('')
  const [notes, setNotes] = useState('')
  const [proof, setProof] = useState<string | null>(null)
  const [intent, setIntent] = useState<UpiIntent | null>(null)
  const [qr, setQr] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [repeatWarning, setRepeatWarning] = useState('')
  const [allowRepeat, setAllowRepeat] = useState(false)
  const [saving, setSaving] = useState(false)
  const [result, setResult] = useState<Payment | null>(null)
  const [queued, setQueued] = useState(false)
  const errors = fieldErrors(error)
  const upiEnabled = user?.settings.upi_enabled ?? false
  // Agent app: where this collection is taken (read while the form is filled in, so it costs no waiting).
  const inField = APP_MODE === 'agent'
  const [location, setLocation] = useState<GeoReading | null>(null)
  const [locating, setLocating] = useState(inField)
  const locate = () => {
    setLocating(true)
    getLocation().then((l) => {
      setLocation(l)
      setLocating(false)
    })
  }
  useEffect(() => {
    if (inField) locate()
  }, [inField])

  useEffect(() => {
    if (intent) QRCode.toDataURL(intent.uri, { margin: 1, width: 560, errorCorrectionLevel: 'M' }).then(setQr).catch(() => setQr(''))
  }, [intent])

  function changeBody<T>(setter: (v: T) => void) {
    return (v: T) => {
      setter(v)
      key.current = newIdempotencyKey()
    }
  }

  async function showQr() {
    setError(null)
    setSaving(true)
    try {
      setIntent(await api.post<UpiIntent>('/payments/upi-intent', { account_type: target.accountType, account_id: Number(target.accountId), amount }))
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  async function attach(file: File | undefined) {
    if (!file) return
    try {
      setProof(await compressImage(file))
      key.current = newIdempotencyKey()
    } catch {
      setError(new Error('Could not read that image. Try another screenshot.'))
    }
  }

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    const body = {
      account_type: target.accountType,
      account_id: Number(target.accountId),
      amount,
      method,
      reference: method === 'cash' ? null : reference.trim() || null,
      upi_tr: method === 'upi' ? intent?.tr ?? null : null,
      proof_image: method === 'cash' ? null : proof,
      purpose,
      notes: notes || null,
      ...(allowRepeat ? { allow_repeat: true } : {}),
      ...(location ? { location } : {}),
    }
    try {
      const payment = await api.post<Payment>('/payments', body, { 'Idempotency-Key': key.current })
      setResult(payment)
      onRecorded(payment)
    } catch (err) {
      if (err instanceof ApiError && err.code === 'REPEAT_TODAY') {
        // Already collected today: ask before recording a second payment (usually a duplicate).
        setRepeatWarning(err.message)
      } else if (isNetworkError(err)) {
        // No connection: keep it encrypted on this device with the same key; it syncs automatically.
        await queuePayment({ idempotencyKey: key.current, body, label: `${target.customerName} · ${target.accountNumber} · ${money(amount)} ${method}` })
        setQueued(true)
      } else {
        setError(err)
      }
    } finally {
      setSaving(false)
    }
  }

  if (queued) {
    return (
      <Modal title="Saved offline" onClose={onClose}>
        <div className="highlight">
          <div className="highlight__label">Pending sync — not yet a receipt</div>
          <div className="highlight__value">{money(amount)}</div>
        </div>
        <p>
          You're offline. This collection is stored encrypted on this device and will be sent automatically when the connection returns.
          Do not give the customer a receipt number until it syncs.
        </p>
        <div className="modal__footer">
          <button type="button" className="btn btn--primary" onClick={onClose}>OK</button>
        </div>
      </Modal>
    )
  }

  if (result) {
    return (
      <Modal title={result.status === 'confirmed' ? 'Payment recorded' : 'Payment submitted'} onClose={onClose}>
        <div className="highlight">
          <div className="highlight__label">{result.payment_number}</div>
          <div className="highlight__value">{money(result.amount)}</div>
        </div>
        {result.status === 'pending' ? (
          <div className="banner banner--warning">Sent to finance for confirmation{result.method === 'upi' ? ' against the UTR / screenshot' : ''}. A receipt is issued once it's confirmed.</div>
        ) : (
          <p>Receipt <span className="mono">{result.receipt_number}</span> issued.</p>
        )}
        {result.receipt_number ? (
          <a className="btn btn--block whatsapp-btn" href={whatsappReceiptLink(result, user?.settings.company_name || 'KBSAVE')} target="_blank" rel="noreferrer">
            Share receipt on WhatsApp
          </a>
        ) : null}
        <div className="modal__footer">
          {result.receipt_number ? <Link to={`/payments/${result.id}/receipt`} className="btn">View receipt</Link> : null}
          <button type="button" className="btn btn--primary" onClick={onClose}>Done</button>
        </div>
      </Modal>
    )
  }

  const digitalNeedsConfirm = method !== 'cash' && (!can('payments.confirm') || (method === 'upi' && !reference))
  const upiStep = method === 'upi' && intent !== null

  return (
    <Modal title="Record payment" onClose={onClose}>
      <form onSubmit={submit} className="stack" noValidate>
        <div className="muted">
          {target.customerName} · <span className="mono">{target.accountNumber}</span>
        </div>
        <ErrorBanner error={bannerError(error)} />
        {!upiStep ? (
          <>
            {target.accountType === 'loan' && target.settlementAmount ? (
              <Segmented
                options={[{ id: 'installment', label: 'Installment' }, { id: 'settlement', label: 'Full settlement' }]}
                value={purpose as 'installment' | 'settlement'}
                onChange={changeBody((p: 'installment' | 'settlement') => {
                  setPurpose(p)
                  if (p === 'settlement' && target.settlementAmount) setAmount(String(Number(target.settlementAmount)))
                })}
              />
            ) : null}
            <Field label="Amount (₹)" error={errors.amount} hint={purpose === 'settlement' ? 'Settlement must be paid in full.' : undefined}>
              <input inputMode="decimal" autoFocus value={amount} readOnly={purpose === 'settlement'} onChange={(e) => changeBody(setAmount)(e.target.value)} style={{ fontSize: 20 }} />
            </Field>
            <Field label="Method" error={errors.method}>
              <Segmented options={METHODS} value={method} onChange={changeBody(setMethod)} />
            </Field>
          </>
        ) : null}

        {method === 'upi' && !intent ? (
          upiEnabled ? (
            <button type="button" className="btn btn--primary btn--block" style={{ padding: 12 }} disabled={saving || !Number(amount)} onClick={showQr}>
              Show QR for {amount ? money(amount) : 'amount'}
            </button>
          ) : (
            <div className="banner banner--warning">UPI QR collection isn't set up yet. Ask an admin to add the organisation's UPI ID in Settings.</div>
          )
        ) : null}

        {upiStep ? (
          <>
            <div className="upi-qr">
              <div className="upi-qr__amount">{money(intent.amount)}</div>
              {qr ? <img src={qr} alt={`UPI QR to pay ${money(intent.amount)} to ${intent.payee}`} className="upi-qr__img" /> : <div className="upi-qr__img" />}
              <div className="upi-qr__payee">{intent.payee}</div>
              <div className="small muted mono">{intent.vpa}</div>
              <div className="small muted">Ask the customer to scan with any UPI app (GPay, PhonePe, Paytm, BHIM). Ref {intent.tr}</div>
            </div>
            <div className="upi-steps">After the customer pays, add the proof:</div>
            <Field label="UTR / UPI reference (12 digits)" error={errors.reference} hint="Shown on the customer's payment success screen.">
              <input
                inputMode="numeric"
                maxLength={12}
                value={reference}
                onChange={(e) => changeBody(setReference)(e.target.value.replace(/\D/g, ''))}
                placeholder="e.g. 431298765012"
                style={{ letterSpacing: 2, fontSize: 18 }}
              />
            </Field>
            <Field label="Payment screenshot" error={errors.proof_image} hint="Photo of the customer's success screen, or a screenshot.">
              <div className="proof-picker">
                {proof ? <img src={proof} alt="Attached payment proof" className="proof-picker__thumb" /> : null}
                <label className="btn">
                  {proof ? 'Change photo' : 'Take photo / choose'}
                  <input type="file" accept="image/*" capture="environment" hidden onChange={(e) => attach(e.target.files?.[0])} />
                </label>
                {proof ? <button type="button" className="btn btn--ghost btn--small" onClick={() => setProof(null)}>Remove</button> : null}
              </div>
            </Field>
            <button type="button" className="btn btn--ghost btn--small" onClick={() => { setIntent(null); setProof(null); setReference('') }}>Change amount</button>
          </>
        ) : null}

        {method !== 'upi' || upiStep ? (
          <Field label="Notes" error={errors.notes}>
            <input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional" />
          </Field>
        ) : null}
        {digitalNeedsConfirm && (method !== 'upi' || upiStep) ? <div className="banner banner--info">Finance confirms digital payments against the UTR before a receipt is issued.</div> : null}
        {inField ? (
          <div className={`small location-line${location ? '' : ' location-line--off'}`}>
            {locating ? 'Getting your location…' : location ? `📍 Location recorded (±${location.accuracy} m)` : '📍 Location off — turn on GPS so this collection shows where it was taken.'}
            {!locating && !location ? <button type="button" className="btn btn--ghost btn--small" onClick={locate}>Try again</button> : null}
            {errors.location ? <div className="field__error">{errors.location}</div> : null}
          </div>
        ) : null}
        {repeatWarning ? (
          <div className="banner banner--warning">
            <div>
              <div>{repeatWarning}</div>
              <label className="checkbox consent" style={{ marginTop: 10 }}>
                <input type="checkbox" checked={allowRepeat} onChange={(e) => setAllowRepeat(e.target.checked)} />
                <span>Yes, the customer paid again today — record this payment too</span>
              </label>
            </div>
          </div>
        ) : null}
        {method !== 'upi' || upiStep ? (
          <div className="modal__footer">
            <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
            <button type="submit" className="btn btn--primary" disabled={saving || !amount || (method === 'upi' && reference.length !== 12 && !proof) || (!!repeatWarning && !allowRepeat)}>
              {saving ? 'Submitting…' : method === 'upi' ? 'Submit UPI payment' : `Record ${amount ? money(amount) : 'payment'}`}
            </button>
          </div>
        ) : null}
      </form>
    </Modal>
  )
}

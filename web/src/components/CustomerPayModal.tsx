import { useRef, useState, type FormEvent } from 'react'
import { ApiError, api } from '../api'
import { money } from '../format'
import { newIdempotencyKey } from '../hooks'
import type { Payment } from '../types'
import { compressImage } from './RecordPaymentModal'
import { ErrorBanner, Field, Modal, Notice, bannerError, fieldErrors } from './ui'

export interface PayTarget {
  accountType: 'loan' | 'deposit'
  accountId: string
  accountNumber: string
  suggestedAmount?: string | null
}

interface Intent {
  uri: string
  vpa: string
  payee: string
  amount: string
  tr: string
  note: string
}

/**
 * Customer app: pay your own installment with any UPI app (GPay, PhonePe, Paytm…). The UPI app opens with
 * the amount filled in; afterwards the customer sends the 12-digit UTR or the screenshot, and finance
 * confirms it before the receipt is issued.
 */
export function CustomerPayModal({ target, onClose, onDone }: { target: PayTarget; onClose: () => void; onDone: () => void }) {
  const key = useRef(newIdempotencyKey())
  const [amount, setAmount] = useState(target.suggestedAmount ? String(Number(target.suggestedAmount)) : '')
  const [intent, setIntent] = useState<Intent | null>(null)
  const [utr, setUtr] = useState('')
  const [proof, setProof] = useState<string | null>(null)
  const [repeatWarning, setRepeatWarning] = useState('')
  const [allowRepeat, setAllowRepeat] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const [result, setResult] = useState<Payment | null>(null)
  const errors = fieldErrors(error)

  async function openUpi() {
    setError(null)
    setSaving(true)
    try {
      const i = await api.post<Intent>('/my/payments/upi-intent', { account_type: target.accountType, account_id: Number(target.accountId), amount })
      setIntent(i)
      // Android shows the installed UPI apps with the amount and payee filled in.
      window.location.href = i.uri
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      setResult(await api.post<Payment>('/my/payments', {
        account_type: target.accountType,
        account_id: Number(target.accountId),
        amount,
        reference: utr || null,
        upi_tr: intent?.tr ?? null,
        proof_image: proof,
        ...(allowRepeat ? { allow_repeat: true } : {}),
      }, { 'Idempotency-Key': key.current }))
      onDone()
    } catch (err) {
      if (err instanceof ApiError && err.code === 'REPEAT_TODAY') setRepeatWarning(err.message)
      else setError(err)
    } finally {
      setSaving(false)
    }
  }

  if (result) {
    return (
      <Modal title="Payment sent" onClose={onClose}>
        <div className="highlight">
          <div className="highlight__label">{result.payment_number}</div>
          <div className="highlight__value">{money(result.amount)}</div>
        </div>
        <p>Thank you. Our team checks the UPI payment and confirms it — your receipt then appears in Alerts and on this account.</p>
        <div className="modal__footer"><button type="button" className="btn btn--primary" onClick={onClose}>Done</button></div>
      </Modal>
    )
  }

  return (
    <Modal title={`Pay ${target.accountNumber}`} onClose={onClose}>
      <form className="stack" onSubmit={submit} noValidate>
        <ErrorBanner error={bannerError(error)} />
        {!intent ? (
          <>
            <Field label="Amount (₹)" error={errors.amount}>
              <input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} style={{ fontSize: 20 }} />
            </Field>
            <button type="button" className="btn btn--primary btn--block" disabled={saving || !Number(amount)} onClick={openUpi}>
              {saving ? 'Opening…' : `Pay ${amount ? money(amount) : ''} with UPI app`}
            </button>
            <p className="small muted">Opens GPay, PhonePe, Paytm or your bank's UPI app with the amount filled in.</p>
          </>
        ) : (
          <>
            <Notice tone="info">
              <div>
                Paid {money(intent.amount)} to <b>{intent.payee}</b> ({intent.vpa})? Now tell us the payment reference.{' '}
                <a href={intent.uri}>Open UPI app again</a>
              </div>
            </Notice>
            <Field label="12-digit UTR / UPI Ref No." error={errors.reference} hint="Shown in your UPI app's payment details.">
              <input inputMode="numeric" maxLength={12} value={utr} onChange={(e) => setUtr(e.target.value.replace(/\D/g, ''))} style={{ letterSpacing: 2 }} />
            </Field>
            <label className="btn btn--block btn--ghost">
              {proof ? 'Screenshot attached ✓ — change' : 'Or attach the payment screenshot'}
              <input type="file" accept="image/*" hidden onChange={async (e) => {
                const file = e.target.files?.[0]
                if (!file) return
                try {
                  setProof(await compressImage(file))
                  key.current = newIdempotencyKey()
                } catch {
                  setError(new Error('Could not read that image. Try another screenshot.'))
                }
              }} />
            </label>
            {repeatWarning ? (
              <div className="banner banner--warning">
                <div>
                  <div>{repeatWarning}</div>
                  <label className="checkbox consent" style={{ marginTop: 10 }}>
                    <input type="checkbox" checked={allowRepeat} onChange={(e) => setAllowRepeat(e.target.checked)} />
                    <span>Yes, I made another payment today</span>
                  </label>
                </div>
              </div>
            ) : null}
            <div className="modal__footer">
              <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
              <button type="submit" className="btn btn--primary" disabled={saving || (utr.length !== 12 && !proof) || (!!repeatWarning && !allowRepeat)}>
                {saving ? 'Sending…' : 'Send for confirmation'}
              </button>
            </div>
          </>
        )}
      </form>
    </Modal>
  )
}

import QRCode from 'qrcode'
import { useEffect, useState, type FormEvent } from 'react'
import { api } from '../api'
import { useAuth } from '../auth'
import type { User } from '../types'
import { ErrorBanner, Field, Modal, Notice, fieldErrors } from './ui'

/** Enrol an authenticator app: scan QR → confirm with a code. Also offers turning MFA off. */
export function MfaPanel({ onDone }: { onDone?: () => void }) {
  const { user, refresh } = useAuth()
  const [setup, setSetup] = useState<{ secret: string; otpauth_uri: string } | null>(null)
  const [qr, setQr] = useState('')
  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (setup) QRCode.toDataURL(setup.otpauth_uri, { margin: 1, width: 200 }).then(setQr).catch(() => setQr(''))
  }, [setup])

  async function run(fn: () => Promise<unknown>) {
    setBusy(true)
    setError(null)
    try {
      await fn()
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }

  if (user?.mfa_enabled) {
    return (
      <form
        className="stack"
        onSubmit={(e: FormEvent) => {
          e.preventDefault()
          void run(async () => {
            await api.post<User>('/me/mfa/disable', { password })
            await refresh()
            onDone?.()
          })
        }}
      >
        <Notice>Two-factor sign-in is on. You'll be asked for a code from your authenticator app at every sign-in.</Notice>
        <ErrorBanner error={fieldErrors(error).password ? null : error} />
        <Field label="Confirm your password to turn it off" error={fieldErrors(error).password}>
          <input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </Field>
        <div className="modal__footer">
          <button type="submit" className="btn btn--danger" disabled={busy || !password}>Turn off two-factor</button>
        </div>
      </form>
    )
  }

  if (!setup) {
    return (
      <div className="stack">
        <p>Protect your account with a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy…) in addition to your password.</p>
        <ErrorBanner error={error} />
        <div className="modal__footer">
          <button type="button" className="btn btn--primary" disabled={busy} onClick={() => run(async () => setSetup(await api.post('/me/mfa/setup')))}>
            Start setup
          </button>
        </div>
      </div>
    )
  }

  return (
    <form
      className="stack"
      onSubmit={(e: FormEvent) => {
        e.preventDefault()
        void run(async () => {
          await api.post<User>('/me/mfa/enable', { code })
          await refresh()
          onDone?.()
        })
      }}
    >
      <ol style={{ margin: 0, paddingLeft: 18 }}>
        <li>Open your authenticator app and scan this code.</li>
        <li>Enter the 6-digit code it shows.</li>
      </ol>
      <div className="row" style={{ gap: 18, alignItems: 'center' }}>
        {qr ? <img src={qr} alt="QR code for your authenticator app" width={160} height={160} style={{ borderRadius: 8, background: '#fff', padding: 6 }} /> : null}
        <div className="small muted">
          Can't scan? Enter this key manually:
          <div className="mono" style={{ fontSize: 14, color: 'var(--text)', wordBreak: 'break-all', marginTop: 4 }}>{setup.secret.replace(/(.{4})/g, '$1 ').trim()}</div>
        </div>
      </div>
      <ErrorBanner error={fieldErrors(error).code ? null : error} />
      <Field label="6-digit code" error={fieldErrors(error).code}>
        <input inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} autoFocus style={{ letterSpacing: 6, fontSize: 18 }} />
      </Field>
      <div className="modal__footer">
        <button type="submit" className="btn btn--primary" disabled={busy || code.length !== 6}>Turn on two-factor</button>
      </div>
    </form>
  )
}

export function MfaModal({ onClose }: { onClose: () => void }) {
  return (
    <Modal title="Two-factor sign-in" onClose={onClose}>
      <MfaPanel onDone={onClose} />
    </Modal>
  )
}

/** Shown instead of the app when policy requires MFA and the user hasn't enrolled. */
export function MfaRequiredScreen() {
  const { logout } = useAuth()
  return (
    <div className="login">
      <div className="login__card" style={{ maxWidth: 480 }}>
        <h1 className="login__title">Secure your account</h1>
        <p className="login__subtitle">Your role handles money, so two-factor sign-in is required before you continue.</p>
        <div className="login__panel">
          <MfaPanel />
        </div>
        <button type="button" className="btn btn--ghost" onClick={() => void logout()}>Sign out</button>
      </div>
    </div>
  )
}

import { useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { APP_MODE } from '../config'
import { ErrorBanner, Field, fieldErrors } from '../components/ui'
import { LanguageSwitch } from '../components/LanguageSwitch'

export function Login() {
  const { user, login, verifyMfa } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  // Where to go after signing in (customer app: back to the plan being applied for). Same-app paths only.
  const nextParam = params.get('next') ?? ''
  const next = nextParam.startsWith('/') && !nextParam.startsWith('//') ? nextParam : '/'
  const isCustomerApp = APP_MODE === 'customer'
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [challenge, setChallenge] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [submitting, setSubmitting] = useState(false)

  if (user) return <Navigate to={next} replace />

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      if (challenge) {
        await verifyMfa(challenge, code)
        navigate(next, { replace: true })
      } else {
        const result = await login(email, password)
        if (result.status === 'mfa_required') setChallenge(result.challenge)
        else navigate(next, { replace: true })
      }
    } catch (err) {
      setError(err)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="login">
      <form className="login__card" onSubmit={onSubmit}>
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}>
          <LanguageSwitch />
        </div>
        <div className="login__brand">
          <span className="brand__mark" style={{ width: 44, height: 44, fontSize: 24, borderRadius: 12 }}>K</span>
        </div>
        <h1 className="login__title">{challenge ? 'Two-step check' : APP_MODE === 'agent' ? 'KBSAVE Agent' : 'Welcome back'}</h1>
        <p className="login__subtitle">
          {challenge ? 'Enter the 6-digit code from your authenticator app.' : APP_MODE === 'agent' ? 'Collect, record and hand over — even offline.' : APP_MODE === 'customer' ? 'Your loans, savings and receipts.' : 'Loans, deposits and daily collections in one place.'}
        </p>
        <div className="login__panel">
          {challenge ? (
            <>
              <ErrorBanner error={fieldErrors(error).code ? null : error} />
              <Field label="Authentication code" error={fieldErrors(error).code}>
                <input
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                  autoFocus
                  style={{ fontSize: 22, letterSpacing: 8, textAlign: 'center' }}
                />
              </Field>
              <button type="submit" className="btn btn--dark btn--block" disabled={submitting || code.length !== 6} style={{ padding: '10px 14px' }}>
                {submitting ? 'Checking…' : 'Verify'}
              </button>
              <button type="button" className="btn btn--ghost btn--block" onClick={() => { setChallenge(null); setCode(''); setError(null) }}>
                Use a different account
              </button>
            </>
          ) : (
            <>
              <ErrorBanner error={error} />
              <Field label={isCustomerApp ? 'Mobile number or email' : 'Email'}>
                <input
                  type={isCustomerApp ? 'text' : 'email'}
                  inputMode={isCustomerApp ? 'email' : undefined}
                  autoCapitalize="none"
                  autoComplete="username"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  autoFocus
                />
              </Field>
              <Field label="Password">
                <input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
              </Field>
              <button type="submit" className="btn btn--dark btn--block" disabled={submitting} style={{ padding: '10px 14px' }}>
                {submitting ? 'Signing in…' : 'Continue'}
              </button>
            </>
          )}
        </div>
        {isCustomerApp && !challenge ? (
          <div className="login__panel login__alt">
            <span className="muted small">New customer?</span>
            <Link to={`/register${next !== '/' ? `?next=${encodeURIComponent(next)}` : ''}`} className="btn btn--block">Create an account</Link>
            <Link to="/" className="btn btn--ghost btn--block">Browse loan &amp; savings plans</Link>
          </div>
        ) : null}
        <p className="login__foot">
          Access is logged. Use only your own account.
        </p>
      </form>
    </div>
  )
}

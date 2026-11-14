import { useState } from 'react'
import { useAuth } from '../auth'
import { ChangePasswordModal } from '../components/ChangePasswordModal'
import { Icons } from '../components/icons'
import { MfaModal } from '../components/MfaSetup'
import { APP_MODE, serverOrigin } from '../config'
import { titleCase } from '../format'
import { LanguageSwitch } from '../components/LanguageSwitch'

type Theme = 'system' | 'light' | 'dark'

function currentTheme(): Theme {
  try {
    const t = localStorage.getItem('finserve.theme')
    return t === 'light' || t === 'dark' ? t : 'system'
  } catch {
    return 'system'
  }
}

export function Profile() {
  const { user, logout } = useAuth()
  const [theme, setTheme] = useState<Theme>(currentTheme)
  const [dialog, setDialog] = useState<'password' | 'mfa' | null>(null)

  function choose(t: Theme) {
    setTheme(t)
    if (t === 'system') delete document.documentElement.dataset.theme
    else document.documentElement.dataset.theme = t
    try {
      if (t === 'system') localStorage.removeItem('finserve.theme')
      else localStorage.setItem('finserve.theme', t)
    } catch {
      // Applies for this session only.
    }
  }

  const initials = (user?.name ?? '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase()

  return (
    <div className="stack">
      <section className="card m-profile">
        <span className="avatar" style={{ width: 56, height: 56, fontSize: 20 }}>{initials}</span>
        <div>
          <div className="serif" style={{ fontSize: 20 }}>{user?.name}</div>
          <div className="small muted">{user?.email}</div>
          <div className="small muted">{user?.roles.map(titleCase).join(', ')}</div>
        </div>
      </section>

      <section className="card m-list">
        <div className="m-list__label">Appearance</div>
        <div className="segmented" style={{ width: '100%' }}>
          {(['system', 'light', 'dark'] as Theme[]).map((t) => (
            <button key={t} type="button" aria-pressed={theme === t} onClick={() => choose(t)} style={{ flex: 1 }}>{titleCase(t)}</button>
          ))}
        </div>
        <div className="m-list__label" style={{ marginTop: 12 }}>Language</div>
        <LanguageSwitch block />
      </section>

      <section className="card m-list">
        <button type="button" className="m-row" onClick={() => setDialog('password')}><Icons.key /> Change password</button>
        {APP_MODE === 'agent' ? (
          <button type="button" className="m-row" onClick={() => setDialog('mfa')}>
            <Icons.shield /> Two-factor sign-in {user?.mfa_enabled ? <span className="badge badge--success" style={{ marginLeft: 'auto' }}>On</span> : null}
          </button>
        ) : null}
        <button type="button" className="m-row m-row--danger" onClick={() => void logout()}><Icons.logout /> Sign out</button>
      </section>

      <p className="small muted" style={{ textAlign: 'center' }}>
        {APP_MODE === 'agent' ? 'KBSAVE Agent' : 'KBSAVE'} · connected to {serverOrigin() || 'this server'}
      </p>
      {dialog === 'password' ? <ChangePasswordModal onClose={() => setDialog(null)} /> : null}
      {dialog === 'mfa' ? <MfaModal onClose={() => setDialog(null)} /> : null}
    </div>
  )
}

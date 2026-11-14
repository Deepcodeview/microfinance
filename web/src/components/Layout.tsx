import { useEffect, useRef, useState, type ReactNode } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { titleCase } from '../format'
import { ChangePasswordModal } from './ChangePasswordModal'
import { MfaModal } from './MfaSetup'
import { NotificationBell } from './NotificationBell'
import { OfflineStatus } from './OfflineStatus'
import { Icons } from './icons'
import { LanguageSwitch } from './LanguageSwitch'

interface NavItem {
  to: string
  label: string
  icon: () => ReactNode
  permission: string
  end?: boolean
  when?: boolean
  count?: number
}

type Theme = 'system' | 'light' | 'dark'

function readTheme(): Theme {
  try {
    const t = localStorage.getItem('finserve.theme')
    return t === 'light' || t === 'dark' ? t : 'system'
  } catch {
    return 'system'
  }
}

function applyTheme(theme: Theme) {
  if (theme === 'system') delete document.documentElement.dataset.theme
  else document.documentElement.dataset.theme = theme
  try {
    if (theme === 'system') localStorage.removeItem('finserve.theme')
    else localStorage.setItem('finserve.theme', theme)
  } catch {
    // Theme still applies for this session.
  }
}

export function Layout() {
  const { user, can, logout } = useAuth()
  const location = useLocation()
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem('finserve.sidebar') === 'collapsed'
    } catch {
      return false
    }
  })
  const [mobileOpen, setMobileOpen] = useState(false)
  const [menu, setMenu] = useState<'user' | null>(null)
  const [theme, setTheme] = useState<Theme>(readTheme)
  const [changingPassword, setChangingPassword] = useState(false)
  const [mfaOpen, setMfaOpen] = useState(false)
  const [pending, setPending] = useState<Record<string, number>>({})
  const menuRef = useRef<HTMLDivElement>(null)

  const isCustomer = user?.customer_scope === 'own'
  const depositsOn = user?.settings.deposits_enabled ?? false

  useEffect(() => setMobileOpen(false), [location.pathname])

  useEffect(() => {
    if (isCustomer || !can('dashboard.view')) return
    api
      .get<{ pending: Record<string, number> }>('/dashboard')
      .then((d) => setPending(d.pending))
      .catch(() => {})
  }, [location.pathname, isCustomer, can])

  useEffect(() => {
    if (!menu) return
    const close = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) setMenu(null)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [menu])

  function toggleCollapsed() {
    setCollapsed((c) => {
      try {
        localStorage.setItem('finserve.sidebar', c ? 'open' : 'collapsed')
      } catch {
        // Not persisted.
      }
      return !c
    })
  }

  function chooseTheme(t: Theme) {
    setTheme(t)
    applyTheme(t)
  }

  const groups: { label: string | null; items: NavItem[] }[] = isCustomer
    ? [
        {
          label: null,
          items: [
            { to: '/', label: 'My accounts', icon: Icons.home, permission: 'dashboard.view', end: true },
            { to: '/support', label: 'Help & support', icon: Icons.chat, permission: 'support.view' },
          ],
        },
      ]
    : [
        {
          label: null,
          items: [{ to: '/', label: 'Home', icon: Icons.home, permission: 'dashboard.view', end: true }],
        },
        {
          label: 'Accounts',
          items: [
            { to: '/customers', label: 'Customers', icon: Icons.users, permission: 'customers.view' },
            {
              to: '/loans',
              label: 'Loans',
              icon: Icons.loan,
              permission: 'loans.view',
              count: (pending.loan_approvals ?? 0) + (pending.disbursements ?? 0),
            },
            { to: '/deposits', label: 'Savings', icon: Icons.piggy, permission: 'deposits.view' },
            {
              to: '/payouts',
              label: 'Savings payouts',
              icon: Icons.payout,
              permission: 'deposits.view',
              when: depositsOn,
              count: (pending.payout_approvals ?? 0) + (pending.payouts_to_pay ?? 0),
            },
            {
              to: '/applications',
              label: 'Applications',
              icon: Icons.file,
              permission: 'applications.review',
              count: (pending.registrations ?? 0) + (pending.applications ?? 0),
            },
            { to: '/support', label: 'Requests', icon: Icons.chat, permission: 'support.manage' },
          ],
        },
        {
          label: 'Collections',
          items: [
            { to: '/collections', label: 'Due today', icon: Icons.route, permission: 'collections.view' },
            { to: '/payments', label: 'Payments', icon: Icons.payment, permission: 'payments.view', count: pending.payment_confirmations ?? 0 },
            {
              to: '/cash',
              label: 'Cash handover',
              icon: Icons.cash,
              permission: can('cash.verify') ? 'cash.verify' : 'cash.handover',
              count: (pending.cash_verifications ?? 0) + (pending.cash_discrepancies ?? 0),
            },
          ],
        },
        {
          label: 'Finance',
          items: [
            { to: '/approvals', label: 'Approvals', icon: Icons.check, permission: 'approvals.view', count: pending.approvals ?? 0 },
            { to: '/accounting', label: 'Accounting', icon: Icons.wallet, permission: 'accounting.view' },
            { to: '/reports', label: 'Reports', icon: Icons.chart, permission: 'reports.view' },
          ],
        },
        {
          label: 'Setup',
          items: [
            { to: '/migrate', label: 'Bring in old accounts', icon: Icons.download, permission: 'migration.import' },
            { to: '/products', label: 'Loan & savings plans', icon: Icons.sliders, permission: 'loan_products.view', when: can('loan_products.manage') || can('reports.view') },
            { to: '/users', label: 'Staff', icon: Icons.shield, permission: 'users.manage' },
            { to: '/settings', label: 'Settings', icon: Icons.settings, permission: 'settings.manage' },
            { to: '/audit', label: 'Audit log', icon: Icons.history, permission: 'audit.view' },
          ],
        },
      ]

  const initials = (user?.name ?? '?')
    .split(' ')
    .map((p) => p[0])
    .slice(0, 2)
    .join('')
    .toUpperCase()

  return (
    <div className={`shell${collapsed ? ' shell--collapsed' : ''}${mobileOpen ? ' shell--mobile-open' : ''}`}>
      {mobileOpen ? <div className="scrim" onClick={() => setMobileOpen(false)} /> : null}
      <aside className="sidebar">
        <div className="sidebar__top">
          <div className="brand">
            <span className="brand__mark">K</span>
            <span className="brand__name">{user?.settings.company_name || 'KBSAVE'}</span>
          </div>
          <span className="spacer" />
          {!collapsed ? <NotificationBell placement="right" /> : null}
          <button type="button" className="icon-btn" onClick={toggleCollapsed} aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}>
            <Icons.panel />
          </button>
        </div>

        <nav className="nav">
          {groups.map((group, gi) => {
            const items = group.items.filter((i) => can(i.permission) && i.when !== false)
            if (items.length === 0) return null
            return (
              <div key={gi} className="nav__group">
                {group.label ? <div className="nav__label">{group.label}</div> : null}
                {items.map((item) => (
                  <NavLink key={item.to} to={item.to} end={item.end} className="nav__link" title={collapsed ? item.label : undefined}>
                    <item.icon />
                    <span className="nav__text">{item.label}</span>
                    {item.count ? <span className="nav__count">{item.count}</span> : null}
                  </NavLink>
                ))}
              </div>
            )
          })}
        </nav>

        <div style={{ position: 'relative' }} ref={menu === 'user' ? menuRef : undefined}>
          {menu === 'user' ? (
            <div className="popover" style={{ bottom: 52, left: 0, right: collapsed ? 'auto' : 0 }}>
              <div className="small muted" style={{ padding: '6px 10px' }}>
                {user?.email}
              </div>
              <div className="popover__sep" />
              <div className="small muted" style={{ padding: '4px 10px' }}>
                Appearance
              </div>
              {(['system', 'light', 'dark'] as Theme[]).map((t) => (
                <button key={t} type="button" className="popover__item" onClick={() => chooseTheme(t)}>
                  {t === 'dark' ? <Icons.moon /> : <Icons.sun />}
                  {titleCase(t)}
                  {theme === t ? <span style={{ marginLeft: 'auto' }}><Icons.check /></span> : null}
                </button>
              ))}
              <div className="popover__sep" />
              <div className="small muted" style={{ padding: '4px 10px' }}>
                Language
              </div>
              <div style={{ padding: '2px 8px 6px' }}>
                <LanguageSwitch block />
              </div>
              <div className="popover__sep" />
              <button
                type="button"
                className="popover__item"
                onClick={() => {
                  setMenu(null)
                  setChangingPassword(true)
                }}
              >
                <Icons.key /> Change password
              </button>
              <button
                type="button"
                className="popover__item"
                onClick={() => {
                  setMenu(null)
                  setMfaOpen(true)
                }}
              >
                <Icons.shield /> Two-factor sign-in {user?.mfa_enabled ? <span className="badge badge--success" style={{ marginLeft: 'auto' }}>On</span> : null}
              </button>
              <button type="button" className="popover__item" onClick={() => void logout()}>
                <Icons.logout /> Sign out
              </button>
            </div>
          ) : null}
          <button type="button" className="user-chip" onClick={() => setMenu(menu === 'user' ? null : 'user')} aria-expanded={menu === 'user'}>
            <span className="avatar">{initials}</span>
            <span className="user-chip__meta">
              <div className="user-chip__name">{user?.name}</div>
              <div className="user-chip__role">{user?.roles.map(titleCase).join(', ')}</div>
            </span>
          </button>
        </div>
      </aside>

      <div className="main">
        <div className="topbar">
          <button type="button" className="icon-btn" onClick={() => setMobileOpen(true)} aria-label="Open menu">
            <Icons.menu />
          </button>
          <div className="brand">
            <span className="brand__mark">K</span>
            <span>{user?.settings.company_name || 'KBSAVE'}</span>
          </div>
          <span className="spacer" />
          <NotificationBell />
        </div>
        <OfflineStatus />
        <main className="content">
          <Outlet />
        </main>
      </div>

      {changingPassword ? <ChangePasswordModal onClose={() => setChangingPassword(false)} /> : null}
      {mfaOpen ? <MfaModal onClose={() => setMfaOpen(false)} /> : null}
    </div>
  )
}

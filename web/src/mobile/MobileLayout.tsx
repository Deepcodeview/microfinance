import { useEffect, useRef, type ReactNode } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth'
import { Icons } from '../components/icons'
import { NotificationBell } from '../components/NotificationBell'
import { OfflineStatus } from '../components/OfflineStatus'
import { UpdateBanner } from '../components/UpdateBanner'
import { APP_MODE } from '../config'

interface Tab {
  to: string
  label: string
  icon: () => ReactNode
  end?: boolean
}

const AGENT_TABS: Tab[] = [
  { to: '/', label: 'Today', icon: Icons.route, end: true },
  { to: '/customers', label: 'Customers', icon: Icons.users },
  { to: '/payments', label: 'Payments', icon: Icons.payment },
  { to: '/cash', label: 'Cash', icon: Icons.cash },
  { to: '/profile', label: 'Me', icon: Icons.shield },
]

const CUSTOMER_TABS: Tab[] = [
  { to: '/', label: 'Home', icon: Icons.home, end: true },
  { to: '/plans', label: 'Plans', icon: Icons.wallet },
  { to: '/support', label: 'Help', icon: Icons.chat },
  { to: '/notifications', label: 'Alerts', icon: Icons.bell },
  { to: '/profile', label: 'Me', icon: Icons.shield },
]

/** Native-feeling shell for the Android apps: app bar, content, bottom tab bar (safe-area aware). */
export function MobileLayout() {
  const { user } = useAuth()
  const location = useLocation()
  const tabs = APP_MODE === 'agent' ? AGENT_TABS : CUSTOMER_TABS
  const active = tabs.find((t) => (t.end ? location.pathname === t.to : location.pathname.startsWith(t.to)))
  const mainRef = useRef<HTMLElement>(null)

  // Phones: every data table renders as a stack of cards. Each cell gets its column name as a label
  // (data-label), so all columns stay readable without sideways scrolling — on every screen at once.
  useEffect(() => {
    const root = mainRef.current
    if (!root) return
    const label = () => {
      root.querySelectorAll<HTMLTableElement>('table.table').forEach((table) => {
        const heads = [...table.querySelectorAll('thead th')].map((th) => th.textContent?.trim() ?? '')
        table.querySelectorAll('tbody tr').forEach((tr) => {
          ;[...tr.children].forEach((td, i) => {
            // A full-width cell (e.g. "No payments yet.") is not under one column.
            const text = (td as HTMLTableCellElement).colSpan > 1 ? '' : heads[i] ?? ''
            if ((td as HTMLElement).dataset.label !== text) (td as HTMLElement).dataset.label = text
          })
        })
      })
    }
    label()
    const observer = new MutationObserver(label)
    observer.observe(root, { childList: true, subtree: true })
    return () => observer.disconnect()
  }, [])

  useEffect(() => {
    window.scrollTo(0, 0)
  }, [location.pathname])

  return (
    <div className="m-shell">
      <header className="m-bar">
        <span className="brand__mark">K</span>
        <div className="m-bar__title">
          <div className="m-bar__app">{APP_MODE === 'agent' ? 'KBSAVE Agent' : user?.settings.company_name || 'KBSAVE'}</div>
          <div className="m-bar__sub">{active?.label ?? ''}</div>
        </div>
        {APP_MODE === 'agent' ? <NotificationBell /> : null}
      </header>
      <UpdateBanner />
      <OfflineStatus />
      <main className="m-content" ref={mainRef}>
        <Outlet />
      </main>
      <nav className="m-tabs" aria-label="Main">
        {tabs.map((t) => (
          <NavLink key={t.to} to={t.to} end={t.end} className="m-tab">
            <t.icon />
            <span>{t.label}</span>
          </NavLink>
        ))}
      </nav>
    </div>
  )
}

/** Blocks people from using the wrong app (e.g. a customer signing into the Agent app). */
export function AppRoleGate({ children }: { children: ReactNode }) {
  const { user, can, logout } = useAuth()
  const allowed = APP_MODE === 'agent' ? can('collections.view') && can('payments.create') : user?.customer_scope === 'own'
  if (allowed) return children
  return (
    <div className="login">
      <div className="login__card">
        <h1 className="login__title">Wrong app</h1>
        <p className="login__subtitle">
          {APP_MODE === 'agent' ? 'This app is for collection agents. Customers should use the KBSAVE app; office staff use the web portal.' : 'This app is for customers. Staff should use the Agent app or the web portal.'}
        </p>
        <button type="button" className="btn btn--dark btn--block" onClick={() => void logout()}>Sign out</button>
      </div>
    </div>
  )
}

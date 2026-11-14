import { Suspense, lazy, type ComponentType, type ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './auth'
import { Layout } from './components/Layout'
import { MfaRequiredScreen } from './components/MfaSetup'
import { APP_MODE, IS_APP } from './config'
import { AppRoleGate, MobileLayout } from './mobile/MobileLayout'
import { Loading } from './components/ui'
import { Login } from './pages/Login'

// Screens load on demand: each app build only downloads the screens it uses (faster start-up).
const NotificationsPage = lazy(() => import('./mobile/Notifications').then((m) => ({ default: m.NotificationsPage })))
const Profile = lazy(() => import('./mobile/Profile').then((m) => ({ default: m.Profile })))
const MyAccounts = lazy(() => import('./pages/MyAccounts').then((m) => ({ default: m.MyAccounts })))
const CustomerShell = lazy(() => import('./mobile/CustomerApp').then((m) => ({ default: m.CustomerShell })))
const SignedIn = lazy(() => import('./mobile/CustomerApp').then((m) => ({ default: m.SignedIn })))
const Welcome = lazy(() => import('./mobile/CustomerApp').then((m) => ({ default: m.Welcome })))
const Plans = lazy(() => import('./mobile/CustomerApp').then((m) => ({ default: m.Plans })))
const PlanDetail = lazy(() => import('./mobile/CustomerApp').then((m) => ({ default: m.PlanDetail })))
const Register = lazy(() => import('./mobile/CustomerApp').then((m) => ({ default: m.Register })))
const Apply = lazy(() => import('./mobile/CustomerApp').then((m) => ({ default: m.Apply })))
const MyApplications = lazy(() => import('./mobile/CustomerApp').then((m) => ({ default: m.MyApplications })))
const Applications = lazy(() => import('./pages/Applications').then((m) => ({ default: m.Applications })))
const Migration = lazy(() => import('./pages/Migration').then((m) => ({ default: m.Migration })))
const CustomerMap = lazy(() => import('./pages/CustomerMap').then((m) => ({ default: m.CustomerMap })))
const LoanCertificate = lazy(() => import('./pages/LoanCertificate').then((m) => ({ default: m.LoanCertificate })))
const Accounting = lazy(() => import('./pages/Accounting').then((m) => ({ default: m.Accounting })))
const Approvals = lazy(() => import('./pages/Approvals').then((m) => ({ default: m.Approvals })))
const AuditLog = lazy(() => import('./pages/AuditLog').then((m) => ({ default: m.AuditLog })))
const CashHandover = lazy(() => import('./pages/CashHandover').then((m) => ({ default: m.CashHandover })))
const Collections = lazy(() => import('./pages/Collections').then((m) => ({ default: m.Collections })))
const CustomerDetail = lazy(() => import('./pages/CustomerDetail').then((m) => ({ default: m.CustomerDetail })))
const CustomerForm = lazy(() => import('./pages/CustomerForm').then((m) => ({ default: m.CustomerForm })))
const Customers = lazy(() => import('./pages/Customers').then((m) => ({ default: m.Customers })))
const Dashboard = lazy(() => import('./pages/Dashboard').then((m) => ({ default: m.Dashboard })))
const DepositProductEditor = lazy(() => import('./pages/DepositProducts').then((m) => ({ default: m.DepositProductEditor })))
const DepositProducts = lazy(() => import('./pages/DepositProducts').then((m) => ({ default: m.DepositProducts })))
const DepositDetail = lazy(() => import('./pages/Deposits').then((m) => ({ default: m.DepositDetail })))
const DepositNew = lazy(() => import('./pages/Deposits').then((m) => ({ default: m.DepositNew })))
const Deposits = lazy(() => import('./pages/Deposits').then((m) => ({ default: m.Deposits })))
const Payouts = lazy(() => import('./pages/Deposits').then((m) => ({ default: m.Payouts })))
const LoanDetail = lazy(() => import('./pages/LoanDetail').then((m) => ({ default: m.LoanDetail })))
const LoanNew = lazy(() => import('./pages/LoanNew').then((m) => ({ default: m.LoanNew })))
const LoanProductEditor = lazy(() => import('./pages/LoanProductEditor').then((m) => ({ default: m.LoanProductEditor })))
const LoanProducts = lazy(() => import('./pages/LoanProducts').then((m) => ({ default: m.LoanProducts })))
const Loans = lazy(() => import('./pages/Loans').then((m) => ({ default: m.Loans })))
const PaymentDetail = lazy(() => import('./pages/PaymentDetail').then((m) => ({ default: m.PaymentDetail })))
const Payments = lazy(() => import('./pages/Payments').then((m) => ({ default: m.Payments })))
const Receipt = lazy(() => import('./pages/Receipt').then((m) => ({ default: m.Receipt })))
const Reports = lazy(() => import('./pages/Reports').then((m) => ({ default: m.Reports })))
const Settings = lazy(() => import('./pages/Settings').then((m) => ({ default: m.Settings })))
const Statement = lazy(() => import('./pages/Statement').then((m) => ({ default: m.Statement })))
const SupportDetail = lazy(() => import('./pages/Support').then((m) => ({ default: m.SupportDetail })))
const SupportList = lazy(() => import('./pages/Support').then((m) => ({ default: m.SupportList })))
const Users = lazy(() => import('./pages/Users').then((m) => ({ default: m.Users })))
const VerifyReceipt = lazy(() => import('./pages/VerifyReceipt').then((m) => ({ default: m.VerifyReceipt })))

function RequireAuth({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) return <Loading />
  if (!user) return <Navigate to="/login" replace />
  if (user.mfa_setup_required) return <MfaRequiredScreen />
  return children
}

function Guard({ permission, children }: { permission: string; children: ReactNode }) {
  const { can } = useAuth()
  if (!can(permission)) {
    return <div className="banner banner--error">You do not have access to this page.</div>
  }
  return children
}

const LoanStatement = () => <Statement kind="loan" />
const DepositStatement = () => <Statement kind="deposit" />

const routes: [string, string, ComponentType][] = [
  ['customers', 'customers.view', Customers],
  ['customers/new', 'customers.create', CustomerForm],
  ['customers/:id', 'customers.view', CustomerDetail],
  ['customers/:id/edit', 'customers.update', CustomerForm],
  ['loans', 'loans.view', Loans],
  ['loans/new', 'loans.apply', LoanNew],
  ['loans/:id', 'loans.view', LoanDetail],
  ['payments', 'payments.view', Payments],
  ['payments/:id', 'payments.view', PaymentDetail],
  ['payments/:id/receipt', 'payments.view', Receipt],
  ['collections', 'collections.view', Collections],
  ['map', 'collections.view', CustomerMap],
  ['cash', 'cash.handover', CashHandover],
  ['deposits', 'deposits.view', Deposits],
  ['deposits/new', 'deposits.open', DepositNew],
  ['deposits/:id', 'deposits.view', DepositDetail],
  ['payouts', 'deposits.view', Payouts],
  ['reports', 'reports.view', Reports],
  ['loan-products', 'loan_products.view', LoanProducts],
  ['loan-products/new', 'loan_products.manage', LoanProductEditor],
  ['loan-products/:id', 'loan_products.view', LoanProductEditor],
  ['deposit-products', 'deposits.view', DepositProducts],
  ['deposit-products/new', 'deposit_products.manage', DepositProductEditor],
  ['deposit-products/:id', 'deposits.view', DepositProductEditor],
  ['users', 'users.manage', Users],
  ['migrate', 'migration.import', Migration],
  ['approvals', 'approvals.view', Approvals],
  ['applications', 'applications.review', Applications],
  ['accounting', 'accounting.view', Accounting],
  ['support', 'support.view', SupportList],
  ['support/:id', 'support.view', SupportDetail],
  ['loans/:id/statement', 'loans.view', LoanStatement],
  ['loans/:id/certificate', 'loans.view', LoanCertificate],
  ['deposits/:id/statement', 'deposits.view', DepositStatement],
  ['audit', 'audit.view', AuditLog],
  ['settings', 'settings.manage', Settings],
]

/** Screens available in the Android apps (subset of the web routes, same permission guards). */
const AGENT_ROUTES = new Set(['customers', 'customers/new', 'customers/:id', 'loans/new', 'deposits/new', 'loans/:id', 'loans/:id/statement', 'loans/:id/certificate', 'deposits/:id', 'deposits/:id/statement',
  'payments', 'payments/:id', 'payments/:id/receipt', 'cash', 'map', 'support', 'support/:id', 'applications'])
const CUSTOMER_ROUTES = new Set(['loans/:id', 'loans/:id/statement', 'loans/:id/certificate', 'deposits/:id', 'deposits/:id/statement', 'payments/:id/receipt', 'support', 'support/:id'])

/** Customer app: plans and registration are open to everyone; accounts and applications need sign-in. */
function CustomerHome() {
  const { user } = useAuth()
  return user ? <MyAccounts /> : <Welcome />
}

function CustomerAppRoutes() {
  return (
    <Route element={<CustomerShell />}>
      <Route index element={<CustomerHome />} />
      <Route path="plans" element={<Plans />} />
      <Route path="plans/:kind/:id" element={<PlanDetail />} />
      <Route path="register" element={<Register />} />
      <Route element={<SignedIn />}>
        <Route path="apply/:kind" element={<Apply />} />
        <Route path="applications" element={<MyApplications />} />
        <Route path="profile" element={<Profile />} />
        <Route path="notifications" element={<NotificationsPage />} />
        {routes.filter(([path]) => CUSTOMER_ROUTES.has(path)).map(([path, permission, Page]) => (
          <Route key={path} path={path} element={<Guard permission={permission}><Page /></Guard>} />
        ))}
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Route>
  )
}

function AppShellRoutes() {
  const allowed = APP_MODE === 'agent' ? AGENT_ROUTES : CUSTOMER_ROUTES
  return (
    <Route
      element={
        <RequireAuth>
          <AppRoleGate>
            <MobileLayout />
          </AppRoleGate>
        </RequireAuth>
      }
    >
      <Route index element={APP_MODE === 'agent' ? <Collections /> : <MyAccounts />} />
      <Route path="profile" element={<Profile />} />
      <Route path="notifications" element={<NotificationsPage />} />
      {routes.filter(([path]) => allowed.has(path)).map(([path, permission, Page]) => (
        <Route key={path} path={path} element={<Guard permission={permission}><Page /></Guard>} />
      ))}
      <Route path="*" element={<Navigate to="/" replace />} />
    </Route>
  )
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Suspense fallback={<Loading />}>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/verify/:code" element={<VerifyReceipt />} />
          {APP_MODE === 'customer' ? (
            CustomerAppRoutes()
          ) : IS_APP ? (
            AppShellRoutes()
          ) : (
            <Route
              element={
                <RequireAuth>
                  <Layout />
                </RequireAuth>
              }
            >
              <Route index element={<Dashboard />} />
              <Route path="products" element={<Navigate to="/loan-products" replace />} />
              {routes.map(([path, permission, Page]) => (
                <Route key={path} path={path} element={<Guard permission={permission}><Page /></Guard>} />
              ))}
              <Route path="*" element={<Navigate to="/" replace />} />
            </Route>
          )}
        </Routes>
        </Suspense>
      </BrowserRouter>
    </AuthProvider>
  )
}

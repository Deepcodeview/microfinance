import { locale } from '../i18n'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import { BarList, ColumnChart } from '../components/charts'
import { Icons } from '../components/icons'
import { ErrorBanner, Loading, StatusBadge } from '../components/ui'
import { dateTime, money, moneyShort, titleCase } from '../format'
import { useFetch } from '../hooks'
import { MyAccounts } from './MyAccounts'

interface DashboardData {
  business_date: string
  customers: { total: number; active: number; new_last_30_days: number }
  loans: { active_loans: number; principal_outstanding: string; overdue_amount: string; overdue_loans: number; due_today: string }
  collections_today: { total: string; by_method: { method: string; total: string; count: string }[] }
  pending: Record<string, number>
  deposits: { accounts: string; liability: string } | null
  my_cash_in_hand: string | null
  recent_payments: { id: string; payment_number: string; amount: string; method: string; status: string; collected_at: string; customer_name: string; account_number: string }[]
}

interface Insights {
  asset_classes: { asset_class: string; loans: string; principal: string }[]
  gross_npa: string
  gross_npa_percent: string
  provision_held: string
  trend: { date: string; cash: string; digital: string }[]
  aging: { bucket: string; loans: number; principal: string }[]
  loan_status: { status: string; count: number }[]
  products: { product: string; outstanding: string }[]
  branches: { branch: string; outstanding: string; active_loans: number; collected_month: string }[]
  top_collectors: { name: string; receipts: string; total: string }[]
  monthly: { month: string; disbursed: string; collected: string }[]
}

const TASKS: { key: string; label: string; to: string }[] = [
  { key: 'registrations', label: 'New customers who registered in the app', to: '/applications?tab=registrations' },
  { key: 'applications', label: 'Loan & savings applications from the app', to: '/applications?tab=requests' },
  { key: 'loan_approvals', label: 'Loan applications awaiting your decision', to: '/loans?status=pending_approval' },
  { key: 'disbursements', label: 'Approved loans ready to disburse', to: '/loans?status=approved' },
  { key: 'payment_confirmations', label: 'Digital payments to confirm', to: '/payments?status=pending' },
  { key: 'cash_verifications', label: 'Cash handovers to verify', to: '/cash?status=submitted' },
  { key: 'cash_discrepancies', label: 'Cash discrepancies to resolve', to: '/cash?status=discrepancy' },
  { key: 'payout_approvals', label: 'Deposit payouts awaiting approval', to: '/payouts?status=requested' },
  { key: 'payouts_to_pay', label: 'Approved payouts to pay', to: '/payouts?status=approved' },
  { key: 'approvals', label: 'Requests awaiting your approval', to: '/approvals' },
]

const short = (n: number) => moneyShort(String(n))
const ASSET_LABEL: Record<string, string> = { standard: 'Standard', sma_0: 'SMA-0 (1–30)', sma_1: 'SMA-1 (31–60)', sma_2: 'SMA-2 (61–90)', substandard: 'NPA sub-standard', doubtful: 'NPA doubtful', loss: 'NPA loss' }
// Ordinal severity ramp (validated light/dark steps), worst = darkest.
const ASSET_COLOR: Record<string, string> = { standard: 'var(--seq-1)', sma_0: 'var(--seq-2)', sma_1: 'var(--seq-2)', sma_2: 'var(--seq-3)', substandard: 'var(--seq-4)', doubtful: 'var(--seq-5)', loss: 'var(--seq-5)' }
const shortDay = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString(locale(), { day: 'numeric', month: 'short' })
const monthLabel = (m: string) => new Date(`${m}-01T00:00:00`).toLocaleDateString(locale(), { month: 'short', year: '2-digit' })

function greeting(): string {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'
}

/** Last 30 business-calendar days, zero-filled so gaps read as "nothing collected", not as missing bars. */
function last30(trend: Insights['trend'], today: string) {
  const byDate = new Map(trend.map((t) => [t.date, t]))
  const days: string[] = []
  const end = new Date(`${today}T00:00:00`)
  for (let i = 29; i >= 0; i--) {
    const d = new Date(end)
    d.setDate(end.getDate() - i)
    days.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`)
  }
  return {
    categories: days.map(shortDay),
    rows: days.map((d) => ({ cash: Number(byDate.get(d)?.cash ?? 0), digital: Number(byDate.get(d)?.digital ?? 0) })),
  }
}

export function Dashboard() {
  const { user, can } = useAuth()
  const isCustomer = user?.customer_scope === 'own'
  const { data, error } = useFetch<DashboardData>(isCustomer ? null : '/dashboard')
  const { data: insights } = useFetch<Insights>(!isCustomer && can('reports.view') ? '/dashboard/insights' : null)

  if (isCustomer) return <MyAccounts />

  const firstName = user?.name.split(' ')[0] ?? ''
  const isAgent = user?.customer_scope === 'assigned'
  const tasks = data ? TASKS.filter((t) => (data.pending[t.key] ?? 0) > 0) : []

  return (
    <>
      <div className="greeting">
        <div className="greeting__title">
          <span className="greeting__spark">✦</span>
          {greeting()}, {firstName}
        </div>
        <div className="greeting__sub">
          {data ? new Date(`${data.business_date}T00:00:00`).toLocaleDateString(locale(), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) : ' '}
        </div>
      </div>

      <ErrorBanner error={error} />
      {!data && !error ? <Loading /> : null}

      {data ? (
        <>
          <div className="stats">
            {isAgent ? (
              <>
                <Kpi tone="terracotta" icon={<Icons.route />} label="Due today on your route" value={money(data.loans.due_today)} />
                <Kpi tone="green" icon={<Icons.payment />} label="Collected today" value={money(data.collections_today.total)} />
                <Kpi tone="amber" icon={<Icons.cash />} label="Cash in hand" value={money(data.my_cash_in_hand)} hint="Hand over at day end" />
                <Kpi tone="red" icon={<Icons.alert />} label="Overdue loans" value={String(data.loans.overdue_loans)} hint={money(data.loans.overdue_amount)} />
              </>
            ) : (
              <>
                <Kpi tone="terracotta" icon={<Icons.loan />} label="Loan book outstanding" value={moneyShort(data.loans.principal_outstanding)} hint={`${data.loans.active_loans} active loans`} />
                <Kpi tone="green" icon={<Icons.trend />} label="Collected today" value={moneyShort(data.collections_today.total)} hint={`Due today ${moneyShort(data.loans.due_today)}`} />
                <Kpi tone="red" icon={<Icons.alert />} label="Overdue" value={moneyShort(data.loans.overdue_amount)} hint={`${data.loans.overdue_loans} loans past due`} />
                <Kpi tone="blue" icon={<Icons.users />} label="Active customers" value={data.customers.active.toLocaleString('en-IN')} hint={`${data.customers.new_last_30_days} new in 30 days`} />
                {data.deposits ? <Kpi tone="violet" icon={<Icons.piggy />} label="Savings held" value={moneyShort(data.deposits.liability)} hint={`${data.deposits.accounts} accounts`} /> : null}
                {data.my_cash_in_hand !== null && Number(data.my_cash_in_hand) > 0 ? <Kpi tone="amber" icon={<Icons.cash />} label="Your cash in hand" value={money(data.my_cash_in_hand)} /> : null}
              </>
            )}
          </div>

          {tasks.length > 0 ? (
            <section className="card" style={{ marginBottom: 16 }}>
              <h2 className="card__title" style={{ marginBottom: 8 }}>Needs your attention</h2>
              <ul className="task-list task-list--row">
                {tasks.map((t) => (
                  <li key={t.key}>
                    <Link to={t.to} className="task">
                      <span className="task__count">{data.pending[t.key]}</span>
                      <span className="task__label">{t.label}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}

          {insights ? <ManagementCharts insights={insights} today={data.business_date} /> : null}

          <div className={isAgent ? 'grid grid--2-1' : undefined} style={{ marginTop: 16 }}>
            <section className="card card--flush">
              <div className="card__header">
                <h2 className="card__title">Recent payments</h2>
                <Link to="/payments" className="small">View all</Link>
              </div>
              {data.recent_payments.length === 0 ? (
                <p className="muted" style={{ padding: '0 20px 20px' }}>No payments yet.</p>
              ) : (
                <div className="table-wrap">
                  <table className="table">
                    <tbody>
                      {data.recent_payments.map((p) => (
                        <tr key={p.id}>
                          <td>
                            <Link to={`/payments/${p.id}`}>{p.customer_name}</Link>
                            <div className="small muted mono">{p.account_number}</div>
                          </td>
                          <td className="small muted">{titleCase(p.method)} · {dateTime(p.collected_at)}</td>
                          <td><StatusBadge status={p.status} /></td>
                          <td className="num">{money(p.amount)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>

            {isAgent ? (
              <section className="card">
                <h2 className="card__title" style={{ marginBottom: 8 }}>Field work</h2>
                <ul className="task-list">
                  <li><Link to="/collections" className="task"><span className="task__label">Open today's collection list</span></Link></li>
                  {can('cash.handover') ? <li><Link to="/cash" className="task"><span className="task__label">Submit day-end cash handover</span></Link></li> : null}
                </ul>
              </section>
            ) : null}
          </div>
        </>
      ) : null}
    </>
  )
}

function ManagementCharts({ insights, today }: { insights: Insights; today: string }) {
  const trend = last30(insights.trend, today)
  const totalMonth = insights.branches.reduce((s, b) => s + Number(b.collected_month), 0)
  return (
    <>
      <div className="grid grid--2-1">
        <section className="card">
          <div className="card__header">
            <h2 className="card__title">Collections, last 30 days</h2>
            <span className="small muted">This month {moneyShort(String(totalMonth))}</span>
          </div>
          <ColumnChart
            caption="Confirmed collections per day, cash and digital"
            categories={trend.categories}
            series={[{ key: 'cash', label: 'Cash', slot: 1 }, { key: 'digital', label: 'UPI', slot: 2 }]}
            rows={trend.rows}
            stacked
            format={short}
            labelEvery={5}
          />
        </section>
        <section className="card">
          <div className="card__header">
            <h2 className="card__title">Overdue aging</h2>
            <Link to="/reports" className="small">Portfolio report</Link>
          </div>
          <BarList
            caption="Outstanding principal by days past due"
            items={insights.aging.map((a) => ({ label: a.bucket, value: Number(a.principal), note: `${a.loans} loans` }))}
            colors={['var(--seq-1)', 'var(--seq-2)', 'var(--seq-3)', 'var(--seq-4)', 'var(--seq-5)']}
            format={short}
          />
        </section>
      </div>

      <div className="grid grid--4" style={{ marginTop: 16 }}>
        <section className="card">
          <h2 className="card__title" style={{ marginBottom: 12 }}>Disbursed vs collected</h2>
          <ColumnChart
            caption="Principal disbursed and payments collected per month, last 6 months"
            categories={insights.monthly.map((m) => monthLabel(m.month))}
            series={[{ key: 'disbursed', label: 'Disbursed', slot: 5 }, { key: 'collected', label: 'Collected', slot: 3 }]}
            rows={insights.monthly.map((m) => ({ disbursed: Number(m.disbursed), collected: Number(m.collected) }))}
            format={short}
            height={210}
            width={340}
          />
        </section>
        <section className="card">
          <div className="card__header">
            <h2 className="card__title">Asset quality</h2>
            <span className={`badge ${Number(insights.gross_npa_percent) > 5 ? 'badge--danger' : Number(insights.gross_npa_percent) > 0 ? 'badge--warning' : 'badge--success'}`}>Gross NPA {insights.gross_npa_percent}%</span>
          </div>
          <BarList
            caption="Principal outstanding by IRACP asset class"
            items={insights.asset_classes.map((a) => ({ label: ASSET_LABEL[a.asset_class] ?? a.asset_class, value: Number(a.principal), note: `${a.loans} loans` }))}
            colors={insights.asset_classes.map((a) => ASSET_COLOR[a.asset_class] ?? 'var(--seq-1)')}
            format={short}
          />
          <p className="small muted" style={{ marginTop: 10 }}>Provision held {moneyShort(insights.provision_held)} · NPA {moneyShort(insights.gross_npa)}</p>
        </section>
        <section className="card">
          <h2 className="card__title" style={{ marginBottom: 12 }}>Loan book by product</h2>
          {insights.products.length === 0 ? <p className="muted">No active loans.</p> : (
            <BarList caption="Outstanding principal by product" items={insights.products.map((p) => ({ label: p.product, value: Number(p.outstanding) }))} format={short} slot={1} />
          )}
          <h3 className="card__subtitle">Loans by status</h3>
          <BarList caption="Number of loans by status" items={insights.loan_status.map((s) => ({ label: titleCase(s.status), value: s.count }))} format={(n) => String(n)} slot={1} />
        </section>
        <section className="card">
          <h2 className="card__title" style={{ marginBottom: 8 }}>Top collectors this month</h2>
          {insights.top_collectors.length === 0 ? <p className="muted">No collections yet this month.</p> : insights.top_collectors.map((c, i) => (
            <div key={c.name} className="leader">
              <span className={`leader__rank${i === 0 ? ' leader__rank--1' : ''}`}>{i + 1}</span>
              <span style={{ flex: 1 }}>{c.name}<div className="small muted">{c.receipts} receipts</div></span>
              <strong className="tabular">{moneyShort(c.total)}</strong>
            </div>
          ))}
        </section>
      </div>

      {insights.branches.length > 0 ? (
        <section className="card card--flush" style={{ marginTop: 16 }}>
          <div className="card__header"><h2 className="card__title">Branches</h2></div>
          <div className="table-wrap">
            <table className="table">
              <thead><tr><th>Branch</th><th className="num">Active loans</th><th className="num">Outstanding</th><th className="num">Collected this month</th></tr></thead>
              <tbody>
                {insights.branches.map((b) => (
                  <tr key={b.branch}>
                    <td>{b.branch}</td>
                    <td className="num">{b.active_loans}</td>
                    <td className="num">{money(b.outstanding)}</td>
                    <td className="num">{money(b.collected_month)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}
    </>
  )
}

function Kpi({ tone, icon, label, value, hint }: { tone: string; icon: ReactNode; label: string; value: string; hint?: string }) {
  return (
    <div className={`kpi kpi--${tone}`}>
      <div className="kpi__icon">{icon}</div>
      <div className="kpi__label">{label}</div>
      <div className="kpi__value">{value}</div>
      {hint ? <div className="kpi__hint">{hint}</div> : null}
    </div>
  )
}

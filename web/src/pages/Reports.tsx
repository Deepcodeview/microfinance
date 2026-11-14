import { useEffect, useState } from 'react'
import { api } from '../api'
import { useAuth } from '../auth'
import { Icons } from '../components/icons'
import { ErrorBanner, Loading, PageHeader, Tabs } from '../components/ui'
import { money, titleCase } from '../format'

type ReportId = 'demand-collection' | 'missed-collections' | 'daily-collection' | 'loan-portfolio' | 'agent-cash' | 'deposit-liability' | 'payouts' | 'trial-balance' | 'journal'

const REPORTS: { id: ReportId; label: string; permission: string; dates: 'date' | 'range' | 'as_of' | null; deposits?: boolean }[] = [
  { id: 'demand-collection', label: 'Demand vs collection', permission: 'reports.view', dates: 'date' },
  { id: 'missed-collections', label: 'Missed collections', permission: 'reports.view', dates: 'date' },
  { id: 'daily-collection', label: 'Daily collection', permission: 'reports.view', dates: 'date' },
  { id: 'loan-portfolio', label: 'Loan portfolio & aging', permission: 'reports.view', dates: null },
  { id: 'agent-cash', label: 'Agent cash & floats', permission: 'reports.view', dates: 'range' },
  { id: 'deposit-liability', label: 'Deposit liability', permission: 'reports.view', dates: null, deposits: true },
  { id: 'payouts', label: 'Payouts', permission: 'reports.view', dates: null, deposits: true },
  { id: 'trial-balance', label: 'Trial balance', permission: 'accounting.view', dates: 'as_of' },
  { id: 'journal', label: 'Journal', permission: 'accounting.view', dates: 'range' },
]

const MONEY_COLUMNS = /(amount|cash|upi|bank|total|pending|principal|interest|liability|maturing|collected|handed|verified|discrepancy|hand_now|debit|credit|penalt|outstanding|expected|confirmed$|overdue|float_received|paid_out)/

interface ReportData {
  rows: Record<string, string | number | null>[]
  summary: Record<string, string | number | boolean>
}

export function Reports() {
  const { user, can } = useAuth()
  const available = REPORTS.filter((r) => can(r.permission) && (!r.deposits || user?.settings.deposits_enabled))
  const [report, setReport] = useState<ReportId>(available[0]?.id ?? 'daily-collection')
  const today = user?.settings.business_date ?? new Date().toISOString().slice(0, 10)
  const [from, setFrom] = useState(today)
  const [to, setTo] = useState(today)
  const [data, setData] = useState<ReportData | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [downloading, setDownloading] = useState(false)

  const config = REPORTS.find((r) => r.id === report)!
  const query = config.dates === 'date' ? `date=${from}` : config.dates === 'as_of' ? `as_of=${from}` : config.dates === 'range' ? `date_from=${from}&date_to=${to}` : ''

  useEffect(() => {
    let cancelled = false
    setData(null)
    setError(null)
    api
      .get<ReportData>(`/reports/${report}?${query}`)
      .then((d) => !cancelled && setData(d))
      .catch((e) => !cancelled && setError(e))
    return () => {
      cancelled = true
    }
  }, [report, query])

  async function exportCsv() {
    setDownloading(true)
    try {
      await api.download(`/reports/${report}?${query}&format=csv`, `${report}-${from}.csv`)
    } catch (e) {
      setError(e)
    } finally {
      setDownloading(false)
    }
  }

  const columns = data && data.rows.length > 0 ? Object.keys(data.rows[0]) : []

  return (
    <>
      <PageHeader
        title="Reports"
        subtitle="Figures are computed from posted transactions and the ledger. Exports are logged."
        actions={
          <button type="button" className="btn" onClick={exportCsv} disabled={downloading || !data || data.rows.length === 0}>
            <Icons.download /> Export CSV
          </button>
        }
      />
      <Tabs tabs={available.map((r) => ({ id: r.id, label: r.label }))} value={report} onChange={setReport} />

      {config.dates ? (
        <div className="toolbar">
          <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="Date" />
          {config.dates === 'range' ? <input type="date" value={to} onChange={(e) => setTo(e.target.value)} aria-label="To date" /> : null}
        </div>
      ) : null}

      <ErrorBanner error={error} />
      {!data && !error ? <Loading /> : null}
      {data ? (
        <>
          {Object.keys(data.summary).length > 0 ? (
            <div className="stats">
              {Object.entries(data.summary).map(([key, value]) => (
                <div key={key} className={`stat${key === 'balanced' ? (value ? ' stat--accent' : '') : ''}`}>
                  <div className="stat__label">{titleCase(key)}</div>
                  <div className="stat__value" style={{ fontSize: 22 }}>
                    {typeof value === 'boolean' ? (value ? 'Yes' : 'No') : key.endsWith('percent') ? `${value}%` : MONEY_COLUMNS.test(key) ? money(String(value)) : String(value)}
                  </div>
                </div>
              ))}
            </div>
          ) : null}
          <div className="table-wrap">
            <table className="table table--compact">
              <thead>
                <tr>
                  {columns.map((c) => (
                    <th key={c} className={MONEY_COLUMNS.test(c) ? 'num' : undefined}>
                      {titleCase(c)}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.rows.length === 0 ? (
                  <tr>
                    <td className="table__empty" colSpan={Math.max(1, columns.length)}>
                      No data for this period.
                    </td>
                  </tr>
                ) : (
                  data.rows.map((row, i) => (
                    <tr key={i}>
                      {columns.map((c) => (
                        <td key={c} className={MONEY_COLUMNS.test(c) ? 'num' : undefined}>
                          {MONEY_COLUMNS.test(c) && row[c] !== null && !Number.isNaN(Number(row[c])) ? money(String(row[c])) : String(row[c] ?? '—')}
                        </td>
                      ))}
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </>
      ) : null}
    </>
  )
}
